<?php

namespace App\Services;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class CicloEfectivoService
{
    public const CATEGORIA_SALDO_INICIAL = 'Saldo inicial';

    /**
     * Devuelve [inicio, fin] del ciclo que contiene la fecha indicada (hoy por defecto).
     * El fin es el día de corte del usuario, ajustado al último día en meses más cortos.
     */
    public function cycleFor(User $user, ?Carbon $date = null): array
    {
        $timezone = $this->timezone($user);
        $date = ($date ?? Carbon::now($timezone))->copy()->startOfDay();
        $cutoff = $this->cutoffDay($user);

        $end = $this->cutoffDate($date, $cutoff);
        if ($date->greaterThan($end)) {
            $end = $this->cutoffDate($date->copy()->startOfMonth()->addMonthNoOverflow(), $cutoff);
        }
        $start = $this->cutoffDate($end->copy()->startOfMonth()->subMonthNoOverflow(), $cutoff)->addDay();

        return [$start, $end];
    }

    /**
     * Efectivo real del usuario. Excluye los saldos arrastrados por el corte,
     * que solo informan el saldo con el que abre cada ciclo.
     */
    public function balance(User $user, ?Carbon $until = null, ?int $exceptId = null): float
    {
        return (float) $user->movimientosEfectivo()
            ->where('es_saldo_arrastrado', false)
            ->when($until, fn ($q) => $q->whereDate('fecha', '<=', $until->toDateString()))
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))
            ->selectRaw("COALESCE(SUM(CASE WHEN tipo = 'ingreso' THEN monto ELSE -monto END), 0) balance")
            ->value('balance');
    }

    public function closeIfNeeded(User $user): void
    {
        DB::transaction(function () use ($user) {
            $category = $user->categoriasPersonales()->where('nombre', self::CATEGORIA_SALDO_INICIAL)->first();
            $lastStart = $category?->movimientos()->where('tipo', 'ingreso')->max('fecha');
            if (! $lastStart) {
                return;
            }

            $timezone = $this->timezone($user);
            $today = Carbon::today($timezone);
            [, $cycleEnd] = $this->cycleFor($user, Carbon::parse($lastStart, $timezone));

            // Un usuario ausente varios meses debe cerrar cada ciclo pendiente, no solo uno.
            while ($today->greaterThan($cycleEnd)) {
                $nextStart = $cycleEnd->copy()->addDay();

                if (! $category->movimientos()->where('es_saldo_arrastrado', true)->whereDate('fecha', $nextStart)->exists()) {
                    $user->movimientosEfectivo()->create([
                        'categoria_personal_id' => $category->id,
                        'descripcion' => self::CATEGORIA_SALDO_INICIAL,
                        'monto' => max(0, $this->balance($user, $cycleEnd)),
                        'fecha' => $nextStart->toDateString(),
                        'tipo' => 'ingreso',
                        'es_saldo_arrastrado' => true,
                    ]);
                }

                [, $cycleEnd] = $this->cycleFor($user, $nextStart);
            }

            app(CompromisoService::class)->sincronizar($user, $this->cycleFor($user, $today)[1]);
        });
    }

    private function cutoffDate(Carbon $month, int $cutoff): Carbon
    {
        return $month->copy()->day(min($cutoff, $month->daysInMonth))->startOfDay();
    }

    private function cutoffDay(User $user): int
    {
        return max(1, min((int) ($user->fecha_corte_dia ?: 31), 31));
    }

    private function timezone(User $user): string
    {
        return $user->zona_horaria ?: config('app.timezone');
    }
}
