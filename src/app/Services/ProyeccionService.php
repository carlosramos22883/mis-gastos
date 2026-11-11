<?php

namespace App\Services;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Proyecciones: ¿cuándo salgo de mis deudas? Arma la foto de hoy con los datos reales del hogar (ingreso neto,
 * gasto del ciclo, tarjetas, préstamos del banco y de personas) y la simula mes a mes con un escenario.
 *
 * Reglas del modelo (simplificaciones explícitas):
 *  - Cada mes entra el ingreso neto (ya sin lo que se descuenta por planilla) y sale el gasto de vida y la parte en
 *    efectivo de las cuotas del banco. Los préstamos del banco bajan con su cuota a su tasa (la hipoteca va aparte:
 *    no cuenta para "sin deudas").
 *  - Tarjetas: o pagan intereses (su tasa anual / 12 sobre el saldo) o se "rotan" con avances (se pierde un % del
 *    saldo cada mes, hasta el 90 % de los límites; lo que pase de ahí paga intereses).
 *  - Lo que sobra paga primero la deuda más cara (tarjetas por tasa), después la de personas y luego abona a los
 *    préstamos del banco (sobrante = "abonar"; con "ahorrar" se guarda). Un préstamo descontado por planilla que se
 *    termina devuelve ese descuento al ingreso. Lo que falta se va a las tarjetas y el mes queda marcado con faltante.
 *  - Palancas: préstamo nuevo, venta, cambiar el gasto, ingreso extra, algo de una vez, pagar una deuda en un mes y
 *    pasar una deuda a cuotas (a otra tasa). Un préstamo o una venta pagan primero las deudas elegidas como destino.
 *  - Orden de pago (escenario "orden"): la más cara primero (por defecto), la más pequeña primero (bola de nieve) o
 *    personalizado: las deudas de "prioridad" en ese orden y después las demás como la más cara primero.
 *  - Metas: meta() busca cuánto ingreso extra al mes hace falta para llegar a un hito antes de un mes.
 */
class ProyeccionService
{
    public const MAX_MESES = 180;

    public const TIPOS_PALANCA = ['prestamo', 'venta', 'gasto', 'ingreso', 'unico', 'pagar', 'cuotas'];

    /** Orden en que lo que sobra paga las deudas: la más cara primero, la más pequeña primero o el que elija la persona. */
    public const ORDENES = ['mas_cara', 'mas_pequena', 'personalizado'];

    /** Metas que se pueden pedir (o la clave de una deuda): ¿cuándo quiero haber llegado? */
    public const METAS = ['tarjetas', 'personas', 'sin_deudas'];

    /** Lo más que se busca de ingreso extra para cumplir una meta. */
    private const EXTRA_MAXIMO = 20000.0;

    /** Tasa de una tarjeta que no la tiene registrada. */
    private const TASA_SUPUESTA = 40.0;

    public function __construct(
        private readonly TarjetaService $tarjetas,
        private readonly PrestamoService $prestamos,
        private readonly IngresoService $ingresos,
        private readonly CicloEfectivoService $ciclos,
        private readonly DashboardService $dashboard,
    ) {}

