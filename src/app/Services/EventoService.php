<?php

namespace App\Services;

use App\Models\Evento;
use App\Models\EventoParticipante;
use App\Models\MovimientoCuenta;
use App\Models\MovimientoEfectivo;
use App\Models\MovimientoTarjeta;
use App\Models\User;
use App\Support\FotoCuenta;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Eventos: dividir la cuenta de una comida o celebración.
 *
 *  - Como una tabla: cada participante pidió cierta cantidad de cada producto (cantidad × precio). La propina es un
 *    porcentaje del consumo de cada uno o un monto fijo dividido entre todos. Lo de los festejados (consumo + propina)
 *    se reparte en partes iguales entre los que no lo son. Todo en centavos: la suma de las partes es exactamente el total.
 *  - Si pagó un miembro del hogar: el pago es un solo movimiento (efectivo, cuenta o tarjeta, o solo anotado) por el
 *    total, y la parte de cada contacto es una deuda ("Nos deben") que se cobra desde el evento (el ingreso se registra
 *    solo). Si pagó otra persona: solo se marca quién ya le pagó; un miembro del hogar puede registrar su egreso.
 *  - Los movimientos y deudas de un evento se modifican desde el evento (App\Support\MovimientoVinculado).
 */
class EventoService
{
    /** Disco privado de las fotos de la cuenta. */
    public const DISCO = 'local';

    public function __construct(
        private readonly MovimientoEfectivoService $efectivo,
        private readonly CuentaService $cuentas,
        private readonly TarjetaService $tarjetas,
        private readonly ContactoService $contactos,
        private readonly DeudorService $deudores,
    ) {}

    /**
     * Lo que le toca a cada participante.
     *
     * @return array{subtotal: float, propina: float, total: float, participantes: array<int, array{consumo: float, propina: float, festejo: float, total: float, detalle: list<array{descripcion: string, cantidad: int, precio: float, monto: float}>}>}
     */
    public function calcular(Evento $evento): array
    {
        $evento->loadMissing('participantes', 'consumos.participantes');
        $ids = $evento->participantes->pluck('id')->all();
        $consumo = array_fill_keys($ids, 0);
        $detalle = array_fill_keys($ids, []);
        $ids_ = array_combine($ids, $ids);

        foreach ($evento->consumos as $item) {
            $precio = (int) round((float) $item->precio * 100);
            foreach ($item->participantes as $p) {
                $cantidad = (int) $p->pivot->cantidad;
                if ($cantidad > 0 && isset($consumo[$p->id])) {
                    $consumo[$p->id] += $cantidad * $precio;
                    $detalle[$p->id][] = ['descripcion' => $item->descripcion, 'cantidad' => $cantidad, 'precio' => $precio / 100, 'monto' => $cantidad * $precio / 100];
                }
            }
        }

        // Propina: un porcentaje del consumo de cada uno, o un monto fijo dividido entre todos
        $subtotal = array_sum($consumo);
        $n = max(1, count($ids));
        [$propina, $exactoPropina] = match ($evento->propina_tipo) {
            'porcentaje' => [$p = (int) round($subtotal * (float) $evento->propina_valor / 100), array_map(fn ($c) => $subtotal > 0 ? $p * $c / $subtotal : 0, $consumo)],
            'monto' => [$p = (int) round((float) $evento->propina_valor * 100), array_fill_keys($ids, $p / $n)],
            default => [0, array_fill_keys($ids, 0)],
        };
        $propinas = $ids ? $this->repartir($propina, $exactoPropina) : [];

        // Lo de los festejados (consumo + propina) se reparte en partes iguales entre los demás; el centavo que sobra
        // va a quien menos recibió de más al redondear la propina
        $festejados = $evento->participantes->where('es_festejado', true)->pluck('id')->all();
        $pagan = array_values(array_diff($ids, $festejados));
        $festejo = array_sum(array_map(fn ($id) => $consumo[$id] + $propinas[$id], $festejados));
        $exceso = array_map(fn ($id) => $propinas[$id] - $exactoPropina[$id], $ids_);
        $aportes = $pagan ? $this->repartir($festejo, array_fill_keys($pagan, 1), $exceso) : [];

        $dolares = fn (int|float $centavos) => round($centavos / 100, 2);
        $participantes = [];
        foreach ($ids as $id) {
            $esFestejado = in_array($id, $festejados, true);
            $participantes[$id] = [
                'consumo' => $dolares($consumo[$id]),
                'propina' => $dolares($propinas[$id]),
                'festejo' => $dolares($aportes[$id] ?? 0),
                'total' => $esFestejado ? 0.0 : $dolares($consumo[$id] + $propinas[$id] + ($aportes[$id] ?? 0)),
                'detalle' => $detalle[$id],
            ];
        }

        return ['subtotal' => $dolares($subtotal), 'propina' => $dolares($propina), 'total' => $dolares($subtotal + $propina), 'participantes' => $participantes];
    }

