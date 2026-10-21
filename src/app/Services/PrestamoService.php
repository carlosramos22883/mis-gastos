<?php

namespace App\Services;

use App\Models\CategoriaPersonal;
use App\Models\Compromiso;
use App\Models\MovimientoCuenta;
use App\Models\MovimientoEfectivo;
use App\Models\Prestamo;
use App\Models\PrestamoPago;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Préstamos que debe el hogar.
 *
 *  - Saldo = saldo inicial (a fecha_saldo) − capital de los pagos.
 *  - Interés de un período = saldo × tasa anual × días / 365 (así lo cobran los bancos de El Salvador;
 *    verificado con un pago real). La cuota (capital + interés) es fija: lo que no es interés va a capital.
 *  - Los cargos (seguros, IVA) se suman a cada cuota y no reducen el saldo.
 *  - Un pago desde efectivo o una cuenta crea su egreso y abona el compromiso del mes (si el préstamo tiene
 *    uno); un pago por planilla solo se anota. Solo se elimina el último pago.
 */
class PrestamoService
{
    public const CATEGORIA = 'Préstamos y deudas';

    private const MAX_MESES = 600;

    public function __construct(
        private readonly MovimientoEfectivoService $efectivo,
        private readonly CuentaService $cuentas,
        private readonly CompromisoService $compromisos,
    ) {}

    // ------------------------------------------------------------------ estado

    public function saldo(Prestamo $prestamo): float
    {
        return round((float) $prestamo->saldo_inicial - (float) $prestamo->pagos()->sum('capital'), 2);
    }

    /** Fecha desde la que corren los intereses: el último pago o la fecha del saldo inicial. */
    public function ultimaFecha(Prestamo $prestamo): Carbon
    {
        $ultimo = $prestamo->pagos()->max('fecha');

        return Carbon::parse(max($ultimo ?? '0000-00-00', $prestamo->fecha_saldo->toDateString()));
    }

    public function cargosMensuales(Prestamo $prestamo): float
    {
        return round((float) $prestamo->cargos()->sum('monto'), 2);
    }

    /** Interés acumulado desde el último pago hasta $fecha. */
    public function interesAl(Prestamo $prestamo, Carbon $fecha, ?float $saldo = null): float
    {
        $dias = max(0, (int) $this->ultimaFecha($prestamo)->diffInDays($fecha->copy()->startOfDay(), false));

        return $this->interes($saldo ?? $this->saldo($prestamo), (float) $prestamo->tasa_anual, $dias);
    }

    /** Cuota de capital + interés: la registrada o, si no la hay, la que salda el préstamo en su plazo restante. */
    public function cuota(Prestamo $prestamo): ?float
    {
        if ($prestamo->cuota) {
            return (float) $prestamo->cuota;
        }
        $restantes = $this->mesesRestantesDelPlazo($prestamo);

        return $restantes ? $this->anualidad($this->saldo($prestamo), (float) $prestamo->tasa_anual, $restantes) : null;
    }

    /** Siguiente fecha de pago después del último pago. */
    public function proximaFecha(Prestamo $prestamo): Carbon
    {
        return $this->siguienteFecha($prestamo, $this->ultimaFecha($prestamo));
    }

    /** Desglose sugerido para pagar la cuota en $fecha. */
    public function sugerencia(Prestamo $prestamo, ?Carbon $fecha = null): array
    {
        $fecha ??= $this->hoy($prestamo->user);
        $saldo = $this->saldo($prestamo);
        $interes = $this->interesAl($prestamo, $fecha, $saldo);
        $cuota = $this->cuota($prestamo);
        $capital = $cuota ? min($saldo, max(0, round($cuota - $interes, 2))) : 0.0;
        $cargos = $saldo > 0 ? $this->cargosMensuales($prestamo) : 0.0;

        return ['fecha' => $fecha->toDateString(), 'capital' => $capital, 'interes' => $interes, 'cargos' => $cargos, 'mora' => 0.0,
            'total' => round($capital + $interes + $cargos, 2)];
    }

