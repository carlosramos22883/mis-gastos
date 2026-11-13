<?php

namespace App\Services;

use App\Exceptions\ExcedePresupuesto;
use App\Models\Compromiso;
use App\Models\MovimientoCuenta;
use App\Models\MovimientoEfectivo;
use App\Models\MovimientoTarjeta;
use App\Models\PresupuestoTraspaso;
use App\Models\User;
use App\Support\CobrosDeEventos;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pasar presupuesto de una categoría a otra en el mismo ciclo, para que ninguna se pase y el total (el tope del hogar)
 * no cambie. Un gasto que pasa su presupuesto avisa antes de guardarse (presupuesto = "revisar"): se puede cubrir lo
 * que se pasa con lo que les queda a otras ("cubrir" + traspasos: cuánto le quita a cada una, deben sumar justo lo que
 * se pasa) o guardarlo así ("pasarme"). Lo movido queda ligado al gasto: si se borra, baja o cambia de categoría, el
 * presupuesto regresa (reconciliar). También se puede cubrir a mano un presupuesto que ya se pasó.
 */
class PresupuestoTraspasoService
{
    public const MODOS = ['revisar', 'pasarme', 'cubrir'];

    public function __construct(private readonly PresupuestoService $presupuestos, private readonly CicloEfectivoService $ciclos) {}

    /** El presupuesto abierto de la categoría cuyo ciclo incluye la fecha (o null). */
    public function presupuestoPara(?int $hogarId, ?int $categoriaId, string $fecha): ?Compromiso
    {
        if (! $hogarId || ! $categoriaId) {
            return null;
        }

        return Compromiso::with('user', 'categoria')->where('hogar_id', $hogarId)->where('es_presupuesto', true)->where('estado', Compromiso::PENDIENTE)
            ->where('categoria_personal_id', $categoriaId)->whereNotNull('ciclo_inicio')->get()
            ->first(function (Compromiso $p) use ($fecha) {
                [$inicio, $fin] = $this->ciclos->cycleFor($p->user, $p->ciclo_inicio);

                return $fecha >= $inicio->toDateString() && $fecha <= $fin->toDateString();
            });
    }

    /**
     * Si un gasto de $monto en la categoría pasa su presupuesto ($sin = el movimiento que se edita, como si no
     * existiera): el presupuesto, cuánto le quedaba, cuánto se pasa y lo que les queda a las demás del ciclo. Null si no se pasa.
     *
     * @return array{presupuesto: Compromiso, queda: float, gasto: float, excedente: float, opciones: Collection<int, array{presupuesto: Compromiso, disponible: float}>}|null
     */
    public function exceso(?int $hogarId, ?int $categoriaId, string $fecha, float $monto, ?Model $sin = null): ?array
    {
        $presupuesto = $this->presupuestoPara($hogarId, $categoriaId, $fecha);
        if (! $presupuesto) {
            return null;
        }
        $queda = $this->presupuestos->resumen($presupuesto, $sin)['queda'];
        $excedente = round($monto - $queda, 2);
        if ($excedente <= 0) {
            return null;
        }

        return ['presupuesto' => $presupuesto, 'queda' => $queda, 'gasto' => round($monto, 2), 'excedente' => $excedente,
            'opciones' => $this->opciones($presupuesto, $sin)];
    }

    /** @return Collection<int, array{presupuesto: Compromiso, disponible: float}> de las que más tienen a las que menos */
    public function opciones(Compromiso $presupuesto, ?Model $sin = null): Collection
    {
        return Compromiso::with('categoria')->where('hogar_id', $presupuesto->hogar_id)->where('es_presupuesto', true)->where('estado', Compromiso::PENDIENTE)
            ->whereDate('ciclo_inicio', $presupuesto->ciclo_inicio->toDateString())->whereKeyNot($presupuesto->id)->get()
            ->map(fn (Compromiso $o) => ['presupuesto' => $o, 'disponible' => max(0.0, $this->presupuestos->resumen($o, $sin)['queda'])])
            ->filter(fn ($o) => $o['disponible'] > 0)->sortByDesc('disponible')->values();
    }