    /**
     * El cálculo más el estado de cobro de cada uno: pagado, pendiente y "estado" (pagador, festejado, hogar,
     * pendiente, parcial o pagado). por_cobrar = lo que aún nos deben (si pagó el hogar).
     */
    public function resumen(Evento $evento): array
    {
        $evento->loadMissing('participantes.user', 'participantes.contacto', 'participantes.deuda', 'consumos.participantes');
        $calculo = $this->calcular($evento);
        $pagoElHogar = $evento->pagoElHogar();

        foreach ($evento->participantes as $p) {
            $total = $calculo['participantes'][$p->id]['total'];
            [$pagado, $estado] = match (true) {
                $p->es_pagador => [$total, 'pagador'],
                $p->es_festejado => [0.0, 'festejado'],
                $pagoElHogar && $p->user_id => [$total, 'hogar'],
                $pagoElHogar => [$p->deuda ? min($total, $p->deuda->pagado()) : 0.0, null],
                default => [$p->fecha_pago ? $total : 0.0, null],
            };
            $estado ??= $total <= 0 || $pagado >= $total ? 'pagado' : ($pagado > 0 ? 'parcial' : 'pendiente');
            $calculo['participantes'][$p->id] += ['pagado' => round($pagado, 2), 'pendiente' => round(max(0, $total - $pagado), 2), 'estado' => $estado];
        }
        $calculo['por_cobrar'] = $pagoElHogar ? round(collect($calculo['participantes'])->sum('pendiente'), 2) : 0.0;
        $calculo['pago_el_hogar'] = $pagoElHogar;

        return $calculo;
    }

    /**
     * Crea o actualiza un evento con sus participantes y consumos, y deja al día sus deudas y el movimiento del pago.
     *
     * @param  array{nombre: string, fecha: string, lugar?: ?string, comentario?: ?string, propina_tipo: string, propina_valor?: numeric,
     *     participantes: list<array{quien: string, es_festejado?: bool}>, pagador: int,
     *     consumos: list<array{descripcion: string, precio: numeric, cantidades: array<int, ?int>}>,
     *     origen?: ?string, categoria_personal_id?: ?int, confirmar?: bool, foto?: ?UploadedFile, quitar_foto?: bool}  $data
     *     quien: "miembro:{id}" | id de un contacto | "nuevo:Nombre"; pagador: posición en participantes;
     *     cantidades: cuántos pidió cada participante (por posición)
     */
    public function guardar(User $user, array $data, ?Evento $evento = null): Evento
    {
        return DB::transaction(function () use ($user, $data, $evento) {
            $base = [
                'nombre' => $data['nombre'], 'fecha' => $data['fecha'], 'lugar' => $data['lugar'] ?? null, 'comentario' => $data['comentario'] ?? null,
                'propina_tipo' => $data['propina_tipo'], 'propina_valor' => $data['propina_tipo'] === 'ninguna' ? 0 : ($data['propina_valor'] ?? 0),
            ];
            if ($evento) {
                $evento = Evento::whereKey($evento->id)->lockForUpdate()->firstOrFail();
                $evento->update($base);
            } else {
                $evento = $user->eventos()->create($base + ['user_id' => $user->id]);
            }

            $participantes = $this->guardarParticipantes($user, $evento, $data['participantes'], (int) $data['pagador']);
            $evento->consumos()->delete();
            foreach (array_values($data['consumos']) as $i => $c) {
                $item = $evento->consumos()->create(['descripcion' => $c['descripcion'], 'precio' => $c['precio'], 'orden' => $i]);
                $item->participantes()->sync(collect($c['cantidades'] ?? [])->filter(fn ($cantidad, $pos) => (int) $cantidad > 0 && isset($participantes[(int) $pos]))
                    ->mapWithKeys(fn ($cantidad, $pos) => [$participantes[(int) $pos]->id => ['cantidad' => (int) $cantidad]])->all());
            }

            $evento->unsetRelation('participantes')->unsetRelation('consumos');
            $calculo = $this->calcular($evento);
            $this->sincronizarDeudas($evento, $calculo);
            $this->sincronizarPago($user, $evento, $data, $calculo['total']);
            if (! empty($data['quitar_foto'])) {
                $this->quitarFoto($evento);
            }
            if (($data['foto'] ?? null) instanceof UploadedFile) { // al final: si algo falla antes, no queda una foto suelta
                $this->guardarFoto($evento, $data['foto']);
            }

            return $evento->refresh();
        });
    }