    /** Resumen para listas y la cabecera del detalle. */
    public function resumen(Prestamo $prestamo): array
    {
        $saldo = $this->saldo($prestamo);
        $base = $saldo > 0 ? $this->tabla($prestamo) : null;
        $pagado = $prestamo->pagos()->selectRaw('COALESCE(SUM(capital),0) capital, COALESCE(SUM(interes),0) interes, COALESCE(SUM(cargos + mora),0) cargos')->first();

        return [
            'saldo' => $saldo,
            'cuota' => $this->cuota($prestamo),
            'cargos' => $this->cargosMensuales($prestamo),
            'cuota_total' => ($c = $this->cuota($prestamo)) ? round($c + $this->cargosMensuales($prestamo), 2) : null,
            'proxima_fecha' => $saldo > 0 && $prestamo->dia_pago ? $this->proximaFecha($prestamo)->toDateString() : null,
            'avance' => (float) $prestamo->monto_original > 0 ? round(max(0, min(100, (1 - $saldo / (float) $prestamo->monto_original) * 100)), 1) : 0.0,
            'meses_restantes' => $base['meses'] ?? null,
            'fecha_fin' => $base['fecha_fin'] ?? null,
            'intereses_restantes' => $base['total_intereses'] ?? null,
            'pagado_capital' => round((float) $pagado->capital, 2),
            'pagado_intereses' => round((float) $pagado->interes, 2),
            'pagado_cargos' => round((float) $pagado->cargos, 2),
        ];
    }

    // ------------------------------------------------------------------ proyecciones

    /**
     * Tabla de pagos desde hoy.
     *
     * @param  array{extra_mensual?: float, abono?: float, modo?: 'plazo'|'cuota', cuota?: float}  $op
     *                                                                                                  abono: pago extra a capital ahora; modo: con el abono, mantener la cuota y terminar antes ("plazo")
     *                                                                                                  o mantener el plazo y bajar la cuota ("cuota"); cuota: probar otra cuota (préstamos sin cuota fija)
     * @return array{filas: array, meses: int, cuota: float, total_intereses: float, total_cargos: float, total_pagado: float, fecha_fin: ?string}|null
     *                                                                                                                                                  null si no hay cuota con qué proyectar
     */
    public function tabla(Prestamo $prestamo, array $op = []): ?array
    {
        $saldo = round($this->saldo($prestamo) - (float) ($op['abono'] ?? 0), 2);
        $cuota = isset($op['cuota']) && $op['cuota'] > 0 ? (float) $op['cuota'] : $this->cuota($prestamo);
        if (! $cuota || $saldo <= 0) {
            return $saldo <= 0 ? ['filas' => [], 'meses' => 0, 'cuota' => (float) $cuota, 'total_intereses' => 0.0, 'total_cargos' => 0.0, 'total_pagado' => 0.0, 'fecha_fin' => null] : null;
        }
        $tasa = (float) $prestamo->tasa_anual;
        if (($op['modo'] ?? 'plazo') === 'cuota' && ($op['abono'] ?? 0) > 0) {
            // La cuota más baja que termina en el mismo número de cuotas (con interés por días)
            $meses = $this->tabla($prestamo, array_intersect_key($op, ['cuota' => 1]))['meses'] ?? null;
            if ($meses) {
                $cuota = $this->anualidad($saldo, $tasa, $meses);
                while ($this->tabla($prestamo, ['abono' => $op['abono'], 'cuota' => $cuota])['meses'] > $meses) {
                    $cuota = round($cuota + 0.01, 2);
                }
            }
        }
        $extra = max(0, (float) ($op['extra_mensual'] ?? 0));
        $cargos = $this->cargosMensuales($prestamo);

        $filas = [];
        $anterior = $this->ultimaFecha($prestamo);
        $fecha = $this->proximaFecha($prestamo);
        $totales = ['interes' => 0.0, 'cargos' => 0.0, 'pagado' => 0.0];
        for ($n = 1; $saldo > 0 && $n <= self::MAX_MESES; $n++) {
            $interes = $this->interes($saldo, $tasa, (int) $anterior->diffInDays($fecha));
            $capital = min($saldo, round($cuota + $extra - $interes, 2));
            if ($capital <= 0) {
                throw ValidationException::withMessages(['cuota' => 'Con esa cuota no alcanza ni para los intereses: el préstamo nunca terminaría.']);
            }
            $saldo = round($saldo - $capital, 2);
            $filas[] = ['n' => $n, 'fecha' => $fecha->toDateString(), 'interes' => $interes, 'capital' => $capital, 'cargos' => $cargos,
                'total' => round($interes + $capital + $cargos, 2), 'saldo' => $saldo];
            $totales['interes'] += $interes;
            $totales['cargos'] += $cargos;
            $totales['pagado'] += $interes + $capital + $cargos;
            $anterior = $fecha;
            $fecha = $this->siguienteFecha($prestamo, $fecha);
        }

        return ['filas' => $filas, 'meses' => count($filas), 'cuota' => round($cuota, 2), 'total_intereses' => round($totales['interes'], 2),
            'total_cargos' => round($totales['cargos'], 2), 'total_pagado' => round($totales['pagado'], 2), 'fecha_fin' => end($filas)['fecha'] ?? null];
    }