    /** Lo que se le manda al cliente (montos como texto "12.50"). */
    public function detalle(array $exceso): array
    {
        $p = $exceso['presupuesto'];
        $dinero = fn ($v) => number_format((float) $v, 2, '.', '');
        $nombre = $p->categoria?->nombre ?? $p->descripcion;
        $disponible = round((float) $exceso['opciones']->sum('disponible'), 2);

        return [
            'mensaje' => "Con este gasto te pasas del presupuesto de «{$nombre}» por \$ ".number_format($exceso['excedente'], 2).'.',
            'presupuesto_id' => $p->id, 'descripcion' => $p->descripcion, 'categoria' => $nombre, 'color' => $p->categoria?->color,
            'queda' => $dinero($exceso['queda']), 'gasto' => $dinero($exceso['gasto']), 'excedente' => $dinero($exceso['excedente']),
            'disponible' => $dinero($disponible), 'alcanza' => $disponible >= $exceso['excedente'],
            'opciones' => $exceso['opciones']->map(fn ($o) => ['presupuesto_id' => $o['presupuesto']->id, 'descripcion' => $o['presupuesto']->descripcion,
                'categoria' => $o['presupuesto']->categoria?->nombre ?? $o['presupuesto']->descripcion, 'color' => $o['presupuesto']->categoria?->color,
                'disponible' => $dinero($o['disponible'])])->all(),
        ];
    }

    /**
     * Guarda un movimiento revisando su presupuesto. $data trae presupuesto (revisar | pasarme | cubrir; sin él no se
     * revisa) y traspasos [{presupuesto_id, monto}]; $guardar recibe $data sin esas dos claves y devuelve el movimiento.
     */
    public function guardar(array $data, bool $esGasto, ?Model $actual, Closure $guardar): mixed
    {
        return DB::transaction(function () use ($data, $esGasto, $actual, $guardar) {
            $plan = null;
            if ($esGasto) {
                $fecha = $data['fecha'] ?? $actual?->fecha?->toDateString();
                $hogar = $actual?->hogar_id ?? auth()->user()?->hogar_id;
                $plan = $this->revisar($data, $hogar, $data['categoria_personal_id'] ?? null, (string) $fecha, (float) $data['monto'], $actual);
            }
            $resultado = $guardar(Arr::except($data, ['presupuesto', 'traspasos']));
            $movimiento = $resultado instanceof Model ? $resultado : $actual?->fresh();
            if ($plan !== null && $movimiento) {
                $this->aplicar($movimiento, $plan);
            }

            return $resultado;
        });
    }

    /**
     * Antes de guardar: con "revisar" y si se pasa, avisa (ExcedePresupuesto); con "cubrir", valida lo que se quita a
     * cada una. Devuelve lo que hay que mover [presupuesto_id => monto] o null si no hay nada que hacer.
     *
     * @return array<int, float>|null
     */
    public function revisar(array $data, ?int $hogarId, ?int $categoriaId, string $fecha, float $monto, ?Model $actual): ?array
    {
        $modo = $data['presupuesto'] ?? null;
        if (! in_array($modo, ['revisar', 'cubrir'], true)) {
            return null; // sin revisar o "pasarme": se guarda así
        }
        $exceso = $this->exceso($hogarId, $categoriaId, $fecha, $monto, $actual);
        if (! $exceso) {
            return null;
        }
        if ($modo === 'revisar') {
            // Al editar: si lo que ya movió este gasto alcanza, no se pregunta otra vez (lo que sobre regresa al reconciliar)
            $yaMovido = $actual ? (float) $this->propios($actual)->where('destino_id', $exceso['presupuesto']->id)->sum('monto') : 0.0;
            if ($yaMovido > 0 && round($yaMovido, 2) >= $exceso['excedente']) {
                return null;
            }
            throw new ExcedePresupuesto($this->detalle($exceso));
        }

        return $this->validar($exceso, $data['traspasos'] ?? []);
    }