    /** Elimina el evento, sus deudas y sus movimientos. Si ya se cobró algo, primero hay que eliminar esos cobros. */
    public function eliminar(Evento $evento): void
    {
        DB::transaction(function () use ($evento) {
            $evento->load('participantes.deuda');
            foreach ($evento->participantes as $p) {
                if ($p->deuda && $p->deuda->pagos()->exists()) {
                    throw ValidationException::withMessages(['evento' => "A {$p->nombre()} ya le cobraste: elimina primero ese cobro."]);
                }
            }
            foreach ($evento->participantes as $p) {
                $this->quitarMovimiento($p);
                if ($deuda = $p->deuda) {
                    $p->update(['deuda_id' => null]);
                    $deuda->delete();
                }
            }
            $this->quitarMovimiento($evento);
            $this->quitarFoto($evento);
            $evento->delete();
        });
    }

    /** Guarda (o reemplaza) la foto de la cuenta, enderezada y reducida. */
    public function guardarFoto(Evento $evento, UploadedFile $archivo): void
    {
        try {
            $jpeg = FotoCuenta::normalizar($archivo);
        } catch (\InvalidArgumentException) {
            throw ValidationException::withMessages(['foto' => 'No se pudo leer la foto de la cuenta. Usa una imagen JPG o PNG.']);
        }
        $this->quitarFoto($evento);
        $ruta = "eventos/{$evento->hogar_id}/".Str::uuid().'.jpg';
        Storage::disk(self::DISCO)->put($ruta, $jpeg);
        $evento->update(['foto' => $ruta]);
    }

    public function quitarFoto(Evento $evento): void
    {
        if ($evento->foto && Storage::disk(self::DISCO)->exists($evento->foto)) {
            Storage::disk(self::DISCO)->delete($evento->foto);
        }
        if ($evento->foto) {
            $evento->update(['foto' => null]);
        }
    }

    /**
     * Cobrar a un contacto su parte (pagó el hogar): un pago de su deuda que entra al efectivo o a una cuenta.
     *
     * @param  array{monto: numeric, fecha: string, destino: string, comentario?: ?string, user_id?: ?int}  $data
     */
    public function cobrar(User $user, EventoParticipante $participante, array $data): void
    {
        if (! $participante->deuda_id) {
            throw ValidationException::withMessages(['monto' => 'Esta persona no tiene nada que pagarte en este evento.']);
        }
        $this->deudores->registrarPago($user, $participante->contacto, $data + ['deuda_id' => $participante->deuda_id]);
    }

