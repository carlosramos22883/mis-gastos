<?php

namespace App\Services;

use App\Models\Compromiso;
use App\Models\Hogar;
use App\Models\MovimientoCuenta;
use App\Models\MovimientoEfectivo;
use App\Models\MovimientoTarjeta;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Presupuestos (súper, gasolina…): compromisos con es_presupuesto = true que no se pagan. Lo pendiente es lo que
 * queda del monto después de los egresos de su categoría en su ciclo (efectivo, cuentas y compras o cargos con
 * tarjeta) y de los compromisos normales pendientes de esa categoría en el ciclo (la cuota de Gaby ya reserva su
 * parte: no se cuenta dos veces). Nunca vencen: al terminar el ciclo se cierran (estado pagado). Se recalculan al
 * guardar o borrar un movimiento o un compromiso (AppServiceProvider) y al sincronizar los compromisos.
 */
class PresupuestoService
{
    public function __construct(private readonly CicloEfectivoService $ciclos) {}

    /** Lo gastado en la categoría del presupuesto durante su ciclo. */
    public function gastado(Compromiso $presupuesto): float
    {
        return round((float) $this->movimientos($presupuesto)->sum('monto'), 2);
    }

    /**
     * Los egresos que descuentan el presupuesto (de efectivo, cuentas y tarjetas), del más reciente al más antiguo.
     * Cada uno trae "clase" (efectivo | cuenta | tarjeta) y "origen" (dónde se hizo).
     *
     * @return Collection<int, Model>
     */
    public function movimientos(Compromiso $presupuesto): Collection
    {
        if (! $presupuesto->categoria_personal_id || ! $presupuesto->ciclo_inicio) {
            return collect();
        }
        [$inicio, $fin] = $this->ciclos->cycleFor($presupuesto->user, $presupuesto->ciclo_inicio);
        $filtro = fn ($q) => $q->where('hogar_id', $presupuesto->hogar_id)->where('categoria_personal_id', $presupuesto->categoria_personal_id)
            ->whereDate('fecha', '>=', $inicio->toDateString())->whereDate('fecha', '<=', $fin->toDateString());

        return collect()
            ->concat($filtro(MovimientoEfectivo::query())->where('tipo', 'egreso')->whereNull('transferencia_id')->get()
                ->each(fn ($m) => $m->setAttribute('clase', 'efectivo')->setAttribute('origen', 'Efectivo')))
            ->concat($filtro(MovimientoCuenta::query())->with('cuenta')->where('tipo', 'egreso')->whereNull('transferencia_id')->get()
                ->each(fn ($m) => $m->setAttribute('clase', 'cuenta')->setAttribute('origen', $m->cuenta?->nombre ?? 'Cuenta')))
            ->concat($filtro(MovimientoTarjeta::query())->with('tarjeta')->whereIn('tipo', ['compra', 'cargo'])->get()
                ->each(fn ($m) => $m->setAttribute('clase', 'tarjeta')->setAttribute('origen', 'Tarjeta '.($m->tarjeta?->nombre ?? ''))))
            ->sortByDesc(fn ($m) => $m->fecha->toDateString().'-'.str_pad((string) $m->id, 10, '0', STR_PAD_LEFT))->values();
    }

    /** Lo que aún se debe pagar este ciclo en compromisos normales de la misma categoría (ya está reservado). */
    public function comprometido(Compromiso $presupuesto): float
    {
        if (! $presupuesto->categoria_personal_id || ! $presupuesto->ciclo_inicio) {
            return 0.0;
        }

        return round((float) Compromiso::where('hogar_id', $presupuesto->hogar_id)->where('es_presupuesto', false)->where('estado', Compromiso::PENDIENTE)
            ->where('categoria_personal_id', $presupuesto->categoria_personal_id)->whereDate('ciclo_inicio', $presupuesto->ciclo_inicio->toDateString())
            ->sum('saldo_pendiente'), 2);
    }

    /** @return array{monto: float, gastado: float, comprometido: float, queda: float} queda < 0 = se pasó */
    public function resumen(Compromiso $presupuesto): array
    {
        $gastado = $this->gastado($presupuesto);
        $comprometido = $this->comprometido($presupuesto);

        return ['monto' => (float) $presupuesto->monto, 'gastado' => $gastado, 'comprometido' => $comprometido,
            'queda' => round((float) $presupuesto->monto - $gastado - $comprometido, 2)];
    }

    /** Ajusta lo que queda del presupuesto; si su ciclo ya terminó, lo cierra. */
    public function recalcular(Compromiso $presupuesto, ?Carbon $inicioActual = null): void
    {
        if (! $presupuesto->es_presupuesto || $presupuesto->estado === Compromiso::CANCELADO) {
            return;
        }
        $inicioActual ??= $this->ciclos->cycleFor($presupuesto->user)[0];
        $queda = max(0, $this->resumen($presupuesto)['queda']);
        $cerrado = $presupuesto->ciclo_inicio && $presupuesto->ciclo_inicio->toDateString() < $inicioActual->toDateString();
        $presupuesto->update(['saldo_pendiente' => $queda, 'estado' => $cerrado ? Compromiso::PAGADO : Compromiso::PENDIENTE]);
    }

    /** Recalcula los presupuestos abiertos del hogar (o los de un ciclo y categorías). */
    public function sincronizar(User $user): void
    {
        $inicio = $this->ciclos->cycleFor($user)[0];
        Compromiso::where('hogar_id', $user->hogar_id)->where('es_presupuesto', true)->where('estado', Compromiso::PENDIENTE)->get()
            ->each(fn (Compromiso $c) => $this->recalcular($c, $inicio));
    }

    /** Un movimiento o un compromiso normal cambió: se recalculan los presupuestos de su categoría (la de antes y la de ahora). */
    public function alCambiarMovimiento(Model $movimiento): void
    {
        if ($movimiento instanceof Compromiso && $movimiento->es_presupuesto) {
            return; // el propio presupuesto se guardó al recalcularse
        }
        $categorias = array_filter([$movimiento->categoria_personal_id, $movimiento->getOriginal('categoria_personal_id')]);
        if (! $categorias || ! $movimiento->hogar_id) {
            return;
        }
        $presupuestos = Compromiso::where('hogar_id', $movimiento->hogar_id)->where('es_presupuesto', true)
            ->whereIn('categoria_personal_id', $categorias)->whereIn('estado', [Compromiso::PENDIENTE, Compromiso::PAGADO])
            ->whereDate('ciclo_inicio', '>=', now()->subMonths(3)->toDateString())->get();
        foreach ($presupuestos as $c) {
            DB::afterCommit(fn () => $this->recalcular($c->fresh()));
        }
    }

    /**
     * Gasto del ciclo frente al tope del hogar: gastado, lo que queda, cuánto se puede gastar por día los días que
     * faltan y en qué porcentaje va. null si el hogar no tiene tope.
     *
     * @return array{tope: float, gastado: float, queda: float, por_dia: float, porcentaje: int, dias_restantes: int}|null
     */
    public function tope(Hogar $hogar, float $gastado, int $diasRestantes): ?array
    {
        $tope = (float) $hogar->tope_gasto_ciclo;
        if ($tope <= 0) {
            return null;
        }
        $queda = round($tope - $gastado, 2);

        return ['tope' => $tope, 'gastado' => round($gastado, 2), 'queda' => $queda,
            'por_dia' => $queda > 0 ? round($queda / max(1, $diasRestantes + 1), 2) : 0.0,
            'porcentaje' => (int) round($gastado / $tope * 100), 'dias_restantes' => $diasRestantes];
    }
}