    /**
     * Lo que se quita a cada una: de las que tienen disponible, sin pasar lo que les queda, y que sume justo lo que se pasa.
     *
     * @param  array<int, array{presupuesto_id: int|string, monto: numeric}>  $traspasos
     * @return array<int, float> presupuesto_id => monto
     */
    private function validar(array $exceso, array $traspasos): array
    {
        $opciones = $exceso['opciones']->keyBy(fn ($o) => $o['presupuesto']->id);
        $lista = [];
        foreach (array_values($traspasos) as $i => $t) {
            $id = (int) ($t['presupuesto_id'] ?? 0);
            $monto = round((float) ($t['monto'] ?? 0), 2);
            if ($monto <= 0) {
                continue;
            }
            $opcion = $opciones->get($id) ?? throw ValidationException::withMessages(["traspasos.{$i}.monto" => 'Esa categoría no tiene presupuesto disponible en este ciclo.']);
            if ($monto > $opcion['disponible']) {
                $nombre = $opcion['presupuesto']->categoria?->nombre ?? $opcion['presupuesto']->descripcion;
                throw ValidationException::withMessages(["traspasos.{$i}.monto" => "A «{$nombre}» solo le quedan \$ ".number_format($opcion['disponible'], 2).'.']);
            }
            $lista[$id] = round(($lista[$id] ?? 0) + $monto, 2);
        }
        $suma = round(array_sum($lista), 2);
        if ($suma < $exceso['excedente']) {
            throw ValidationException::withMessages(['traspasos' => 'Te faltan $ '.number_format($exceso['excedente'] - $suma, 2).' para cubrir lo que te pasas: elige otra categoría o sube los montos.']);
        }
        if ($suma > $exceso['excedente']) {
            throw ValidationException::withMessages(['traspasos' => 'Solo necesitas $ '.number_format($exceso['excedente'], 2).': bájale $ '.number_format($suma - $exceso['excedente'], 2).' a los montos.']);
        }

        return $lista;
    }

    /** Lo que movió el gasto se reemplaza por $lista (presupuesto_id => monto). */
    public function aplicar(Model $movimiento, array $lista): void
    {
        $destino = $this->presupuestoPara($movimiento->hogar_id, $movimiento->categoria_personal_id, $movimiento->fecha->toDateString());
        $columna = PresupuestoService::columnaMovimiento($movimiento);
        $afectados = $this->propios($movimiento)->get()->flatMap(fn ($t) => [$t->origen_id, $t->destino_id]);
        PresupuestoTraspaso::where($columna, $movimiento->id)->delete();
        if ($destino) {
            foreach ($lista as $origen => $monto) {
                PresupuestoTraspaso::create(['user_id' => auth()->id() ?? $movimiento->user_id, 'origen_id' => $origen, 'destino_id' => $destino->id,
                    'monto' => $monto, $columna => $movimiento->id]);
            }
            $afectados = $afectados->concat(array_keys($lista))->push($destino->id);
        }
        $this->recalcular($afectados);
    }

    /**
     * Tras guardar el gasto (o cobrar parte de un evento): si ya no hace falta todo lo que movió, regresa lo que sobra
     * (lo último primero); si cambió de categoría o de ciclo, regresa todo.
     */
    public function reconciliar(Model $movimiento): void
    {
        $propios = $this->propios($movimiento)->orderByDesc('id')->get();
        if ($propios->isEmpty()) {
            return;
        }
        $destino = $this->esGasto($movimiento) ? $this->presupuestoPara($movimiento->hogar_id, $movimiento->categoria_personal_id, $movimiento->fecha->toDateString()) : null;
        $necesita = 0.0;
        if ($destino && $destino->id === $propios->first()->destino_id) {
            $contado = (float) CobrosDeEventos::descontar(collect([$movimiento->replicate()->setAttribute('id', $movimiento->id)]))->first()->monto;
            $necesita = max(0.0, round($contado - $this->presupuestos->resumen($destino, $movimiento)['queda'], 2));
        }
        $sobra = round((float) $propios->sum('monto') - $necesita, 2);
        if ($sobra <= 0) {
            return;
        }
        foreach ($propios as $t) {
            if ($sobra <= 0) {
                break;
            }
            $quitar = min($sobra, (float) $t->monto);
            round((float) $t->monto - $quitar, 2) > 0 ? $t->update(['monto' => round((float) $t->monto - $quitar, 2)]) : $t->delete();
            $sobra = round($sobra - $quitar, 2);
        }
        $this->recalcular($propios->flatMap(fn ($t) => [$t->origen_id, $t->destino_id]));
    }