    /**
     * Pagó otra persona: marcar que un participante ya le pagó su parte. Si es del hogar, con "origen" (efectivo,
     * cuenta o tarjeta) se registra el egreso con la categoría elegida.
     *
     * @param  array{fecha: string, origen?: ?string, categoria_personal_id?: ?int, confirmar?: bool}  $data
     */
    public function marcarPagado(User $user, EventoParticipante $participante, array $data): void
    {
        $evento = $participante->evento;
        $pagador = $evento->pagador();
        if ($evento->pagoElHogar() || $participante->es_pagador || $participante->es_festejado) {
            throw ValidationException::withMessages(['fecha' => 'Aquí no hay un pago que marcar.']);
        }

        DB::transaction(function () use ($user, $participante, $data, $evento, $pagador) {
            $monto = $this->calcular($evento)['participantes'][$participante->id]['total'];
            $origen = $participante->user_id ? ($data['origen'] ?? 'ninguno') : 'ninguno';
            $enlace = $origen === 'ninguno' ? [] : $this->crearMovimiento($user, $origen, [
                'descripcion' => "{$evento->nombre}: mi parte a {$pagador->nombre()}", 'monto' => $monto, 'fecha' => $data['fecha'],
                'categoria_personal_id' => $data['categoria_personal_id'] ?? null, 'user_id' => $participante->user_id,
                'contacto_id' => $pagador->contacto_id, 'confirmar' => ! empty($data['confirmar']),
            ]);
            $participante->update(['fecha_pago' => $data['fecha']] + $enlace);
        });
    }

    public function desmarcarPagado(EventoParticipante $participante): void
    {
        DB::transaction(function () use ($participante) {
            $this->quitarMovimiento($participante);
            $participante->update(['fecha_pago' => null]);
        });
    }

    /**
     * Reparte $total centavos según $pesos (clave => peso) sin perder centavos: cada uno recibe la parte entera y los
     * centavos que sobran van a las fracciones más grandes; a igualdad, a quien tenga menos en $exceso (lo que ya
     * recibió de más en otros repartos) y luego en orden. Sin pesos, en partes iguales.
     *
     * @param  array<int, int|float>  $pesos
     * @param  array<int, int|float>  $exceso
     * @return array<int, int>
     */
    public function repartir(int $total, array $pesos, array $exceso = []): array
    {
        if ($pesos === []) {
            return [];
        }
        $suma = array_sum($pesos);
        if ($suma <= 0) {
            $pesos = array_fill_keys(array_keys($pesos), 1);
            $suma = count($pesos);
        }
        $orden = array_flip(array_keys($pesos));
        $partes = $fracciones = [];
        foreach ($pesos as $clave => $peso) {
            $exacto = $total * $peso / $suma;
            $partes[$clave] = (int) floor($exacto + 1e-9);
            $fracciones[$clave] = round($exacto - $partes[$clave], 6);
        }
        $claves = array_keys($fracciones);
        usort($claves, fn ($a, $b) => $fracciones[$b] <=> $fracciones[$a]
            ?: round($exceso[$a] ?? 0, 6) <=> round($exceso[$b] ?? 0, 6)
            ?: $orden[$a] <=> $orden[$b]);
        for ($i = 0, $sobran = $total - array_sum($partes); $i < $sobran; $i++) {
            $partes[$claves[$i % count($claves)]]++;
        }

        return $partes;
    }

    /** Opciones del selector de participantes: los miembros del hogar y los contactos activos. */
    public function opcionesParticipantes(User $user): array
    {
        $miembros = User::where('hogar_id', $user->hogar_id)->orderBy('name')->get()
            ->mapWithKeys(fn (User $u) => ['miembro:'.$u->id => ['label' => $u->name.($u->id === $user->id ? ' (yo)' : ''), 'logo' => null]]);

        return $miembros->all() + $this->contactos->opciones($user);
    }