    /**
     * Qué pasa si…: seguir igual, pagar todo hoy, abonar algo extra cada mes o un abono único ahora.
     *
     * @param  array{extra_mensual?: float, abono?: float, modo?: string, cuota?: float}  $op
     */
    public function escenarios(Prestamo $prestamo, array $op = []): array
    {
        $hoy = $this->hoy($prestamo->user);
        $saldo = $this->saldo($prestamo);
        $base = $this->tabla($prestamo, array_intersect_key($op, ['cuota' => 1]));
        $costoBase = $base ? $base['total_intereses'] + $base['total_cargos'] : null;
        $ahorro = fn (?array $t) => $t && $costoBase !== null ? round($costoBase - $t['total_intereses'] - $t['total_cargos'], 2) : null;

        $interesHoy = $this->interesAl($prestamo, $hoy, $saldo);
        $resultado = [
            'base' => $base,
            'pagar_hoy' => ['monto' => round($saldo + $interesHoy, 2), 'interes_a_hoy' => $interesHoy,
                'ahorro' => $costoBase !== null ? round($costoBase - $interesHoy, 2) : null],
        ];
        if (($op['extra_mensual'] ?? 0) > 0) {
            $t = $this->tabla($prestamo, array_intersect_key($op, ['cuota' => 1, 'extra_mensual' => 1]));
            $resultado['extra_mensual'] = ['monto' => (float) $op['extra_mensual'], 'tabla' => $t, 'ahorro' => $ahorro($t),
                'meses_menos' => $base && $t ? $base['meses'] - $t['meses'] : null];
        }
        if (($op['abono'] ?? 0) > 0) {
            if ((float) $op['abono'] >= $saldo) {
                throw ValidationException::withMessages(['abono' => 'Con ese abono el préstamo queda pagado: mira "Pagar todo hoy".']);
            }
            $t = $this->tabla($prestamo, array_intersect_key($op, ['cuota' => 1, 'abono' => 1, 'modo' => 1]));
            $resultado['abono'] = ['monto' => (float) $op['abono'], 'modo' => $op['modo'] ?? 'plazo', 'tabla' => $t, 'ahorro' => $ahorro($t),
                'meses_menos' => $base && $t ? $base['meses'] - $t['meses'] : null,
                'cuota_nueva' => $t['cuota'] ?? null];
        }

        return $resultado;
    }

    // ------------------------------------------------------------------ registro

