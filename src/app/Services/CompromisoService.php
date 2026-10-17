<?php

namespace App\Services;

use App\Models\Compromiso;
use App\Models\CompromisoRecurrente;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class CompromisoService
{
    public function __construct(private readonly CicloEfectivoService $ciclos) {}

    /** Crea un compromiso de una sola vez. */
    public function crearUnico(User $user, array $data): Compromiso
    {
        $fecha = isset($data['fecha_esperada']) ? Carbon::parse($data['fecha_esperada']) : null;

        return $user->compromisos()->create(array_merge($data, [
            'saldo_pendiente' => $data['monto'],
            'ciclo_inicio' => $this->ciclos->cycleFor($user, $fecha ?? $this->hoy($user))[0],
            'estado' => Compromiso::PENDIENTE,
        ]));
    }

    /** Crea la regla recurrente y genera de inmediato los pagos del ciclo actual. */
    public function crearRecurrente(User $user, array $data): CompromisoRecurrente
    {
        return DB::transaction(function () use ($user, $data) {
            $recurrente = $user->compromisosRecurrentes()->create($data);
            $this->generar($recurrente, $this->ciclos->cycleFor($user)[1]);

            return $recurrente;
        });
    }

    /**
     * Genera los pagos pendientes de todas las reglas activas hasta el fin del ciclo actual
     * (o hasta $hasta). Es idempotente: cada regla recuerda hasta qué fecha ya generó.
     */
    public function sincronizar(User $user, ?Carbon $hasta = null): void
    {
        $hasta ??= $this->ciclos->cycleFor($user)[1];
        foreach ($user->compromisosRecurrentes()->where('activo', true)->get() as $recurrente) {
            $this->generar($recurrente, $hasta);
        }
    }

    public function generar(CompromisoRecurrente $recurrente, Carbon $hasta): int
    {
        $hasta = $this->soloFecha($hasta);

        return DB::transaction(function () use ($recurrente, $hasta) {
            $recurrente = CompromisoRecurrente::whereKey($recurrente->id)->lockForUpdate()->first();
            $desde = $recurrente->generado_hasta ?? $recurrente->fecha_inicio->copy()->subDay();
            $user = $recurrente->user;
            $creados = 0;

            foreach ($this->fechasDePago($recurrente, $desde, $hasta) as $fecha) {
                if ($recurrente->fecha_fin && $fecha->gt($recurrente->fecha_fin)) {
                    $recurrente->activo = false;
                    break;
                }
                if ($recurrente->total_cuotas && $recurrente->cuotas_generadas >= $recurrente->total_cuotas) {
                    $recurrente->activo = false;
                    break;
                }

                $recurrente->cuotas_generadas++;
                $user->compromisos()->create([
                    'compromiso_recurrente_id' => $recurrente->id,
                    'categoria_personal_id' => $recurrente->categoria_personal_id,
                    'descripcion' => $recurrente->descripcion,
                    'comentario' => $recurrente->comentario,
                    'monto' => $recurrente->monto,
                    'saldo_pendiente' => $recurrente->monto,
                    'fecha_esperada' => $fecha->toDateString(),
                    'ciclo_inicio' => $this->ciclos->cycleFor($user, $fecha)[0],
                    'numero_cuota' => $recurrente->cuotas_generadas,
                    'total_cuotas' => $recurrente->total_cuotas,
                    'medio_pago' => $recurrente->medio_pago,
                    'estado' => Compromiso::PENDIENTE,
                ]);
                $creados++;
            }

            if ($recurrente->total_cuotas && $recurrente->cuotas_generadas >= $recurrente->total_cuotas) {
                $recurrente->activo = false;
            }
            if ($recurrente->fecha_fin && $hasta->gte($recurrente->fecha_fin)) {
                $recurrente->activo = false;
            }
            if ($hasta->gt($desde)) {
                $recurrente->generado_hasta = $hasta->toDateString();
            }
            $recurrente->save();

            return $creados;
        });
    }

    /**
     * Fechas de pago en el rango ($desde, $hasta]. El día 31 (ULTIMO_DIA) o cualquier día
     * que el mes no tenga se ajusta al último día del mes (ej. 28 de febrero).
     *
     * @return Carbon[]
     */
    public function fechasDePago(CompromisoRecurrente $recurrente, Carbon $desde, Carbon $hasta): array
    {
        $desde = $this->soloFecha($desde);
        $hasta = $this->soloFecha($hasta);
        $fechas = [];

        if ($recurrente->frecuencia === 'semanal') {
            $fecha = $desde->copy()->addDay();
            $fecha->addDays(($recurrente->dia_semana - $fecha->dayOfWeek + 7) % 7);
            for (; $fecha->lte($hasta); $fecha->addWeek()) {
                $fechas[] = $fecha->copy();
            }

            return $fechas;
        }

        $dias = array_filter([$recurrente->dia_pago, $recurrente->frecuencia === 'quincenal' ? $recurrente->dia_pago_2 : null]);
        for ($mes = $desde->copy()->startOfMonth(); $mes->lte($hasta); $mes->addMonthNoOverflow()) {
            $delMes = array_unique(array_map(fn (int $dia) => $mes->copy()->day(min($dia, $mes->daysInMonth))->toDateString(), $dias));
            sort($delMes);
            foreach ($delMes as $dia) {
                $fecha = Carbon::parse($dia);
                if ($fecha->gt($desde) && $fecha->lte($hasta)) {
                    $fechas[] = $fecha;
                }
            }
        }

        return $fechas;
    }

    /**
     * Aplica un abono (positivo) o revierte uno (negativo, al editar o eliminar el egreso).
     * Debe llamarse dentro de una transacción.
     *
     * @throws \InvalidArgumentException si el abono supera el saldo pendiente
     */
    public function aplicarAbono(Compromiso $compromiso, float $monto): void
    {
        $compromiso = Compromiso::whereKey($compromiso->id)->lockForUpdate()->firstOrFail();
        $saldo = round((float) $compromiso->saldo_pendiente - $monto, 2);
        if ($saldo < 0) {
            throw new \InvalidArgumentException('El pago supera el saldo pendiente del compromiso.');
        }

        $compromiso->update([
            'saldo_pendiente' => min($saldo, (float) $compromiso->monto),
            'estado' => match (true) {
                $compromiso->estado === Compromiso::CANCELADO => Compromiso::CANCELADO,
                $saldo <= 0 => Compromiso::PAGADO,
                default => Compromiso::PENDIENTE,
            },
        ]);
    }

    /**
     * Cancela un pago. Con $serie = true también cancela los pagos futuros sin abonos
     * y desactiva la regla; los pagados o con abonos se conservan.
     */
    public function cancelar(Compromiso $compromiso, bool $serie = false): void
    {
        DB::transaction(function () use ($compromiso, $serie) {
            $this->cancelarSiPuede($compromiso);

            if ($serie && $compromiso->recurrente) {
                $compromiso->recurrente->update(['activo' => false]);
                $compromiso->recurrente->compromisos()
                    ->where('estado', Compromiso::PENDIENTE)
                    ->whereColumn('saldo_pendiente', 'monto')
                    ->where(fn ($q) => $q->whereNull('fecha_esperada')->orWhereDate('fecha_esperada', '>=', $compromiso->fecha_esperada ?? $this->hoy($compromiso->user)))
                    ->update(['estado' => Compromiso::CANCELADO]);
            }
        });
    }

    private function cancelarSiPuede(Compromiso $compromiso): void
    {
        if ($compromiso->estado === Compromiso::PENDIENTE) {
            $compromiso->update(['estado' => Compromiso::CANCELADO]);
        }
    }

    /** Quita hora y zona horaria: las comparaciones se hacen por día de calendario. */
    private function soloFecha(Carbon $fecha): Carbon
    {
        return Carbon::parse($fecha->toDateString());
    }

    private function hoy(User $user): Carbon
    {
        return Carbon::today($user->zona_horaria ?: config('app.timezone'));
    }
}