    /**
     * La foto de hoy.
     *
     * @return array{mes_inicio: string, ingreso: float, gasto: float, gasto_origen: string, deudas: array<int, array>}
     */
    public function base(User $user): array
    {
        $hoy = Carbon::now($user->zona_horaria ?: config('app.timezone'));

        // Ingreso neto mensual y lo que se descuenta por planilla de cada préstamo
        $ingreso = 0.0;
        $planilla = [];
        foreach ($user->fuentesIngreso()->where('activo', true)->with('conceptos')->get() as $fuente) {
            $propuesta = $this->ingresos->propuesta($fuente);
            $veces = match ($fuente->frecuencia) {
                'quincenal' => 2, 'semanal' => 52 / 12, default => 1
            };
            $ingreso += $propuesta['neto'] * $veces;
            foreach ($propuesta['lineas'] as $linea) {
                if ($linea['prestamo_id'] && $linea['tipo'] === 'descuento') {
                    $planilla[$linea['prestamo_id']] = ($planilla[$linea['prestamo_id']] ?? 0) + (float) $linea['monto'] * $veces;
                }
            }
        }

        // Gasto de vida: el tope del ciclo o, sin tope, el promedio de los últimos 3 ciclos (sin cuotas de préstamos)
        $tope = (float) $user->hogar?->tope_gasto_ciclo;
        if ($tope > 0) {
            [$gasto, $origen] = [$tope, 'tope'];
        } else {
            $fin = $this->ciclos->cycleFor($user)[0]->copy()->subDay();
            $suma = 0.0;
            for ($i = 0; $i < 3; $i++) {
                [$ini, $fi] = $this->ciclos->cycleFor($user, $fin);
                $suma += (float) $this->dashboard->movimientosDeGasto($user, $ini, $fi)
                    ->reject(fn ($m) => in_array($m->categoria->nombre, DashboardService::FUERA_DEL_TOPE, true))->sum('monto');
                $fin = $ini->copy()->subDay();
            }
            [$gasto, $origen] = [round($suma / 3, 2), 'promedio'];
        }

        $hipotecas = DB::table('tipos_prestamo')->where('nombre', 'like', 'Hipotec%')->pluck('id')->all();
        $deudas = [];
        foreach ($user->tarjetasCredito()->with('marcaRed')->where('activo', true)->orderBy('nombre')->get() as $t) {
            $saldo = $this->tarjetas->deuda($t);
            if ($saldo > 0.009) {
                $deudas[] = ['clave' => 'tarjeta:'.$t->id, 'tipo' => 'tarjeta', 'nombre' => $t->nombre, 'saldo' => $saldo,
                    'tasa' => (float) ($t->tasa_interes ?: self::TASA_SUPUESTA), 'tasa_supuesta' => ! $t->tasa_interes,
                    'limite' => (float) $t->limite_credito, 'color' => $t->color];
            }
        }
        foreach ($user->prestamos()->with('cargos')->where('activo', true)->orderBy('id')->get() as $p) {
            $saldo = $this->prestamos->saldo($p);
            if ($saldo <= 0.009) {
                continue;
            }
            $cuota = $this->prestamos->cuota($p);
            if (! $cuota) { // de una persona: sin cuota ni intereses, se paga con lo que sobre
                $deudas[] = ['clave' => 'prestamo:'.$p->id, 'tipo' => 'personal', 'nombre' => $p->nombre, 'saldo' => $saldo, 'tasa' => (float) $p->tasa_anual,
                    'color' => $p->color];

                continue;
            }
            $total = round($cuota + $this->prestamos->cargosMensuales($p), 2);
            $deudas[] = ['clave' => 'prestamo:'.$p->id, 'tipo' => 'banco', 'nombre' => $p->nombre, 'saldo' => $saldo, 'tasa' => (float) $p->tasa_anual,
                'cuota' => round($cuota, 2), 'cargos' => round($total - $cuota, 2), 'cuota_efectivo' => max(0, round($total - ($planilla[$p->id] ?? 0), 2)),
                'hipoteca' => in_array($p->tipo_prestamo_id, $hipotecas, true), 'color' => $p->color];
        }

        return ['mes_inicio' => $hoy->copy()->startOfMonth()->addMonthNoOverflow()->format('Y-m'), 'ingreso' => round($ingreso, 2),
            'gasto' => round($gasto, 2), 'gasto_origen' => $origen, 'deudas' => $deudas];
    }