    /** @param array<int, array{nombre: string, monto: numeric}> $cargos */
    public function crear(User $user, array $data, array $cargos = [], bool $conCompromiso = false): Prestamo
    {
        return DB::transaction(function () use ($user, $data, $cargos, $conCompromiso) {
            $prestamo = $user->prestamos()->create($data + ['user_id' => $user->id]);
            $this->guardarCargos($prestamo, $cargos);
            if ($conCompromiso) {
                $this->crearCompromiso($user, $prestamo);
            }

            return $prestamo;
        });
    }

    public function actualizar(Prestamo $prestamo, array $data, ?array $cargos = null): Prestamo
    {
        return DB::transaction(function () use ($prestamo, $data, $cargos) {
            if ($prestamo->pagos()->exists()) {
                unset($data['saldo_inicial'], $data['fecha_saldo']); // con pagos, el punto de partida ya no se mueve
            }
            $prestamo->update($data);
            if ($cargos !== null) {
                $this->guardarCargos($prestamo, $cargos);
            }
            if ($prestamo->recurrente && ($monto = $this->montoPorPagar($prestamo))) {
                $prestamo->recurrente->update(['monto' => $monto, 'dia_pago' => $prestamo->dia_pago ?? $prestamo->recurrente->dia_pago]);
            }

            return $prestamo;
        });
    }

    /** Sin pagos se elimina (y su compromiso mensual deja de generarse); con pagos se desactiva. */
    public function eliminar(Prestamo $prestamo): void
    {
        if ($prestamo->pagos()->exists()) {
            throw ValidationException::withMessages(['prestamo' => 'Este préstamo ya tiene pagos. Desactívalo en lugar de eliminarlo.']);
        }
        DB::transaction(function () use ($prestamo) {
            if ($prestamo->recurrente) {
                $prestamo->recurrente->update(['activo' => false]);
                Compromiso::where('compromiso_recurrente_id', $prestamo->recurrente->id)->where('estado', Compromiso::PENDIENTE)
                    ->where('saldo_pendiente', DB::raw('monto'))->update(['estado' => Compromiso::CANCELADO]);
            }
            $prestamo->delete();
        });
    }

