<?php

namespace App\Services;

use App\Models\CategoriaPersonal;
use App\Models\Deuda;
use App\Models\DeudaPago;
use App\Models\Deudor;
use App\Models\MovimientoCuenta;
use App\Models\MovimientoEfectivo;
use App\Models\MovimientoTarjeta;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Deudores ("nos deben").
 *
 *  - Una deuda puede salir del efectivo, de una cuenta o de una tarjeta (se crea ese movimiento con la
 *    categoría "Préstamos a terceros") o solo anotarse ("ninguno") si el dinero ya estaba registrado.
 *  - Un pago entra al efectivo o a una cuenta (categoría "Cobros de deudas") y se reparte entre las deudas
 *    pendientes del deudor, de la más antigua a la más reciente, o se aplica a una sola.
 *  - Lo pagado y lo pendiente se calculan de los pagos; nunca se guardan.
 *  - Los movimientos ligados a un deudor se modifican desde Deudores (App\Support\MovimientoVinculado).
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

    /** Deudores del hogar con lo prestado, lo pagado y lo pendiente. */
    public function listar(User $user): Collection
    {
        return $user->deudores()->with(['deudas' => fn ($q) => $q->withSum('pagos', 'monto')])
            ->orderByDesc('activo')->orderBy('nombre')->get()
            ->each(fn (Deudor $d) => $this->resumir($d));
    }

    /** Agrega prestado, pagado, pendiente y deudas_abiertas al deudor (con sus deudas cargadas). */
    public function resumir(Deudor $deudor): Deudor
    {
        $deudas = $deudor->relationLoaded('deudas') ? $deudor->deudas : $deudor->deudas()->withSum('pagos', 'monto')->get();
        $deudor->setAttribute('prestado', round((float) $deudas->sum('monto'), 2));
        $deudor->setAttribute('pagado', round($deudas->sum(fn (Deuda $d) => $d->pagado()), 2));
        $deudor->setAttribute('pendiente', round($deudas->sum(fn (Deuda $d) => $d->pendiente()), 2));
        $deudor->setAttribute('deudas_abiertas', $deudas->filter(fn (Deuda $d) => $d->pendiente() > 0)->count());

        return $deudor;
    }

    public function eliminarDeudor(Deudor $deudor): void
    {
        if ($deudor->deudas()->exists()) {
            throw ValidationException::withMessages(['deudor' => 'Este deudor tiene historial. Desactívalo en lugar de eliminarlo.']);
        }
        $deudor->delete();
    }

    /**
     * @param  array{descripcion: string, monto: numeric, fecha: string, origen: string, comentario?: ?string, user_id?: ?int}  $data
     *                                                                                                                                 origen: "efectivo" | "cuenta:{id}" | "tarjeta:{id}" | "ninguno"
     */
    public function registrarDeuda(User $user, Deudor $deudor, array $data): Deuda
    {
        $quien = $data['user_id'] ?? $user->id;
        $base = ['descripcion' => "Préstamo a {$deudor->nombre}: {$data['descripcion']}", 'monto' => $data['monto'], 'fecha' => $data['fecha'],
            'user_id' => $quien, 'categoria_personal_id' => $this->categoria($user, self::CATEGORIA_PRESTAMO, 'egreso')];

        return DB::transaction(function () use ($user, $deudor, $data, $quien, $base) {
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

            return $deudor->deudas()->create($deuda);
        });
    }

    /** Solo sin pagos; el movimiento que generó también se elimina (con las reglas de su módulo). */
    public function eliminarDeuda(Deuda $deuda): void
    {
        if ($deuda->pagos()->exists()) {
            throw ValidationException::withMessages(['deuda' => 'Esta deuda ya tiene pagos: elimina primero los pagos.']);
        }

        DB::transaction(function () use ($deuda) {
            $movimiento = $deuda->movimientoEfectivo ?? $deuda->movimientoCuenta ?? $deuda->movimientoTarjeta;
            $deuda->delete();
            $this->eliminarMovimiento($movimiento);
        });
    }

    /**
     * Un pago del deudor. Sin deuda_id se reparte entre sus deudas pendientes, de la más antigua a la más reciente.
     *
     * @param  array{monto: numeric, fecha: string, destino: string, deuda_id?: ?int, comentario?: ?string, user_id?: ?int}  $data
     *                                                                                                                              destino: "efectivo" | "cuenta:{id}" | "ninguno"
     * @return Collection<int, DeudaPago>
     */
    public function registrarPago(User $user, Deudor $deudor, array $data): Collection
    {
        return DB::transaction(function () use ($user, $deudor, $data) {
            Deudor::whereKey($deudor->id)->lockForUpdate()->first();
            $deudas = $deudor->deudas()->withSum('pagos', 'monto')->orderBy('fecha')->orderBy('id')->get()
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
            $mov = ['descripcion' => "Pago de {$deudor->nombre}", 'monto' => $monto, 'fecha' => $data['fecha'], 'tipo' => 'ingreso',
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