    /** @return list<EventoParticipante> en el orden del formulario */
    private function guardarParticipantes(User $user, Evento $evento, array $filas, int $pagador): array
    {
        $existentes = $evento->participantes()->with('deuda')->get()->keyBy(fn (EventoParticipante $p) => $p->quien());
        $pagadorAntes = $existentes->firstWhere('es_pagador', true)?->quien();
        $resultado = $vistos = [];

        foreach (array_values($filas) as $i => $fila) {
            $quien = trim((string) $fila['quien']);
            if (preg_match('/^miembro:(\d+)$/', $quien, $m)) {
                $miembro = User::where('hogar_id', $user->hogar_id)->find((int) $m[1])
                    ?? throw ValidationException::withMessages(["participantes.{$i}.quien" => 'Elige un miembro del hogar.']);
                $clave = ['user_id' => $miembro->id, 'contacto_id' => null];
            } else {
                $clave = ['user_id' => null, 'contacto_id' => $this->contactos->resolver($user, $quien)];
            }
            $quien = $clave['user_id'] ? 'miembro:'.$clave['user_id'] : (string) $clave['contacto_id'];
            if (isset($vistos[$quien])) {
                throw ValidationException::withMessages(["participantes.{$i}.quien" => 'Esta persona ya está en la lista.']);
            }
            $vistos[$quien] = true;

            $datos = $clave + ['orden' => $i, 'es_festejado' => ! empty($fila['es_festejado']), 'es_pagador' => $i === $pagador];
            $p = $existentes->pull($quien);
            $p ? $p->update($datos) : $p = $evento->participantes()->create($datos);
            $resultado[] = $p;
        }

        if (! isset($resultado[$pagador])) {
            throw ValidationException::withMessages(['pagador' => 'Elige quién pagó la cuenta.']);
        }
        if ($pagadorAntes !== null && $pagadorAntes !== $resultado[$pagador]->quien() && $this->tieneCobros($evento)) {
            throw ValidationException::withMessages(['pagador' => 'Ya hay pagos registrados entre los participantes: elimínalos antes de cambiar quién pagó.']);
        }
        foreach ($existentes as $quitado) { // ya no está en la lista
            if ($quitado->fecha_pago || ($quitado->deuda && $quitado->deuda->pagos()->exists())) {
                throw ValidationException::withMessages(['participantes' => "{$quitado->nombre()} ya tiene un pago registrado: elimínalo antes de quitarlo del evento."]);
            }
            if ($deuda = $quitado->deuda) {
                $quitado->update(['deuda_id' => null]);
                $deuda->delete();
            }
            $quitado->delete();
        }

        return $resultado;
    }

    private function tieneCobros(Evento $evento): bool
    {
        return $evento->participantes()->where(fn ($q) => $q->whereNotNull('fecha_pago')
            ->orWhereHas('deuda', fn ($q) => $q->whereHas('pagos')))->exists();
    }

    /** Si pagó el hogar, la parte de cada contacto es una deuda (no puede quedar por debajo de lo ya cobrado). */
    private function sincronizarDeudas(Evento $evento, array $calculo): void
    {
        $evento->load('participantes.deuda', 'participantes.contacto');
        $pagoElHogar = $evento->pagoElHogar();

        foreach ($evento->participantes as $p) {
            $total = $calculo['participantes'][$p->id]['total'];
            if ($p->fecha_pago && ! $pagoElHogar && $p->movimiento() && round((float) $p->movimiento()->monto, 2) !== round($total, 2)) {
                throw ValidationException::withMessages(['consumos' => "Ya registraste el pago de {$p->nombre()} (\$ ".number_format((float) $p->movimiento()->monto, 2).'): desmárcalo antes de cambiar su parte.']);
            }
            $monto = $pagoElHogar && $p->contacto_id && ! $p->es_pagador ? $total : 0.0;
            $deuda = $p->deuda;
            if ($monto > 0) {
                $datos = ['descripcion' => "Evento: {$evento->nombre}", 'monto' => $monto, 'fecha' => $evento->fecha->toDateString()];
                if ($deuda) {
                    if ($monto < $deuda->pagado()) {
                        throw ValidationException::withMessages(['consumos' => "A {$p->nombre()} ya le cobraste \$ ".number_format($deuda->pagado(), 2).': su parte no puede quedar en $ '.number_format($monto, 2).'.']);
                    }
                    $deuda->update($datos);
                } else {
                    $deuda = $p->contacto->deudas()->create($datos + ['user_id' => $evento->user_id, 'origen' => 'ninguno']);
                    $p->update(['deuda_id' => $deuda->id]);
                }
            } elseif ($deuda) {
                if ($deuda->pagos()->exists()) {
                    throw ValidationException::withMessages(['consumos' => "A {$p->nombre()} ya le cobraste: elimina ese cobro antes de quitarle su parte."]);
                }
                $p->update(['deuda_id' => null]);
                $deuda->delete();
            }
        }
    }

