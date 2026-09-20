<?php

namespace App\Services;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class CicloEfectivoService
{
    public function closeIfNeeded(User $user): void
    {
        DB::transaction(function () use ($user) {
            $category = $user->categoriasPersonales()->where('nombre', 'Saldo inicial')->first();
            $initial = $category?->movimientos()->where('tipo', 'ingreso')->oldest('fecha')->first();
            if (! $category || ! $initial) {
                return;
            }

            $timezone = $user->zona_horaria ?: config('app.timezone');
            $today = Carbon::today($timezone);
            $lastStart = $user->movimientosEfectivo()->where('categoria_personal_id', $category->id)->max('fecha');
            $lastStart = Carbon::parse($lastStart, $timezone);
            $cutoff = min((int) ($user->fecha_corte_dia ?: 31), $lastStart->daysInMonth);
            $cycleEnd = $lastStart->copy()->day($cutoff);

            if ($today->lte($cycleEnd)) {
                return;
            }

            $nextStart = $cycleEnd->copy()->addDay();
            $balance = (float) $user->movimientosEfectivo()
                ->selectRaw("COALESCE(SUM(CASE WHEN tipo = 'ingreso' THEN monto ELSE -monto END), 0) balance")
                ->value('balance');

            if (! $user->movimientosEfectivo()->where('categoria_personal_id', $category->id)->whereDate('fecha', $nextStart)->exists()) {
                $user->movimientosEfectivo()->create([
                    'categoria_personal_id' => $category->id,
                    'descripcion' => 'Saldo inicial',
                    'monto' => max(0, $balance),
                    'fecha' => $nextStart->toDateString(),
                    'tipo' => 'ingreso',
                ]);
            }

            $this->generateRecurringCommitments($user, $nextStart);
        });
    }

    private function generateRecurringCommitments(User $user, Carbon $cycleStart): void
    {
        foreach ($user->compromisos()->where('estado', 'activo')->where('recurrencia_tipo', 'recurrente')->get() as $commitment) {
            if ($commitment->recurrencia_ocurrencias !== null && $commitment->cuotas_pagadas >= $commitment->recurrencia_ocurrencias) {
                continue;
            }

            $date = $this->nextDate($commitment, $cycleStart);
            if ($date->greaterThan($cycleStart)) {
                continue;
            }

            $exists = $user->compromisos()->where('descripcion', $commitment->descripcion)
                ->whereDate('fecha_esperada', $date)->where('recurrencia_tipo', 'unica')->exists();
            if (! $exists) {
                $user->compromisos()->create([
                    'descripcion' => $commitment->descripcion,
                    'monto' => $commitment->monto,
                    'saldo_pendiente' => $commitment->monto,
                    'fecha_esperada' => $date,
                    'medio_pago' => $commitment->medio_pago,
                    'recurrencia_tipo' => 'unica',
                    'estado' => 'activo',
                ]);
            }
            $commitment->update(['fecha_esperada' => $date]);
        }
    }

    private function nextDate($commitment, Carbon $cycleStart): Carbon
    {
        if (! $commitment->fecha_esperada) {
            return $cycleStart->copy();
        }
        $date = Carbon::parse($commitment->fecha_esperada);

        return match ($commitment->recurrencia_unidad) {
            'semanal' => $date->copy()->addWeek(),
            'quincenal' => $date->copy()->addDays(15),
            default => $date->copy()->addMonthNoOverflow(),
        };
    }
}
