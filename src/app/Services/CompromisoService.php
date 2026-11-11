<?php

namespace App\Services;

use App\Models\Compromiso;
use App\Models\CompromisoRecurrente;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CompromisoService
{
    public function __construct(private readonly CicloEfectivoService $ciclos, private readonly PresupuestoService $presupuestos) {}

    /**
     * Lista de compromisos con filtros: search, estado (visual), periodo (actual|proximos|todos),
     * sort y direction. "actual" incluye lo pendiente de ciclos anteriores (Antiguo).
     */
    public function consultar(User $user, array $filtros = []): Builder
    {
        [$hoy, $inicio, $fin] = $this->fechas($user);
        $sort = in_array($filtros['sort'] ?? null, ['descripcion', 'monto', 'saldo_pendiente', 'fecha_esperada', 'estado'], true) ? $filtros['sort'] : 'estado';
        $direction = ($filtros['direction'] ?? null) === 'desc' ? 'desc' : 'asc';
        $periodo = ($filtros['periodo'] ?? null) ?: 'actual';
        $search = $filtros['search'] ?? null;
        $estado = $filtros['estado'] ?? null;

        return Compromiso::query()->where('hogar_id', $user->hogar_id)->with('categoria', 'recurrente', 'contacto')
            ->when($search, fn ($q) => $q->where('descripcion', 'like', '%'.$search.'%'))
            ->when($estado, fn ($q) => $q->estadoVisual($estado, $hoy, $inicio))
            ->when($periodo === 'actual', fn ($q) => $q
                ->whereDate('ciclo_inicio', '<=', $fin->toDateString())
                ->where(fn ($q) => $q->whereDate('ciclo_inicio', '>=', $inicio->toDateString())->orWhere('estado', Compromiso::PENDIENTE)))
            ->when($periodo === 'proximos', fn ($q) => $q->whereDate('ciclo_inicio', '>', $fin->toDateString()))
            // Estado: lo que urge primero (antiguo, vencido), luego lo pendiente, lo pagado y lo cancelado; dentro, por fecha
            ->when($sort === 'estado', fn ($q) => $q->orderByRaw(
                "CASE WHEN estado = 'pendiente' AND ciclo_inicio < ? THEN 0 WHEN estado = 'pendiente' AND es_presupuesto = ? AND fecha_esperada < ? THEN 1 WHEN estado = 'pendiente' THEN 2 WHEN estado = 'pagado' THEN 3 ELSE 4 END ".$direction,
                [$inicio->toDateString(), false, $hoy->toDateString()]))
            ->orderByRaw('fecha_esperada IS NULL')
            ->orderBy($sort === 'estado' ? 'fecha_esperada' : $sort, $sort === 'estado' ? 'asc' : $direction)
            ->orderBy('id');
    }

    /** @return array{0: Carbon, 1: Carbon, 2: Carbon} hoy, inicio y fin del ciclo actual del usuario */
    public function fechas(User $user): array
    {
        [$inicio, $fin] = $this->ciclos->cycleFor($user);

        return [$this->hoy($user), $inicio, $fin];
    }

    /** Crea un compromiso de una sola vez. */
    public function crearUnico(User $user, array $data): Compromiso
    {
        $fecha = isset($data['fecha_esperada']) ? Carbon::parse($data['fecha_esperada']) : null;

        return $user->compromisos()->create(array_merge(['user_id' => $user->id], $data, [
            'saldo_pendiente' => $data['monto'],
            'ciclo_inicio' => $this->ciclos->cycleFor($user, $fecha ?? $this->hoy($user))[0],
            'estado' => Compromiso::PENDIENTE,
        ]));
    }

    /** Crea la regla recurrente y genera de inmediato los pagos del ciclo actual. */
    public function crearRecurrente(User $user, array $data): CompromisoRecurrente
    {
        return DB::transaction(function () use ($user, $data) {
            $recurrente = $user->compromisosRecurrentes()->create($data + ['user_id' => $user->id]);
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
        $this->presupuestos->sincronizar($user);
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
                    'user_id' => $recurrente->user_id,
                    'contacto_id' => $recurrente->contacto_id,
                    'compromiso_recurrente_id' => $recurrente->id,
                    'categoria_personal_id' => $recurrente->categoria_personal_id,
                    'descripcion' => $recurrente->descripcion,
                    'comentario' => $recurrente->comentario,
                    'monto' => $recurrente->monto,
                    'saldo_pendiente' => $recurrente->monto,
                    // Un presupuesto "vence" al cerrar su ciclo
                    'fecha_esperada' => ($recurrente->es_presupuesto ? $this->ciclos->cycleFor($user, $fecha)[1] : $fecha)->toDateString(),
                    'ciclo_inicio' => $this->ciclos->cycleFor($user, $fecha)[0],
                    'es_presupuesto' => $recurrente->es_presupuesto,
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
     * Edita la regla de una serie y la aplica a sus pagos futuros que aún no se tocaron (pendientes, sin abonos,
     * desde hoy). Los pagados, abonados o cancelados no cambian. Si cambia el calendario (frecuencia o días), esos
     * pagos futuros se vuelven a generar con las fechas nuevas. Devuelve cuántos pagos futuros quedaron actualizados.
     */
    public function actualizarSerie(CompromisoRecurrente $recurrente, array $data): int
    {
        return DB::transaction(function () use ($recurrente, $data) {
            $recurrente = CompromisoRecurrente::whereKey($recurrente->id)->lockForUpdate()->first();
            $user = $recurrente->user;
            $hoy = $this->hoy($user);
            $calendario = ['frecuencia', 'dia_semana', 'dia_pago', 'dia_pago_2'];
            $cambiaCalendario = collect($calendario)->contains(fn ($c) => (string) ($recurrente->{$c} ?? '') !== (string) ($data[$c] ?? ''));

            $recurrente->fill($data);
            $futuros = fn () => $recurrente->compromisos()->where('estado', Compromiso::PENDIENTE)->whereColumn('saldo_pendiente', 'monto')
                ->whereDate('fecha_esperada', '>=', $hoy->toDateString());

            // Calendario nuevo, fin más cercano o menos cuotas: los pagos futuros sin tocar se quitan y se regeneran
            $quitar = $cambiaCalendario ? $futuros()->get()
                : $futuros()->get()->filter(fn ($c) => $recurrente->fecha_fin && $c->fecha_esperada->toDateString() > $recurrente->fecha_fin->toDateString());
            if (! $cambiaCalendario && $recurrente->total_cuotas && $recurrente->cuotas_generadas > $recurrente->total_cuotas) {
                $quitar = $quitar->concat($futuros()->orderByDesc('numero_cuota')->take($recurrente->cuotas_generadas - $recurrente->total_cuotas)->get())->unique('id');
            }
            if ($quitar->isNotEmpty()) {
                Compromiso::whereIn('id', $quitar->pluck('id'))->delete();
                $recurrente->cuotas_generadas = max(0, $recurrente->cuotas_generadas - $quitar->count());
            }
            if ($cambiaCalendario) {
                $ultimo = $recurrente->compromisos()->max('fecha_esperada');
                $desde = $hoy->copy()->subDay();
                // Un pago quitado de este ciclo se repone aunque su fecha nueva ya pasó (queda vencido): no salta al siguiente ciclo
                [$inicio, $fin] = $this->ciclos->cycleFor($user);
                $faltan = $quitar->filter(fn ($c) => $c->ciclo_inicio?->toDateString() === $inicio->toDateString())->count()
                    - count($this->fechasDePago($recurrente, $desde, $fin));
                $pasadas = $this->fechasDePago($recurrente, $inicio->copy()->subDay(), $desde);
                if ($faltan > 0 && $pasadas) {
                    $desde = $pasadas[max(0, count($pasadas) - $faltan)]->copy()->subDay();
                }
                $recurrente->generado_hasta = max($desde->toDateString(), $ultimo ? Carbon::parse($ultimo)->toDateString() : $recurrente->fecha_inicio->copy()->subDay()->toDateString());
            }

            // Sigue viva si no llegó a su fin (pudo extenderse)
            $recurrente->activo = ! ($recurrente->fecha_fin && $recurrente->fecha_fin->toDateString() < $hoy->toDateString())
                && ! ($recurrente->total_cuotas && $recurrente->cuotas_generadas >= $recurrente->total_cuotas);
            $recurrente->save();

            // Lo demás (monto, descripción, categoría…) se aplica a los pagos futuros sin tocar
            $actualizados = $futuros()->update([
                'user_id' => $recurrente->user_id, 'contacto_id' => $recurrente->contacto_id, 'categoria_personal_id' => $recurrente->categoria_personal_id,
                'descripcion' => $recurrente->descripcion, 'comentario' => $recurrente->comentario, 'monto' => $recurrente->monto,
                'saldo_pendiente' => $recurrente->monto, 'medio_pago' => $recurrente->medio_pago, 'total_cuotas' => $recurrente->total_cuotas,
            ]);
            // Presupuesto (o deja de serlo): sus pagos abiertos se ajustan aunque ya tengan gastos de su categoría
            if ($recurrente->es_presupuesto || $recurrente->wasChanged('es_presupuesto')) {
                foreach ($recurrente->compromisos()->where('estado', Compromiso::PENDIENTE)->get() as $abierto) {
                    $datos = ['es_presupuesto' => $recurrente->es_presupuesto, 'categoria_personal_id' => $recurrente->categoria_personal_id,
                        'descripcion' => $recurrente->descripcion, 'monto' => $recurrente->monto, 'user_id' => $recurrente->user_id];
                    $datos += $recurrente->es_presupuesto
                        ? ['fecha_esperada' => $this->ciclos->cycleFor($user, $abierto->ciclo_inicio ?? $abierto->fecha_esperada)[1]->toDateString()]
                        : ['saldo_pendiente' => $recurrente->monto];
                    $abierto->update($datos);
                    $this->presupuestos->recalcular($abierto);
                }
            }
            if ($recurrente->activo) {
                $actualizados += $this->generar($recurrente, $this->ciclos->cycleFor($user)[1]);
            }

            return $actualizados;
        });
    }

    /**
     * El pago que ocupa el lugar de $compromiso tras editar su serie: él mismo, o si se regeneró, el de la serie
     * en su mismo ciclo (o el siguiente pendiente). Null si no queda ninguno.
     */
    public function pagoVigente(Compromiso $compromiso): ?Compromiso
    {
        if (Compromiso::whereKey($compromiso->id)->exists()) {
            return $compromiso->fresh();
        }
        $serie = Compromiso::where('compromiso_recurrente_id', $compromiso->compromiso_recurrente_id);

        return ($compromiso->ciclo_inicio ? (clone $serie)->whereDate('ciclo_inicio', $compromiso->ciclo_inicio->toDateString())->orderBy('fecha_esperada')->first() : null)
            ?? $serie->where('estado', Compromiso::PENDIENTE)->orderBy('fecha_esperada')->first();
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
     * Edita un pago pendiente (no su serie). El nuevo monto no puede ser menor a lo ya abonado.
     */
    public function actualizarPago(Compromiso $compromiso, array $data): Compromiso
    {
        abort_unless($compromiso->estado === Compromiso::PENDIENTE, 403, 'Solo se pueden editar compromisos pendientes.');
        $pagado = $compromiso->montoPagado();
        if ((float) $data['monto'] < $pagado) {
            throw ValidationException::withMessages(['monto' => 'El monto no puede ser menor a lo ya pagado ('.number_format($pagado, 2).').']);
        }

        $fecha = isset($data['fecha_esperada']) ? Carbon::parse($data['fecha_esperada']) : null;
        $compromiso->update(array_merge($data, [
            'saldo_pendiente' => round((float) $data['monto'] - $pagado, 2),
            'ciclo_inicio' => $fecha ? $this->ciclos->cycleFor($compromiso->user, $fecha)[0] : $compromiso->ciclo_inicio,
        ]));

        return $compromiso;
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

    /** Igual que aplicarAbono(), pero informa el error como validación del campo "monto". */
    public function abonar(Compromiso $compromiso, float $monto): void
    {
        if ($compromiso->es_presupuesto) {
            throw ValidationException::withMessages(['monto' => 'Un presupuesto no se paga: se descuenta solo al registrar gastos de su categoría.']);
        }
        try {
            $this->aplicarAbono($compromiso, $monto);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['monto' => $e->getMessage()]);
        }
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