    /** El pago de la cuenta: un solo movimiento por el total, desde donde pagó el hogar (o nada si pagó otra persona). */
    private function sincronizarPago(User $user, Evento $evento, array $data, float $total): void
    {
        $pagador = $evento->pagador();
        $origen = $evento->pagoElHogar() && $total > 0 ? ($data['origen'] ?? 'ninguno') : 'ninguno';
        $movimiento = $evento->movimiento();
        $datos = ['descripcion' => "Evento: {$evento->nombre}", 'monto' => $total, 'fecha' => $evento->fecha->toDateString(),
            'categoria_personal_id' => $data['categoria_personal_id'] ?? null, 'user_id' => $pagador?->user_id, 'confirmar' => ! empty($data['confirmar'])];

        if ($movimiento && $this->claveOrigen($movimiento) === $origen) { // mismo origen: se actualiza
            $this->desvincular($evento);
            $this->actualizarMovimiento($movimiento, $datos);
            $evento->update(['origen' => explode(':', $origen)[0], 'categoria_personal_id' => $datos['categoria_personal_id']] + $this->enlace($movimiento));

            return;
        }
        $this->quitarMovimiento($evento);
        $enlace = $origen === 'ninguno' ? [] : $this->crearMovimiento($user, $origen, $datos);
        $evento->update(['origen' => $evento->pagoElHogar() ? explode(':', $origen)[0] : null,
            'categoria_personal_id' => $origen === 'ninguno' ? null : $datos['categoria_personal_id']] + $enlace);
    }

    /** "efectivo" | "cuenta:{id}" | "tarjeta:{id}" del movimiento. */
    private function claveOrigen(Model $movimiento): string
    {
        return match (true) {
            $movimiento instanceof MovimientoEfectivo => 'efectivo',
            $movimiento instanceof MovimientoCuenta => 'cuenta:'.$movimiento->cuenta_id,
            $movimiento instanceof MovimientoTarjeta => 'tarjeta:'.$movimiento->tarjeta_credito_id,
        };
    }

    /** @return array<string, int> columnas movimiento_*_id que apuntan al movimiento */
    private function crearMovimiento(User $user, string $origen, array $datos): array
    {
        $confirmar = $datos['confirmar'] ?? false;
        unset($datos['confirmar']);
        if ($origen === 'efectivo') {
            return $this->enlace($this->efectivo->registrar($user, $datos + ['tipo' => 'egreso']));
        }
        if (preg_match('/^cuenta:(\d+)$/', $origen, $m)) {
            $cuenta = $user->cuentas()->where('activo', true)->find($m[1]) ?? throw ValidationException::withMessages(['origen' => 'Selecciona una cuenta válida.']);

            return $this->enlace($this->cuentas->registrarMovimiento($cuenta, $datos + ['tipo' => 'egreso']));
        }
        if (preg_match('/^tarjeta:(\d+)$/', $origen, $m)) {
            $tarjeta = $user->tarjetasCredito()->where('activo', true)->find($m[1]) ?? throw ValidationException::withMessages(['origen' => 'Selecciona una tarjeta válida.']);

            return $this->enlace($this->tarjetas->registrarMovimiento($tarjeta, $datos + ['tipo' => 'compra', 'confirmar' => $confirmar]));
        }
        throw ValidationException::withMessages(['origen' => 'Elige de dónde salió el dinero.']);
    }

