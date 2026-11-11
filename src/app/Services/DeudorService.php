<?php

namespace App\Services;

use App\Models\CategoriaPersonal;
use App\Models\Contacto;
use App\Models\Deuda;
use App\Models\DeudaPago;
use App\Models\EventoParticipante;
use App\Models\MovimientoCuenta;
use App\Models\MovimientoEfectivo;
use App\Models\MovimientoTarjeta;
use App\Models\User;
use App\Support\MovimientoVinculado;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Deudas de contactos ("nos deben").
 *
 *  - Una deuda puede salir del efectivo, de una cuenta o de una tarjeta (se crea ese movimiento con la
 *    categoría "Préstamos a terceros") o solo anotarse ("ninguno") si el dinero ya estaba registrado.
 *  - También puede ligarse a un gasto ya registrado ("vinculada"; ej. una compra con la tarjeta que hizo otra persona):
 *    si es por el monto completo, el gasto pasa a "Préstamos a terceros"; al eliminar la deuda el gasto se queda.
 *  - Un pago entra al efectivo o a una cuenta (categoría "Cobros de deudas") y se reparte entre las deudas
 *    pendientes del contacto, de la más antigua a la más reciente, o se aplica a una sola.
 *  - Lo pagado y lo pendiente se calculan de los pagos; nunca se guardan.
 *  - Los movimientos ligados a una deuda se modifican desde el contacto (App\Support\MovimientoVinculado).
 */
class DeudorService
{
    public const CATEGORIA_PRESTAMO = 'Préstamos a terceros';

    public const CATEGORIA_COBRO = 'Cobros de deudas';

    public function __construct(
        private readonly MovimientoEfectivoService $efectivo,
        private readonly CuentaService $cuentas,
        private readonly TarjetaService $tarjetas,
    ) {}

    /** Contactos del hogar que tienen deudas, con lo prestado, lo pagado y lo pendiente ("Nos deben"). */
    public function listar(User $user): Collection
    {
        return $user->contactos()->whereHas('deudas')->with(['deudas' => fn ($q) => $q->withSum('pagos', 'monto')])
            ->orderByDesc('activo')->orderBy('nombre')->get()
            ->each(fn (Contacto $c) => $this->resumir($c));
    }

    /** Agrega prestado, pagado, pendiente y deudas_abiertas al contacto (con sus deudas cargadas). */
    public function resumir(Contacto $contacto): Contacto
    {
        $deudas = $contacto->relationLoaded('deudas') ? $contacto->deudas : $contacto->deudas()->withSum('pagos', 'monto')->get();
        $contacto->setAttribute('prestado', round((float) $deudas->sum('monto'), 2));
        $contacto->setAttribute('pagado', round($deudas->sum(fn (Deuda $d) => $d->pagado()), 2));
        $contacto->setAttribute('pendiente', round($deudas->sum(fn (Deuda $d) => $d->pendiente()), 2));
        $contacto->setAttribute('deudas_abiertas', $deudas->filter(fn (Deuda $d) => $d->pendiente() > 0)->count());

        return $contacto;
    }

    /**
     * @param  array{descripcion: string, monto: numeric, fecha: string, origen: string, comentario?: ?string, user_id?: ?int}  $data
     *                                                                                                                                 origen: "efectivo" | "cuenta:{id}" | "tarjeta:{id}" | "ninguno"
     */
    public function registrarDeuda(User $user, Contacto $contacto, array $data): Deuda
    {
        $quien = $data['user_id'] ?? $user->id;
        $base = ['descripcion' => "Préstamo a {$contacto->nombreCompleto()}: {$data['descripcion']}", 'monto' => $data['monto'], 'fecha' => $data['fecha'], 'contacto_id' => $contacto->id,
            'user_id' => $quien, 'categoria_personal_id' => $this->categoria($user, self::CATEGORIA_PRESTAMO, 'egreso')];

        return DB::transaction(function () use ($user, $contacto, $data, $quien, $base) {
            $deuda = ['user_id' => $quien, 'descripcion' => $data['descripcion'], 'comentario' => $data['comentario'] ?? null,
                'monto' => $data['monto'], 'fecha' => $data['fecha']];
            $origen = $data['origen'];

            if ($origen === 'efectivo') {
                $mov = $this->efectivo->registrar($user, $base + ['tipo' => 'egreso']);
                $deuda += ['origen' => 'efectivo', 'movimiento_efectivo_id' => $mov->id];
            } elseif (preg_match('/^cuenta:(\d+)$/', $origen, $m)) {
                $cuenta = $user->cuentas()->where('activo', true)->find($m[1]) ?? throw ValidationException::withMessages(['origen' => 'Selecciona una cuenta válida.']);
                $mov = $this->cuentas->registrarMovimiento($cuenta, $base + ['tipo' => 'egreso']);
                $deuda += ['origen' => 'cuenta', 'movimiento_cuenta_id' => $mov->id];
            } elseif (preg_match('/^tarjeta:(\d+)$/', $origen, $m)) {
                $tarjeta = $user->tarjetasCredito()->where('activo', true)->find($m[1]) ?? throw ValidationException::withMessages(['origen' => 'Selecciona una tarjeta válida.']);
                $mov = $this->tarjetas->registrarMovimiento($tarjeta, $base + ['tipo' => 'compra']);
                $deuda += ['origen' => 'tarjeta', 'movimiento_tarjeta_id' => $mov->id];
            } else {
                $deuda += ['origen' => 'ninguno'];
            }

            return $contacto->deudas()->create($deuda);
        });
    }