    /**
     * Simula un escenario sobre la foto de hoy.
     *
     * @param  array{nombre?: string, modo_tarjetas?: 'intereses'|'rotar', perdida_rotar?: float, palancas?: array<int, array>}  $escenario
     * @return array{meses: array, hitos: array, deudas: array, totales: array}
     */
    public function simular(array $base, array $escenario): array
    {
        $deudas = collect($base['deudas'])->keyBy('clave')->map(fn ($d) => $d + ['cero' => null])->all();
        $modo = ($escenario['modo_tarjetas'] ?? 'intereses') === 'rotar' ? 'rotar' : 'intereses';
        $perdida = max(0, (float) ($escenario['perdida_rotar'] ?? 1)) / 100;
        $abonarBancos = ($escenario['sobrante'] ?? 'abonar') !== 'ahorrar'; // al terminar tarjetas y personas: abonar a los préstamos o ahorrar
        $orden = in_array($escenario['orden'] ?? null, self::ORDENES, true) ? $escenario['orden'] : 'mas_cara';
        $prioridad = array_values(array_filter((array) ($escenario['prioridad'] ?? []), 'is_string'));
        $palancas = collect($escenario['palancas'] ?? [])->filter(fn ($p) => in_array($p['tipo'] ?? null, self::TIPOS_PALANCA, true))->values();
        $capacidad = 0.9 * collect($deudas)->where('tipo', 'tarjeta')->sum('limite');
        $tasaMaxima = (float) (collect($deudas)->where('tipo', 'tarjeta')->max('tasa') ?? self::TASA_SUPUESTA);
        $mes = Carbon::createFromFormat('!Y-m', $base['mes_inicio']);
        $nuevos = []; // préstamos tomados en el escenario
        $totales = ['intereses_tarjetas' => 0.0, 'perdida_rotar' => 0.0, 'intereses_prestamos_nuevos' => 0.0, 'comisiones' => 0.0,
            'faltante' => 0.0, 'meses_con_faltante' => 0, 'meses_sobre_limite' => 0];
        $filas = [];
        $hitos = ['tarjetas' => null, 'personas' => null, 'sin_deudas' => null];
        $ahorro = 0.0;

        for ($n = 0; $n < self::MAX_MESES; $n++, $mes->addMonthNoOverflow()) {
            $clave = $mes->format('Y-m');
            $enMes = fn (array $p) => ($p['mes'] ?? null) === $clave;
            $vigente = fn (array $p) => ($p['desde'] ?? '0000-00') <= $clave && (empty($p['hasta']) || $p['hasta'] >= $clave);

            // 1. Lo que entra y lo que sale este mes
            $gasto = (float) ($palancas->where('tipo', 'gasto')->filter(fn ($p) => $vigente($p))->last()['monto'] ?? $base['gasto']);
            // Un préstamo que se descontaba por planilla y ya se pagó: ese descuento vuelve al sueldo
            $liberado = (float) collect($deudas)->where('tipo', 'banco')->filter(fn ($d) => $d['saldo'] <= 0.009)->sum(fn ($d) => $d['cuota'] + $d['cargos'] - $d['cuota_efectivo']);
            $ingreso = (float) $base['ingreso'] + $liberado + (float) $palancas->where('tipo', 'ingreso')->filter(fn ($p) => $vigente($p))->sum('monto');
            $cuotas = 0.0;
            foreach ($deudas as $k => $d) {
                if ($d['tipo'] === 'banco' && $d['saldo'] > 0.009) {
                    $cuotas += $d['cuota_efectivo'];
                    $interes = $d['saldo'] * $d['tasa'] / 100 / 12;
                    $deudas[$k]['saldo'] = max(0, round($d['saldo'] + $interes - $d['cuota'], 2));
                }
            }
            foreach ($nuevos as $i => $p) {
                if ($p['saldo'] > 0.009) {
                    $interes = $p['saldo'] * $p['tasa'] / 100 / 12;
                    $pago = min($p['cuota'], round($p['saldo'] + $interes, 2));
                    $cuotas += $pago;
                    $totales['intereses_prestamos_nuevos'] += $interes;
                    $nuevos[$i]['saldo'] = max(0, round($p['saldo'] + $interes - $pago, 2));
                }
            }
            $caja = $ingreso - $gasto - $cuotas;

            // 2. Lo de una vez: préstamos nuevos y ventas (con destino), ingresos o gastos únicos
            foreach ($palancas as $p) {
                if (! $enMes($p)) {
                    continue;
                }
                if ($p['tipo'] === 'prestamo') {
                    $monto = (float) $p['monto'];
                    $plazo = max(1, (int) ($p['plazo'] ?? 60));
                    $tasa = (float) ($p['tasa'] ?? 0);
                    $cuota = (float) ($p['cuota'] ?? 0) ?: $this->anualidad($monto, $tasa, $plazo);
                    $nuevos[] = ['nombre' => $p['descripcion'] ?? 'Préstamo nuevo', 'saldo' => $monto, 'tasa' => $tasa, 'cuota' => $cuota];
                    $totales['comisiones'] += (float) ($p['comision'] ?? 0);
                    $caja += $this->pagarDestinos($deudas, $p['destino'] ?? [], $monto - (float) ($p['comision'] ?? 0));
                } elseif ($p['tipo'] === 'venta') {
                    $caja += $this->pagarDestinos($deudas, $p['destino'] ?? [], (float) $p['monto']);
                } elseif ($p['tipo'] === 'unico') {
                    $caja += (float) $p['monto']; // positivo entra (aguinaldo), negativo sale (renta)
                } elseif ($p['tipo'] === 'cuotas' && ($d = $deudas[$p['deuda'] ?? ''] ?? null) && $d['saldo'] > 0.009) {
                    // El saldo pasa a un préstamo a plazo fijo (ExtraYA, Minicuotas): deja de rotar o de pagar la tasa de la tarjeta
                    $comision = (float) ($p['comision'] ?? 0);
                    $monto = round($d['saldo'] + $comision, 2);
                    $tasa = (float) ($p['tasa'] ?? 0);
                    $nuevos[] = ['nombre' => $d['nombre'].' en cuotas', 'saldo' => $monto, 'tasa' => $tasa, 'cuota' => $this->anualidad($monto, $tasa, max(1, (int) ($p['plazo'] ?? 12)))];
                    $totales['comisiones'] += $comision;
                    $deudas[$p['deuda']]['saldo'] = 0.0;
                }
            }

            // 3. Pagos decididos para este mes (ej. "Marlene en noviembre"), salgan de donde salgan
            foreach ($palancas->where('tipo', 'pagar')->filter(fn ($p) => $enMes($p)) as $p) {
                if (isset($deudas[$p['deuda'] ?? ''])) {
                    $d = $deudas[$p['deuda']];
                    $pago = min($d['saldo'], (float) ($p['monto'] ?? 0) ?: $d['saldo']);
                    $deudas[$p['deuda']]['saldo'] = round($d['saldo'] - $pago, 2);
                    $caja -= $pago;
                }
            }

            // 4. Costo de las tarjetas este mes
            $tarjetas = collect($deudas)->where('tipo', 'tarjeta');
            $enTarjetas = (float) $tarjetas->sum('saldo');
            if ($modo === 'rotar') {
                $dentro = min($enTarjetas, $capacidad);
                $costo = $dentro * $perdida + max(0, $enTarjetas - $capacidad) * $tasaMaxima / 100 / 12;
                $totales['perdida_rotar'] += $dentro * $perdida;
                $totales['intereses_tarjetas'] += $costo - $dentro * $perdida;
                if ($enTarjetas > 0.009) { // el costo se reparte en proporción al saldo
                    foreach ($tarjetas as $k => $t) {
                        $deudas[$k]['saldo'] = round($t['saldo'] + $costo * $t['saldo'] / $enTarjetas, 2);
                    }
                }
            } else {
                foreach ($tarjetas as $k => $t) {
                    $interes = $t['saldo'] * $t['tasa'] / 100 / 12;
                    $totales['intereses_tarjetas'] += $interes;
                    $deudas[$k]['saldo'] = round($t['saldo'] + $interes, 2);
                }
            }

            // 5. Lo que sobra paga deudas (la más cara primero); lo que falta se va a las tarjetas
            $faltante = 0.0;
            if ($caja >= 0) {
                $caja = $this->pagarEnOrden($deudas, $caja, $abonarBancos, $orden, $prioridad);
                // Y a los préstamos tomados en el escenario (el de mayor tasa primero)
                foreach ($abonarBancos ? collect($nuevos)->sortByDesc('tasa')->keys() : [] as $i) {
                    $pago = min($caja, $nuevos[$i]['saldo']);
                    $nuevos[$i]['saldo'] = round($nuevos[$i]['saldo'] - $pago, 2);
                    $caja -= $pago;
                }
                $ahorro += $caja;
            } else {
                $faltante = -$caja;
                $totales['faltante'] += $faltante;
                $totales['meses_con_faltante']++;
                $this->cargarFaltante($deudas, $faltante);
            }

            // 6. Fila del mes
            $saldo = fn (string $tipo, ?bool $hipoteca = null) => round((float) collect($deudas)->where('tipo', $tipo)
                ->when($hipoteca !== null, fn ($c) => $c->filter(fn ($d) => (bool) ($d['hipoteca'] ?? false) === $hipoteca))->sum('saldo'), 2);
            $fila = ['mes' => $clave, 'ingreso' => round($ingreso, 2), 'gasto' => round($gasto, 2), 'cuotas' => round($cuotas, 2), 'faltante' => round($faltante, 2),
                'tarjetas' => $saldo('tarjeta'), 'bancos' => $saldo('banco', false), 'hipoteca' => $saldo('banco', true), 'personas' => $saldo('personal'),
                'nuevos' => round(collect($nuevos)->sum('saldo'), 2), 'ahorro' => round($ahorro, 2)];
            $fila['total'] = round($fila['tarjetas'] + $fila['bancos'] + $fila['personas'] + $fila['nuevos'], 2); // sin la hipoteca
            if ($fila['tarjetas'] > 0 && $fila['tarjetas'] > $capacidad / 0.9) {
                $totales['meses_sobre_limite']++;
            }
            $filas[] = $fila;

            foreach ($deudas as $k => $d) {
                if ($d['saldo'] <= 0.009 && ! $d['cero']) {
                    $deudas[$k]['cero'] = $clave;
                }
            }
            $hitos['tarjetas'] ??= $fila['tarjetas'] <= 0.009 ? $clave : null;
            $hitos['personas'] ??= $fila['personas'] <= 0.009 ? $clave : null;
            if ($fila['total'] <= 0.009) {
                $hitos['sin_deudas'] = $clave;
                break;
            }
        }

        return [
            'meses' => $filas,
            'hitos' => $hitos,
            'deudas' => collect($deudas)->map(fn ($d) => ['clave' => $d['clave'], 'tipo' => $d['tipo'], 'nombre' => $d['nombre'], 'cero' => $d['cero'],
                'hipoteca' => (bool) ($d['hipoteca'] ?? false)])->values()->all(),
            'totales' => collect($totales)->map(fn ($v) => is_float($v) ? round($v, 2) : $v)->all() + ['ahorro' => round($ahorro, 2)],
        ];
    }

