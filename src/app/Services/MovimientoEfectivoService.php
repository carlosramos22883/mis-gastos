<?php

namespace App\Services;

use App\Models\Compromiso;
use App\Models\MovimientoEfectivo;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reglas del efectivo, compartidas por la web y la API:
 * el efectivo nunca queda en negativo y los pagos de compromisos mantienen su saldo al día.
 */
class MovimientoEfectivoService
{
    public function __construct(
        private readonly CicloEfectivoService $ciclos,
        private readonly CompromisoService $compromisos,
    ) {}

    /** @param array{descripcion: string, monto: numeric, fecha: string, tipo: string, categoria_personal_id: int, compromiso_id?: ?int} $data */
    public function registrar(User $user, array $data): MovimientoEfectivo
    {
        $this->ciclos->closeIfNeeded($user);

        return DB::transaction(function () use ($user, $data) {
            $this->asegurarSaldo($user, $this->efecto($data));
            if (! empty($data['compromiso_id'])) {
                $this->exigirEgreso($data);
                $compromiso = Compromiso::findOrFail($data['compromiso_id']);
                $this->abonar($compromiso, (float) $data['monto']);
                $data['compromiso_cuota'] = $compromiso->numero_cuota;
            }

            return $user->movimientosEfectivo()->create($data);
        });
    }

    public function actualizar(MovimientoEfectivo $movimiento, array $data): MovimientoEfectivo
    {
        $this->ciclos->closeIfNeeded($movimiento->user);
        $this->asegurarEditable($movimiento);
        unset($data['compromiso_id']); // un pago no se puede reasignar a otro compromiso

        return DB::transaction(function () use ($movimiento, $data) {
            $this->asegurarSaldo($movimiento->user, $this->efecto($data), $movimiento->id);
            if ($movimiento->compromiso) {
                $this->exigirEgreso($data);
                $this->abonar($movimiento->compromiso, (float) $data['monto'] - (float) $movimiento->monto);
            }
            $movimiento->update($data);

            return $movimiento;
        });
    }

    public function eliminar(MovimientoEfectivo $movimiento): void
    {
        $this->asegurarEditable($movimiento);
        if ($movimiento->tipo === 'ingreso') {
            $this->asegurarSaldo($movimiento->user, 0, $movimiento->id, 'No puedes eliminar este ingreso: el efectivo quedaría en negativo.');
        }

        DB::transaction(function () use ($movimiento) {
            // Si era el pago de un compromiso, ese monto vuelve a quedar pendiente
            if ($movimiento->compromiso) {
                $this->abonar($movimiento->compromiso, -(float) $movimiento->monto);
            }
            $movimiento->delete();
        });
    }

    /** Solo se editan movimientos del ciclo actual; el saldo arrastrado lo genera el corte. */
    public function esEditable(MovimientoEfectivo $movimiento, ?Carbon $inicioCicloActual = null): bool
    {
        $inicio = $inicioCicloActual ?? $this->ciclos->cicloConsultado($movimiento->user)[0];

        return ! $movimiento->es_saldo_arrastrado && $movimiento->fecha->toDateString() >= $inicio->toDateString();
    }

    /**
     * Movimientos del período [$inicio, $fin] con filtros: search, monto, desde, hasta (Carbon),
     * tipo, categoria, sort y direction. Las fechas de filtro se recortan al período.
     */
    public function consultar(User $user, Carbon $inicio, Carbon $fin, array $filtros = []): Builder
    {
        $desde = isset($filtros['desde']) ? $filtros['desde']->max($inicio) : $inicio;
        $hasta = isset($filtros['hasta']) ? $filtros['hasta']->min($fin) : $fin;
        $search = $filtros['search'] ?? null;
        $monto = $filtros['monto'] ?? null;
        $sort = in_array($filtros['sort'] ?? null, ['descripcion', 'monto', 'fecha', 'tipo'], true) ? $filtros['sort'] : 'fecha';

        return MovimientoEfectivo::with('categoria')->where('user_id', $user->id)
            ->when($search !== null && $search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('descripcion', 'like', "%{$search}%")->orWhere('monto', 'like', "%{$search}%")))
            ->when($monto !== null && $monto !== '', fn ($q) => $q->where('monto', 'like', '%'.$monto.'%'))
            ->whereBetween('fecha', [$desde->toDateString(), $hasta->toDateString()])
            ->when(! empty($filtros['tipo']), fn ($q) => $q->where('tipo', $filtros['tipo']))
            ->when(! empty($filtros['categoria']), fn ($q) => $q->where('categoria_personal_id', $filtros['categoria']))
            ->orderBy($sort, ($filtros['direction'] ?? null) === 'asc' ? 'asc' : 'desc')
            ->orderBy('id', 'desc');
    }

    /** @return array{ingresos: float, egresos: float, saldo: float} */
    public function resumen(User $user, Carbon $inicio, Carbon $fin): array
    {
        $totales = $user->movimientosEfectivo()
            ->whereBetween('fecha', [$inicio->toDateString(), $fin->toDateString()])
            ->selectRaw("COALESCE(SUM(CASE WHEN tipo = 'ingreso' THEN monto ELSE 0 END), 0) ingresos")
            ->selectRaw("COALESCE(SUM(CASE WHEN tipo = 'egreso' THEN monto ELSE 0 END), 0) egresos")
            ->first();

        return [
            'ingresos' => (float) $totales->ingresos,
            'egresos' => (float) $totales->egresos,
            'saldo' => $this->ciclos->balance($user, $fin),
        ];
    }

    private function asegurarEditable(MovimientoEfectivo $movimiento): void
    {
        abort_unless($this->esEditable($movimiento), 403, 'Solo se pueden modificar movimientos del ciclo actual.');
    }

    /** El efectivo no puede quedar en negativo tras aplicar $delta (sin contar $exceptId). */
    private function asegurarSaldo(User $user, float $delta, ?int $exceptId = null, string $message = 'El egreso supera el saldo disponible.'): void
    {
        if (round($this->ciclos->balance($user, null, $exceptId) + $delta, 2) < 0) {
            throw ValidationException::withMessages(['monto' => $message]);
        }
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

    private function abonar(Compromiso $compromiso, float $monto): void
    {
        try {
            $this->compromisos->aplicarAbono($compromiso, $monto);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['monto' => $e->getMessage()]);
        }
    }
}