    /** Cambia monto, fecha y categoría de un movimiento ya desvinculado (con las reglas de su módulo). */
    private function actualizarMovimiento(Model $movimiento, array $datos): void
    {
        $iguales = round((float) $movimiento->monto, 2) === round((float) $datos['monto'], 2) && $movimiento->fecha->toDateString() === $datos['fecha']
            && (int) $movimiento->categoria_personal_id === (int) $datos['categoria_personal_id'];
        if ($iguales) {
            return;
        }
        $editable = match (true) {
            $movimiento instanceof MovimientoEfectivo => $this->efectivo->esEditable($movimiento),
            $movimiento instanceof MovimientoCuenta => $this->cuentas->esEditable($movimiento),
            $movimiento instanceof MovimientoTarjeta => $this->tarjetas->esEditable($movimiento),
        };
        if (! $editable) {
            throw ValidationException::withMessages(['origen' => 'El pago de la cuenta ($ '.number_format((float) $movimiento->monto, 2).') es de un ciclo que ya cerró: el total, la fecha y la categoría ya no pueden cambiar.']);
        }
        $confirmar = $datos['confirmar'] ?? false;
        $datos = ['descripcion' => $datos['descripcion'], 'monto' => $datos['monto'], 'fecha' => $datos['fecha'], 'categoria_personal_id' => $datos['categoria_personal_id']];
        match (true) {
            $movimiento instanceof MovimientoEfectivo => $this->efectivo->actualizar($movimiento, $datos + ['tipo' => 'egreso']),
            $movimiento instanceof MovimientoCuenta => $this->cuentas->actualizarMovimiento($movimiento, $datos + ['tipo' => 'egreso']),
            $movimiento instanceof MovimientoTarjeta => $this->tarjetas->actualizarMovimiento($movimiento, $datos + ['tipo' => 'compra', 'cuotas' => null, 'dia_cuota' => null, 'confirmar' => $confirmar]),
        };
    }

    /** Elimina el movimiento ligado a un evento o a un participante (con las reglas de su módulo). */
    private function quitarMovimiento(Evento|EventoParticipante $registro): void
    {
        $movimiento = $registro->movimiento();
        if (! $movimiento) {
            return;
        }
        $this->desvincular($registro);
        match (true) {
            $movimiento instanceof MovimientoEfectivo => $this->efectivo->eliminar($movimiento),
            $movimiento instanceof MovimientoCuenta => $this->cuentas->eliminarMovimiento($movimiento),
            $movimiento instanceof MovimientoTarjeta => $this->tarjetas->eliminarMovimiento($movimiento),
        };
    }

    /** Suelta el movimiento para que su módulo deje modificarlo (MovimientoVinculado ya no lo ve ligado). */
    private function desvincular(Evento|EventoParticipante $registro): void
    {
        $registro->update(['movimiento_efectivo_id' => null, 'movimiento_cuenta_id' => null, 'movimiento_tarjeta_id' => null]);
        $registro->unsetRelation('movimientoEfectivo')->unsetRelation('movimientoCuenta')->unsetRelation('movimientoTarjeta');
    }

    private function enlace(Model $movimiento): array
    {
        return match (true) {
            $movimiento instanceof MovimientoEfectivo => ['movimiento_efectivo_id' => $movimiento->id],
            $movimiento instanceof MovimientoCuenta => ['movimiento_cuenta_id' => $movimiento->id],
            $movimiento instanceof MovimientoTarjeta => ['movimiento_tarjeta_id' => $movimiento->id],
        };
    }

    /** Eventos del hogar, del más reciente al más antiguo, con su total y lo que falta cobrar. */
    public function listar(User $user): Collection
    {
        return $user->eventos()->with('participantes.user', 'participantes.contacto', 'participantes.deuda', 'consumos.participantes')
            ->orderByDesc('fecha')->orderByDesc('id')->get()
            ->each(fn (Evento $e) => $e->setAttribute('resumen', $this->resumen($e)));
    }
}