    /**
     * Meta: ¿cuánto ingreso extra al mes (desde el inicio) hace falta para llegar a "que" (tarjetas, personas, sin_deudas
     * o la clave de una deuda) a más tardar en "mes"? extra = 0 si el escenario ya lo logra; null si ni con
     * EXTRA_MAXIMO al mes. Se busca por bisección (al centavo, redondeado hacia arriba al dólar).
     *
     * @param  array{que: string, mes: string}  $meta
     * @return array{que: string, mes: string, extra: ?float, logrado_en: ?string}
     */
    public function meta(array $base, array $escenario, array $meta): array
    {
        $llega = function (array $resultado) use ($meta): ?string {
            $fecha = in_array($meta['que'], self::METAS, true) ? $resultado['hitos'][$meta['que']]
                : (collect($resultado['deudas'])->firstWhere('clave', $meta['que'])['cero'] ?? null);

            return $fecha && $fecha <= $meta['mes'] ? $fecha : null;
        };
        $con = fn (float $extra) => $this->simular($base, array_merge($escenario, [
            'palancas' => array_merge($escenario['palancas'] ?? [], $extra > 0 ? [['tipo' => 'ingreso', 'desde' => $base['mes_inicio'], 'monto' => $extra]] : []),
        ]));

        if ($fecha = $llega($con(0))) {
            return $meta + ['extra' => 0.0, 'logrado_en' => $fecha];
        }
        if (! $llega($con(self::EXTRA_MAXIMO))) {
            return $meta + ['extra' => null, 'logrado_en' => null];
        }
        [$bajo, $alto] = [0.0, self::EXTRA_MAXIMO];
        while ($alto - $bajo > 0.5) {
            $medio = ($bajo + $alto) / 2;
            $llega($con($medio)) ? $alto = $medio : $bajo = $medio;
        }
        $extra = ceil($alto);

        return $meta + ['extra' => $extra, 'logrado_en' => $llega($con($extra))];
    }