    /** El gasto se borra: todo lo que movió regresa. */
    public function quitarDe(Model $movimiento): void
    {
        $propios = $this->propios($movimiento)->get();
        if ($propios->isEmpty()) {
            return;
        }
        $this->propios($movimiento)->delete();
        $this->recalcular($propios->flatMap(fn ($t) => [$t->origen_id, $t->destino_id]));
    }

    /**
     * Cubrir a mano un presupuesto que ya se pasó (sin gasto nuevo).
     *
     * @param  array<int, array{presupuesto_id: int|string, monto: numeric}>  $traspasos
     */
    public function cubrir(User $user, Compromiso $presupuesto, array $traspasos): void
    {
        DB::transaction(function () use ($user, $presupuesto, $traspasos) {
            abort_unless($presupuesto->es_presupuesto && $presupuesto->estado === Compromiso::PENDIENTE, 422, 'Solo se cubre un presupuesto del ciclo abierto.');
            $exceso = $this->excesoActual($presupuesto) ?? throw ValidationException::withMessages(['traspasos' => 'Este presupuesto no se ha pasado: no hay nada que cubrir.']);
            $lista = $this->validar($exceso, $traspasos);
            foreach ($lista as $origen => $monto) {
                PresupuestoTraspaso::create(['user_id' => $user->id, 'origen_id' => $origen, 'destino_id' => $presupuesto->id, 'monto' => $monto]);
            }
            $this->recalcular(collect(array_keys($lista))->push($presupuesto->id));
        });
    }

    /**
     * Repartir lo que le queda a un presupuesto (lo que no se va a usar) entre otras categorías del ciclo. Para el
     * aviso: disponible = lo que le queda; opciones = las demás con lo que les queda ahora (puede ser negativo).
     */
    public function detalleReparto(Compromiso $origen): ?array
    {
        $disponible = max(0.0, $this->presupuestos->resumen($origen)['queda']);
        if ($disponible <= 0 || $origen->estado !== Compromiso::PENDIENTE) {
            return null;
        }
        $dinero = fn ($v) => number_format((float) $v, 2, '.', '');
        $origen->loadMissing('categoria');
        $destinos = Compromiso::with('categoria')->where('hogar_id', $origen->hogar_id)->where('es_presupuesto', true)->where('estado', Compromiso::PENDIENTE)
            ->whereDate('ciclo_inicio', $origen->ciclo_inicio->toDateString())->whereKeyNot($origen->id)->get()
            ->map(fn (Compromiso $d) => ['presupuesto' => $d, 'queda' => $this->presupuestos->resumen($d)['queda']])->sortBy('queda')->values();
        $nombre = $origen->categoria?->nombre ?? $origen->descripcion;

        return [
            'mensaje' => "A «{$nombre}» le quedan \$ ".number_format($disponible, 2).'.', 'presupuesto_id' => $origen->id, 'descripcion' => $origen->descripcion,
            'categoria' => $nombre, 'color' => $origen->categoria?->color, 'disponible' => $dinero($disponible),
            'opciones' => $destinos->map(fn ($d) => ['presupuesto_id' => $d['presupuesto']->id, 'descripcion' => $d['presupuesto']->descripcion,
                'categoria' => $d['presupuesto']->categoria?->nombre ?? $d['presupuesto']->descripcion, 'color' => $d['presupuesto']->categoria?->color,
                'disponible' => $dinero($d['queda'])])->all(),
        ];
    }