    /** Si el movimiento puede ligarse a una deuda: un gasto propio (no de otro módulo, de una transferencia ni de un compromiso). */
    public function vinculable(Model $movimiento): bool
    {
        $gasto = match (true) {
            $movimiento instanceof MovimientoEfectivo => $movimiento->tipo === 'egreso' && ! $movimiento->es_saldo_arrastrado,
            $movimiento instanceof MovimientoCuenta => $movimiento->tipo === 'egreso',
            $movimiento instanceof MovimientoTarjeta => $movimiento->tipo === 'compra' && ! $movimiento->pago_compromiso_id,
            default => false,
        };

        return $gasto && ! $movimiento->transferencia_id && ! $movimiento->compromiso_id && MovimientoVinculado::modulo($movimiento) === null;
    }

    /**
     * Liga un gasto ya registrado a una deuda nueva del contacto, por todo el monto o por una parte. Con el monto
     * completo el gasto pasa a "Préstamos a terceros" (deja de ser gasto propio); con una parte conserva su categoría.
     *
     * @param  array{descripcion: string, monto: numeric, comentario?: ?string}  $data
     */
    public function vincularMovimiento(User $user, Model $movimiento, Contacto $contacto, array $data): Deuda
    {
        return DB::transaction(function () use ($user, $movimiento, $contacto, $data) {
            $movimiento = $movimiento::whereKey($movimiento->id)->lockForUpdate()->firstOrFail();
            if (! $this->vinculable($movimiento)) {
                throw ValidationException::withMessages(['monto' => 'Este movimiento no se puede ligar a una deuda.']);
            }
            $monto = round((float) $data['monto'], 2);
            if ($monto > (float) $movimiento->monto) {
                throw ValidationException::withMessages(['monto' => 'La deuda no puede superar el monto del movimiento ($ '.number_format((float) $movimiento->monto, 2).').']);
            }
            $completo = $monto === round((float) $movimiento->monto, 2);
            $origen = match (true) {
                $movimiento instanceof MovimientoEfectivo => 'efectivo',
                $movimiento instanceof MovimientoCuenta => 'cuenta',
                default => 'tarjeta',
            };

            $deuda = $contacto->deudas()->create([
                'user_id' => $movimiento->user_id, 'descripcion' => $data['descripcion'], 'comentario' => $data['comentario'] ?? null,
                'monto' => $monto, 'fecha' => $movimiento->fecha, 'origen' => $origen, 'vinculada' => true,
                'categoria_original_id' => $completo ? $movimiento->categoria_personal_id : null, "movimiento_{$origen}_id" => $movimiento->id,
            ]);
            $movimiento->update(['contacto_id' => $contacto->id]
                + ($completo ? ['categoria_personal_id' => $this->categoria($user, self::CATEGORIA_PRESTAMO, 'egreso')] : []));

            return $deuda;
        });
    }

    /**
     * Solo sin pagos; el movimiento que generó también se elimina (con las reglas de su módulo). Si la deuda se ligó
     * a un gasto que ya existía, el gasto se queda y recupera su categoría.
     */
    public function eliminarDeuda(Deuda $deuda): void
    {
        if (EventoParticipante::where('deuda_id', $deuda->id)->exists()) {
            throw ValidationException::withMessages(['deuda' => 'Esta deuda es la parte de un evento: se modifica desde Eventos.']);
        }
        if ($deuda->pagos()->exists()) {
            throw ValidationException::withMessages(['deuda' => 'Esta deuda ya tiene pagos: elimina primero los pagos.']);
        }

        DB::transaction(function () use ($deuda) {
            $movimiento = $deuda->movimientoEfectivo ?? $deuda->movimientoCuenta ?? $deuda->movimientoTarjeta;
            $deuda->delete();
            if (! $deuda->vinculada) {
                $this->eliminarMovimiento($movimiento);
            } elseif ($movimiento && round((float) $deuda->monto, 2) === round((float) $movimiento->monto, 2)) {
                $movimiento->update(['categoria_personal_id' => $deuda->categoria_original_id]); // la deuda le había cambiado la categoría
            }
        });
    }