    /** Paga las deudas elegidas, en ese orden, con el dinero de una venta o un préstamo; devuelve lo que sobra. */
    private function pagarDestinos(array &$deudas, array $destino, float $dinero): float
    {
        foreach ($destino as $clave) {
            if ($dinero <= 0 || ! isset($deudas[$clave])) {
                continue;
            }
            $pago = min($dinero, $deudas[$clave]['saldo']);
            $deudas[$clave]['saldo'] = round($deudas[$clave]['saldo'] - $pago, 2);
            $dinero -= $pago;
        }

        return $dinero;
    }

    /**
     * Lo que sobra paga deudas y devuelve lo que quede. Por defecto ("mas_cara"): tarjetas por tasa, después las deudas
     * con personas y, si se elige, abona a los préstamos del banco (menos la hipoteca) por tasa. "mas_pequena": tarjetas y
     * personas de la de menor saldo a la mayor. "personalizado": primero las de $prioridad, en ese orden.
     */
    private function pagarEnOrden(array &$deudas, float $dinero, bool $abonarBancos, string $criterio = 'mas_cara', array $prioridad = []): float
    {
        $grupo = ['tarjeta' => 0, 'personal' => 1, 'banco' => 2];
        $posicion = array_flip($prioridad);
        $orden = collect($deudas)->filter(fn ($d) => $d['saldo'] > 0.009 && ($d['tipo'] !== 'banco' || ($abonarBancos && empty($d['hipoteca']))))
            ->sortBy(fn ($d) => match (true) {
                $criterio === 'personalizado' && isset($posicion[$d['clave']]) => [0, $posicion[$d['clave']], 0],
                $criterio === 'mas_pequena' && $d['tipo'] !== 'banco' => [1, 0, $d['saldo']],
                default => [2, $grupo[$d['tipo']], -$d['tasa']],
            })->keys();
        foreach ($orden as $clave) {
            if ($dinero <= 0.009) {
                break;
            }
            $pago = min($dinero, $deudas[$clave]['saldo']);
            $deudas[$clave]['saldo'] = round($deudas[$clave]['saldo'] - $pago, 2);
            $dinero -= $pago;
        }

        return max(0, $dinero);
    }

