<?php

namespace App\Services;

use App\Models\Compromiso;
use App\Models\Cuenta;
use App\Models\MovimientoCuenta;
use App\Support\MovimientoVinculado;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cuentas bancarias y billeteras. El saldo es el inicial más los movimientos (nunca puede quedar
 * en negativo); el "saldo al corte" de cada ciclo se calcula al vuelo, sin filas de arrastre.
 */
class CuentaService
{
    public function __construct(
        private readonly CicloEfectivoService $ciclos,
        private readonly CompromisoService $compromisos,
    ) {}

    public function saldo(Cuenta $cuenta, ?Carbon $hasta = null, ?int $exceptId = null): float
    {
        $movimientos = (float) $cuenta->movimientos()
            ->when($hasta, fn ($q) => $q->whereDate('fecha', '<=', $hasta->toDateString()))
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))
            ->selectRaw("COALESCE(SUM(CASE WHEN tipo = 'ingreso' THEN monto ELSE -monto END), 0) saldo")
            ->value('saldo');

        return round((float) $cuenta->saldo_inicial + $movimientos, 2);
    }

    /** @return array{saldo_inicial: float, ingresos: float, egresos: float, saldo_final: float} */
    public function resumen(Cuenta $cuenta, Carbon $inicio, Carbon $fin): array
    {
        $totales = $cuenta->movimientos()
            ->whereBetween('fecha', [$inicio->toDateString(), $fin->toDateString()])
            ->selectRaw("COALESCE(SUM(CASE WHEN tipo = 'ingreso' THEN monto ELSE 0 END), 0) ingresos")
            ->selectRaw("COALESCE(SUM(CASE WHEN tipo = 'egreso' THEN monto ELSE 0 END), 0) egresos")
            ->first();

        return [
            'saldo_inicial' => $this->saldo($cuenta, $inicio->copy()->subDay()),
            'ingresos' => (float) $totales->ingresos,
            'egresos' => (float) $totales->egresos,
            'saldo_final' => $this->saldo($cuenta, $fin),
        ];
    }

    public function consultarMovimientos(Cuenta $cuenta, Carbon $inicio, Carbon $fin, array $filtros = []): Builder
    {
        $search = $filtros['search'] ?? null;
        $sort = in_array($filtros['sort'] ?? null, ['descripcion', 'monto', 'fecha', 'tipo'], true) ? $filtros['sort'] : 'fecha';

        return MovimientoCuenta::with('categoria', 'contacto', 'transferencia.origenCuenta', 'transferencia.destinoCuenta', 'transferencia.origenTarjeta')
            ->where('cuenta_id', $cuenta->id)
            ->whereBetween('fecha', [$inicio->toDateString(), $fin->toDateString()])
            ->when($search, fn ($q) => $q->where('descripcion', 'like', "%{$search}%"))
            ->when(! empty($filtros['tipo']), fn ($q) => $q->where('tipo', $filtros['tipo']))
            ->when(! empty($filtros['categoria']), fn ($q) => $q->where('categoria_personal_id', $filtros['categoria']))
            ->orderBy($sort, ($filtros['direction'] ?? null) === 'asc' ? 'asc' : 'desc')
            ->orderBy('id', 'desc');
    }

    /** Una cuenta con movimientos no se elimina (se perdería el historial): se desactiva. */
    public function eliminar(Cuenta $cuenta): void
    {
        if ($cuenta->movimientos()->exists()) {
            throw ValidationException::withMessages(['cuenta' => 'Esta cuenta ya tiene movimientos. Desactívala en lugar de eliminarla.']);
        }
        $cuenta->delete();
    }

    /** @param array{descripcion: string, monto: numeric, fecha: string, tipo: string, categoria_personal_id?: ?int, compromiso_id?: ?int} $data */
    public function registrarMovimiento(Cuenta $cuenta, array $data): MovimientoCuenta
    {
        return DB::transaction(function () use ($cuenta, $data) {
            $cuenta = Cuenta::whereKey($cuenta->id)->lockForUpdate()->firstOrFail();
            $this->validarFecha($cuenta, $data['fecha']);
            $this->asegurarSaldo($cuenta, $this->efecto($data));
            if (! empty($data['compromiso_id'])) {
                $this->exigirEgreso($data);
                $this->compromisos->abonar(Compromiso::findOrFail($data['compromiso_id']), (float) $data['monto']);
            }

            return $cuenta->movimientos()->create($data);
        });
    }

    public function actualizarMovimiento(MovimientoCuenta $movimiento, array $data): MovimientoCuenta
    {
        $this->asegurarEditable($movimiento);
        unset($data['compromiso_id']);

        return DB::transaction(function () use ($movimiento, $data) {
            $cuenta = Cuenta::whereKey($movimiento->cuenta_id)->lockForUpdate()->firstOrFail();
            $this->validarFecha($cuenta, $data['fecha']);
            $this->asegurarSaldo($cuenta, $this->efecto($data), $movimiento->id);
            if ($movimiento->compromiso) {
                $this->exigirEgreso($data);
                $this->compromisos->abonar($movimiento->compromiso, (float) $data['monto'] - (float) $movimiento->monto);
            }
            $movimiento->update($data);

            return $movimiento;
        });
    }

    public function eliminarMovimiento(MovimientoCuenta $movimiento): void
    {
        $this->asegurarEditable($movimiento);

        DB::transaction(function () use ($movimiento) {
            $cuenta = Cuenta::whereKey($movimiento->cuenta_id)->lockForUpdate()->firstOrFail();
            if ($movimiento->tipo === 'ingreso') {
                $this->asegurarSaldo($cuenta, 0, $movimiento->id, 'No puedes eliminar este ingreso: la cuenta quedaría en negativo.');
            }
            if ($movimiento->compromiso) {
                $this->compromisos->abonar($movimiento->compromiso, -(float) $movimiento->monto);
            }
            $movimiento->delete();
        });
    }

    /** Solo movimientos propios (no de transferencias) del ciclo actual del usuario. */
    public function esEditable(MovimientoCuenta $movimiento, ?Carbon $inicioCicloActual = null): bool
    {
        $inicio = $inicioCicloActual ?? $this->ciclos->cicloConsultado($movimiento->user)[0];

        return ! $movimiento->transferencia_id && $movimiento->fecha->toDateString() >= $inicio->toDateString()
            && MovimientoVinculado::modulo($movimiento) === null; // los de un deudor o un préstamo se gestionan desde su módulo
    }

    /** El saldo de la cuenta no puede quedar en negativo tras aplicar $delta (sin contar $exceptId). */
    public function asegurarSaldo(Cuenta $cuenta, float $delta, ?int $exceptId = null, ?string $message = null): void
    {
        if (round($this->saldo($cuenta, null, $exceptId) + $delta, 2) < 0) {
            throw ValidationException::withMessages(['monto' => $message ?? "El monto supera el saldo disponible en {$cuenta->nombre}."]);
        }
    }

    public function validarFecha(Cuenta $cuenta, string $fecha): void
    {
        if ($fecha < $cuenta->fecha_inicial->toDateString()) {
            throw ValidationException::withMessages(['fecha' => 'La fecha es anterior a la fecha en que se registró la cuenta ('.$cuenta->fecha_inicial->format('d/m/Y').').']);
        }
    }

    private function asegurarEditable(MovimientoCuenta $movimiento): void
    {
        MovimientoVinculado::asegurarLibre($movimiento);
        abort_unless($this->esEditable($movimiento), 403, 'Solo se pueden modificar movimientos propios del ciclo actual.');
    }

    private function efecto(array $data): float
    {
        return $data['tipo'] === 'egreso' ? -(float) $data['monto'] : (float) $data['monto'];
    }

    private function exigirEgreso(array $data): void
    {
        if ($data['tipo'] !== 'egreso') {
            throw ValidationException::withMessages(['tipo' => 'El pago de un compromiso debe ser un egreso.']);
        }
    }
}