    /**
     * @param  array{fecha: string, capital: numeric, interes: numeric, cargos?: numeric, mora?: numeric, origen: string, comentario?: ?string, user_id?: ?int}  $data
     *                                                                                                                                                                  origen: "efectivo" | "cuenta:{id}" | "planilla" | "ninguno"
     */
    public function registrarPago(User $user, Prestamo $prestamo, array $data): PrestamoPago
    {
        return DB::transaction(function () use ($user, $prestamo, $data) {
            $prestamo = Prestamo::whereKey($prestamo->id)->lockForUpdate()->firstOrFail();
            $saldo = $this->saldo($prestamo);
            $capital = round((float) $data['capital'], 2);
            $total = round($capital + (float) $data['interes'] + (float) ($data['cargos'] ?? 0) + (float) ($data['mora'] ?? 0), 2);
            if ($total <= 0) {
                throw ValidationException::withMessages(['capital' => 'El pago debe tener algún monto.']);
            }
            if ($capital > $saldo) {
                throw ValidationException::withMessages(['capital' => 'El capital supera el saldo del préstamo ($ '.number_format($saldo, 2).').']);
            }
            if ($data['fecha'] < $this->ultimaFecha($prestamo)->toDateString()) {
                throw ValidationException::withMessages(['fecha' => 'La fecha no puede ser anterior al último pago ('.$this->ultimaFecha($prestamo)->format('d/m/Y').').']);
            }

            $quien = $data['user_id'] ?? $user->id;
            // Si parte de la cuota se descuenta de planilla, el egreso es solo lo demás (ej. lo que se paga en ventanilla)
            $planilla = $data['origen'] === 'planilla' ? $total : min($total, (float) $prestamo->monto_planilla);
            $pagado = round($total - $planilla, 2);
            $pago = ['user_id' => $quien, 'fecha' => $data['fecha'], 'capital' => $capital, 'interes' => $data['interes'],
                'cargos' => $data['cargos'] ?? 0, 'mora' => $data['mora'] ?? 0, 'planilla' => $planilla, 'comentario' => $data['comentario'] ?? null];
            $descripcion = "Pago {$prestamo->nombre}".($planilla > 0 && $pagado > 0 ? ' ($ '.number_format($planilla, 2).' por planilla)' : '');
            if ($pagado <= 0 && $data['origen'] !== 'ninguno') {
                $data['origen'] = 'planilla'; // todo se descontó del sueldo: no hay egreso
            }
            $movimiento = ['descripcion' => $descripcion, 'monto' => $pagado, 'fecha' => $data['fecha'], 'tipo' => 'egreso', 'user_id' => $quien, 'contacto_id' => $prestamo->contacto_id,
                'categoria_personal_id' => $this->categoria($user)];

            if ($data['origen'] === 'efectivo') {
                $pago += ['origen' => 'efectivo', 'movimiento_efectivo_id' => $this->efectivo->registrar($user, $movimiento)->id];
            } elseif (preg_match('/^cuenta:(\d+)$/', $data['origen'], $m)) {
                $cuenta = $user->cuentas()->where('activo', true)->find($m[1]) ?? throw ValidationException::withMessages(['origen' => 'Selecciona una cuenta válida.']);
                $pago += ['origen' => 'cuenta', 'movimiento_cuenta_id' => $this->cuentas->registrarMovimiento($cuenta, $movimiento)->id];
            } else {
                $pago += ['origen' => $data['origen'] === 'planilla' ? 'planilla' : 'ninguno'];
            }

            // La cuota del mes en Compromisos queda pagada (o abonada) con este pago
            $compromiso = $prestamo->compromiso_recurrente_id ? Compromiso::where('compromiso_recurrente_id', $prestamo->compromiso_recurrente_id)
                ->where('estado', Compromiso::PENDIENTE)->orderBy('fecha_esperada')->first() : null;
            if ($compromiso && $pagado > 0) {
                $abono = min($pagado, (float) $compromiso->saldo_pendiente);
                $this->compromisos->abonar($compromiso, $abono);
                $pago += ['compromiso_id' => $compromiso->id, 'monto_compromiso' => $abono];
            }

            return $prestamo->pagos()->create($pago);
        });
    }

    /** Solo el último pago (para que el saldo y los intereses sigan cuadrando); revierte su egreso y el abono al compromiso. */
    public function eliminarPago(PrestamoPago $pago): void
    {
        $ultimo = PrestamoPago::where('prestamo_id', $pago->prestamo_id)->orderByDesc('fecha')->orderByDesc('id')->first();
        if (! $pago->is($ultimo)) {
            throw ValidationException::withMessages(['pago' => 'Solo se puede eliminar el último pago del préstamo.']);
        }

        DB::transaction(function () use ($pago) {
            $movimiento = $pago->movimientoEfectivo ?? $pago->movimientoCuenta;
            if ($pago->compromiso && (float) $pago->monto_compromiso > 0) {
                $this->compromisos->abonar($pago->compromiso, -(float) $pago->monto_compromiso);
            }
            $pago->delete();
            match (true) {
                $movimiento instanceof MovimientoEfectivo => $this->efectivo->eliminar($movimiento),
                $movimiento instanceof MovimientoCuenta => $this->cuentas->eliminarMovimiento($movimiento),
                default => null,
            };
        });
    }

    // ------------------------------------------------------------------ apoyo