    /** Lo que no alcanza se cubre con las tarjetas: primero la que tiene más disponible. */
    private function cargarFaltante(array &$deudas, float $faltante): void
    {
        $tarjetas = collect($deudas)->where('tipo', 'tarjeta')->sortByDesc(fn ($t) => $t['limite'] - $t['saldo'])->keys();
        if ($tarjetas->isEmpty()) {
            $deudas['faltante'] ??= ['clave' => 'faltante', 'tipo' => 'tarjeta', 'nombre' => 'Faltante sin cubrir', 'saldo' => 0.0, 'tasa' => self::TASA_SUPUESTA, 'limite' => 0, 'cero' => null];
            $deudas['faltante']['saldo'] += $faltante;

            return;
        }
        foreach ($tarjetas as $clave) {
            $libre = max(0, $deudas[$clave]['limite'] - $deudas[$clave]['saldo']);
            $carga = $clave === $tarjetas->last() ? $faltante : min($faltante, $libre);
            $deudas[$clave]['saldo'] = round($deudas[$clave]['saldo'] + $carga, 2);
            $faltante -= $carga;
            if ($faltante <= 0.009) {
                return;
            }
        }
    }

    private function anualidad(float $monto, float $tasaAnual, int $meses): float
    {
        $i = $tasaAnual / 100 / 12;

        return round($i > 0 ? $monto * $i / (1 - (1 + $i) ** -$meses) : $monto / $meses, 2);
    }
}