    /**
     * Pasa lo que le queda a $origen a las categorías elegidas (cuánto a cada una), sin pasar lo que le queda.
     *
     * @param  array<int, array{presupuesto_id: int|string, monto: numeric}>  $traspasos
     */
    public function repartir(User $user, Compromiso $origen, array $traspasos): void
    {
        DB::transaction(function () use ($user, $origen, $traspasos) {
            abort_unless($origen->es_presupuesto && $origen->estado === Compromiso::PENDIENTE, 422, 'Solo se reparte un presupuesto del ciclo abierto.');
            $disponible = max(0.0, $this->presupuestos->resumen($origen)['queda']);
            $destinos = Compromiso::where('hogar_id', $origen->hogar_id)->where('es_presupuesto', true)->where('estado', Compromiso::PENDIENTE)
                ->whereDate('ciclo_inicio', $origen->ciclo_inicio->toDateString())->whereKeyNot($origen->id)->pluck('id')->all();
            $lista = [];
            foreach (array_values($traspasos) as $i => $t) {
                $id = (int) ($t['presupuesto_id'] ?? 0);
                $monto = round((float) ($t['monto'] ?? 0), 2);
                if ($monto <= 0) {
                    continue;
                }
                if (! in_array($id, $destinos, true)) {
                    throw ValidationException::withMessages(["traspasos.{$i}.monto" => 'Esa categoría no tiene presupuesto en este ciclo.']);
                }
                $lista[$id] = round(($lista[$id] ?? 0) + $monto, 2);
            }
            $suma = round(array_sum($lista), 2);
            if (! $lista) {
                throw ValidationException::withMessages(['traspasos' => 'Elige a qué categorías pasarlo y cuánto a cada una.']);
            }
            if ($suma > $disponible) {
                throw ValidationException::withMessages(['traspasos' => 'Solo le quedan $ '.number_format($disponible, 2).': bájale $ '.number_format($suma - $disponible, 2).' a los montos.']);
            }
            foreach ($lista as $destino => $monto) {
                PresupuestoTraspaso::create(['user_id' => $user->id, 'origen_id' => $origen->id, 'destino_id' => $destino, 'monto' => $monto]);
            }
            $this->recalcular(collect(array_keys($lista))->push($origen->id));
        });
    }

    /** Lo que se pasó un presupuesto, como si fuera un gasto nuevo (para cubrirlo a mano), o null. */
    public function excesoActual(Compromiso $presupuesto): ?array
    {
        $queda = $this->presupuestos->resumen($presupuesto)['queda'];
        if ($queda >= 0) {
            return null;
        }

        return ['presupuesto' => $presupuesto->loadMissing('categoria'), 'queda' => $queda, 'gasto' => 0.0, 'excedente' => round(-$queda, 2),
            'opciones' => $this->opciones($presupuesto)];
    }

    /** Deshacer un movimiento hecho a mano (los de un gasto regresan al borrar o cambiar ese gasto). */
    public function deshacer(PresupuestoTraspaso $traspaso): void
    {
        if ($traspaso->movimiento_efectivo_id || $traspaso->movimiento_cuenta_id || $traspaso->movimiento_tarjeta_id) {
            throw ValidationException::withMessages(['traspaso' => 'Este lo pidió un gasto: regresa solo si borras o cambias ese gasto.']);
        }
        DB::transaction(function () use ($traspaso) {
            $traspaso->delete();
            $this->recalcular(collect([$traspaso->origen_id, $traspaso->destino_id]));
        });
    }

    /**
     * Lo que recibió y cedió un presupuesto, del más reciente al más antiguo: con quién, cuánto y por qué gasto.
     *
     * @return Collection<int, PresupuestoTraspaso>
     */
    public function delPresupuesto(Compromiso $presupuesto): Collection
    {
        return PresupuestoTraspaso::with('origen.categoria', 'destino.categoria', 'movimientoEfectivo', 'movimientoCuenta', 'movimientoTarjeta')
            ->where(fn ($q) => $q->where('origen_id', $presupuesto->id)->orWhere('destino_id', $presupuesto->id))->orderByDesc('id')->get();
    }

    private function propios(Model $movimiento)
    {
        return PresupuestoTraspaso::where(PresupuestoService::columnaMovimiento($movimiento), $movimiento->id);
    }

    private function esGasto(Model $movimiento): bool
    {
        return match (true) {
            $movimiento instanceof MovimientoTarjeta => in_array($movimiento->tipo, ['compra', 'cargo'], true),
            $movimiento instanceof MovimientoEfectivo, $movimiento instanceof MovimientoCuenta => $movimiento->tipo === 'egreso' && ! $movimiento->transferencia_id,
            default => false,
        };
    }

    /** @param  Collection<int, int>  $ids */
    private function recalcular(Collection $ids): void
    {
        Compromiso::whereIn('id', $ids->filter()->unique()->values())->get()->each(fn (Compromiso $c) => $this->presupuestos->recalcular($c));
    }
}