    private function crearCompromiso(User $user, Prestamo $prestamo): void
    {
        $monto = $this->montoPorPagar($prestamo);
        if (! $monto || ! $prestamo->dia_pago || $prestamo->medio_pago === 'planilla') {
            return;
        }
        $planilla = (float) $prestamo->monto_planilla > 0 ? ' Otros $ '.number_format((float) $prestamo->monto_planilla, 2).' se descuentan de planilla.' : '';
        $recurrente = $this->compromisos->crearRecurrente($user, [
            'user_id' => $prestamo->user_id,
            'contacto_id' => $prestamo->contacto_id,
            'descripcion' => "Cuota {$prestamo->nombre}",
            'comentario' => 'Se paga desde Préstamos para llevar el desglose (capital, interés y seguros).'.$planilla,
            'monto' => $monto,
            'medio_pago' => $prestamo->medio_pago === 'efectivo' ? 'efectivo' : 'transferencia',
            'categoria_personal_id' => $this->categoria($user),
            'frecuencia' => 'mensual',
            'dia_pago' => $prestamo->dia_pago,
            'fecha_inicio' => $this->proximaFecha($prestamo)->toDateString(),
        ]);
        $prestamo->update(['compromiso_recurrente_id' => $recurrente->id]);
    }

    /** Lo que se paga cada mes por el medio de pago: cuota + cargos − la parte que se descuenta de planilla. */
    public function montoPorPagar(Prestamo $prestamo): ?float
    {
        $cuota = $this->cuota($prestamo);

        return $cuota ? max(0, round($cuota + $this->cargosMensuales($prestamo) - (float) $prestamo->monto_planilla, 2)) ?: null : null;
    }

    private function guardarCargos(Prestamo $prestamo, array $cargos): void
    {
        $prestamo->cargos()->delete();
        foreach ($cargos as $c) {
            if (trim((string) ($c['nombre'] ?? '')) !== '' && (float) ($c['monto'] ?? 0) > 0) {
                $prestamo->cargos()->create(['nombre' => trim($c['nombre']), 'monto' => round((float) $c['monto'], 2)]);
            }
        }
    }

    private function mesesRestantesDelPlazo(Prestamo $prestamo): ?int
    {
        if (! $prestamo->plazo_meses) {
            return null;
        }
        $transcurridos = $prestamo->fecha_otorgamiento ? (int) $prestamo->fecha_otorgamiento->diffInMonths($this->ultimaFecha($prestamo)) : 0;

        return max(1, $prestamo->plazo_meses - $transcurridos);
    }

    private function siguienteFecha(Prestamo $prestamo, Carbon $desde): Carbon
    {
        if (! $prestamo->dia_pago) {
            return $desde->copy()->addMonthNoOverflow();
        }
        $mes = $desde->copy()->startOfMonth();
        $fecha = $mes->copy()->day(min($prestamo->dia_pago, $mes->daysInMonth));
        if ($fecha->toDateString() <= $desde->toDateString()) {
            $mes->addMonthNoOverflow();
            $fecha = $mes->copy()->day(min($prestamo->dia_pago, $mes->daysInMonth));
        }

        return $fecha;
    }

    private function interes(float $saldo, float $tasaAnual, int $dias): float
    {
        return round($saldo * $tasaAnual / 100 * $dias / 365, 2);
    }

    /** Cuota fija (capital + interés) que salda $saldo en $meses a tasa mensual tasa/12. */
    private function anualidad(float $saldo, float $tasaAnual, int $meses): float
    {
        $i = $tasaAnual / 100 / 12;

        return round($i > 0 ? $saldo * $i / (1 - (1 + $i) ** -$meses) : $saldo / $meses, 2);
    }

    private function categoria(User $user): int
    {
        return CategoriaPersonal::firstOrCreate(
            ['hogar_id' => $user->hogar_id, 'nombre' => self::CATEGORIA, 'tipo' => 'egreso'],
            ['user_id' => $user->id, 'color' => '#7C3AED', 'activo' => true],
        )->id;
    }

    private function hoy(User $user): Carbon
    {
        return Carbon::parse(Carbon::now($user->zona_horaria ?: config('app.timezone'))->toDateString());
    }
}