    /**
     * Un pago del contacto. Sin deuda_id se reparte entre sus deudas pendientes, de la más antigua a la más reciente.
     *
     * @param  array{monto: numeric, fecha: string, destino: string, deuda_id?: ?int, comentario?: ?string, user_id?: ?int}  $data
     *                                                                                                                              destino: "efectivo" | "cuenta:{id}" | "ninguno"
     * @return Collection<int, DeudaPago>
     */
    public function registrarPago(User $user, Contacto $contacto, array $data): Collection
    {
        return DB::transaction(function () use ($user, $contacto, $data) {
            Contacto::whereKey($contacto->id)->lockForUpdate()->first();
            $deudas = $contacto->deudas()->withSum('pagos', 'monto')->orderBy('fecha')->orderBy('id')->get()
                ->filter(fn (Deuda $d) => $d->pendiente() > 0)
                ->when(! empty($data['deuda_id']), fn ($c) => $c->where('id', (int) $data['deuda_id']));
            $monto = round((float) $data['monto'], 2);
            $pendiente = round($deudas->sum(fn (Deuda $d) => $d->pendiente()), 2);
            if ($deudas->isEmpty()) {
                throw ValidationException::withMessages(['monto' => 'No hay deudas pendientes para aplicar este pago.']);
            }
            if ($monto > $pendiente) {
                throw ValidationException::withMessages(['monto' => 'El pago supera lo pendiente ($ '.number_format($pendiente, 2).').']);
            }

            $quien = $data['user_id'] ?? $user->id;
            $mov = ['descripcion' => "Pago de {$contacto->nombreCompleto()}", 'monto' => $monto, 'fecha' => $data['fecha'], 'tipo' => 'ingreso', 'contacto_id' => $contacto->id,
                'user_id' => $quien, 'categoria_personal_id' => $this->categoria($user, self::CATEGORIA_COBRO, 'ingreso')];
            $enlace = ['destino' => 'ninguno'];
            if ($data['destino'] === 'efectivo') {
                $enlace = ['destino' => 'efectivo', 'movimiento_efectivo_id' => $this->efectivo->registrar($user, $mov)->id];
            } elseif (preg_match('/^cuenta:(\d+)$/', $data['destino'], $m)) {
                $cuenta = $user->cuentas()->where('activo', true)->find($m[1]) ?? throw ValidationException::withMessages(['destino' => 'Selecciona una cuenta válida.']);
                $enlace = ['destino' => 'cuenta', 'movimiento_cuenta_id' => $this->cuentas->registrarMovimiento($cuenta, $mov)->id];
            }

            $pagos = collect();
            foreach ($deudas as $deuda) {
                if ($monto <= 0) {
                    break;
                }
                $aplicado = min($monto, $deuda->pendiente());
                $pagos->push($deuda->pagos()->create($enlace + ['user_id' => $quien, 'monto' => $aplicado, 'fecha' => $data['fecha'], 'comentario' => $data['comentario'] ?? null]));
                $monto = round($monto - $aplicado, 2);
            }

            return $pagos;
        });
    }

    /** Elimina el pago y su ingreso; si el ingreso abonó a varias deudas, se eliminan todas sus partes. */
    public function eliminarPago(DeudaPago $pago): void
    {
        DB::transaction(function () use ($pago) {
            $movimiento = $pago->movimientoEfectivo ?? $pago->movimientoCuenta;
            if ($movimiento) {
                DeudaPago::where($pago->movimiento_efectivo_id ? 'movimiento_efectivo_id' : 'movimiento_cuenta_id', $movimiento->id)->delete();
            } else {
                $pago->delete();
            }
            $this->eliminarMovimiento($movimiento);
        });
    }

    private function eliminarMovimiento(?Model $movimiento): void
    {
        match (true) {
            $movimiento instanceof MovimientoEfectivo => $this->efectivo->eliminar($movimiento),
            $movimiento instanceof MovimientoCuenta => $this->cuentas->eliminarMovimiento($movimiento),
            $movimiento instanceof MovimientoTarjeta => $this->tarjetas->eliminarMovimiento($movimiento),
            default => null,
        };
    }

    private function categoria(User $user, string $nombre, string $tipo): int
    {
        return CategoriaPersonal::firstOrCreate(
            ['hogar_id' => $user->hogar_id, 'nombre' => $nombre, 'tipo' => $tipo],
            ['user_id' => $user->id, 'color' => $tipo === 'egreso' ? '#7C3AED' : '#10B981', 'activo' => true],
        )->id;
    }
}
