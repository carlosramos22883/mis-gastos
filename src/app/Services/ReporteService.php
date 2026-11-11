<?php

namespace App\Services;

use App\Models\Compromiso;
use App\Models\Contacto;
use App\Models\EstadoCuentaTarjeta;
use App\Models\MovimientoCuenta;
use App\Models\MovimientoEfectivo;
use App\Models\MovimientoTarjeta;
use App\Models\Prestamo;
use App\Models\TarjetaCredito;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Reportes: ejecución financiera de un período (como la hoja de cada mes), gastos por categoría,
 * préstamos y el estado de cuenta de una tarjeta. Gasto e ingreso se cuentan igual que en el inicio (DashboardService).
 * Los montos van como número con dos decimales; las fechas, Y-m-d.
 */
class ReporteService
{
    /** Un período no puede pasar de este número de ciclos (el reporte se arma en memoria). */
    public const MAX_CICLOS = 24;

    public function __construct(
        private readonly CicloEfectivoService $ciclos,
        private readonly CuentaService $cuentas,
        private readonly TarjetaService $tarjetas,
        private readonly PrestamoService $prestamos,
        private readonly DeudorService $deudores,
        private readonly DashboardService $dashboard,
        private readonly CompromisoService $compromisos,
    ) {}

    /**
     * Período a reportar: un ciclo (el que contiene $ciclo, o el actual) o un rango libre de fechas.
     *
     * @return array{desde: Carbon, hasta: Carbon, es_ciclo: bool, anterior: ?string, siguiente: ?string}|null null si el ciclo pedido no existe
     */
    public function periodo(User $user, ?string $ciclo = null, ?string $desde = null, ?string $hasta = null): ?array
    {
        if ($desde && $hasta) {
            return ['desde' => Carbon::parse($desde)->startOfDay(), 'hasta' => Carbon::parse($hasta)->startOfDay(), 'es_ciclo' => false, 'anterior' => null, 'siguiente' => null];
        }
        $rango = $this->ciclos->cicloConsultado($user, $ciclo);
        if (! $rango) {
            return null;
        }
        [$inicio, $fin] = $rango;
        $primera = $this->ciclos->primeraFecha($user);
        $hoy = $this->compromisos->fechas($user)[0];

        return [
            'desde' => $inicio, 'hasta' => $fin, 'es_ciclo' => true,
            'anterior' => $primera && $inicio->toDateString() <= $primera->toDateString() ? null : $inicio->copy()->subDay()->toDateString(),
            'siguiente' => $fin->toDateString() >= $hoy->toDateString() ? null : $fin->copy()->addDay()->toDateString(),
        ];
    }

    /** Ciclos (inicio y fin) que tocan el período, del más antiguo al más reciente. */
    public function ciclosEntre(User $user, Carbon $desde, Carbon $hasta): array
    {
        $lista = [];
        $fecha = $desde->copy();
        while ($fecha->toDateString() <= $hasta->toDateString() && count($lista) < self::MAX_CICLOS) {
            [$ini, $fin] = $this->ciclos->cycleFor($user, $fecha);
            $lista[] = ['inicio' => max($ini->toDateString(), $desde->toDateString()), 'fin' => min($fin->toDateString(), $hasta->toDateString())];
            $fecha = $fin->copy()->addDay();
        }

        return $lista;
    }

    // ------------------------------------------------------------------ 7.1 Ejecución financiera

    /**
     * Lo que entró, lo que salió y cómo quedó todo en el período: efectivo, cuentas, compromisos, tarjetas y lo que nos deben.
     * Tarjetas y "nos deben" muestran cómo están hoy (no tienen historial por fecha).
     */
    public function ejecucion(User $user, Carbon $desde, Carbon $hasta): array
    {
        $this->tarjetas->generarPendientes($user);
        $ingresos = $this->dashboard->movimientosDeIngreso($user, $desde, $hasta)->sortBy(fn ($m) => $m->fecha->toDateString().'-'.$m->id)
            ->map(fn ($m) => $this->fila($m))->values();
        $gastos = $this->porCategoria($this->dashboard->movimientosDeGasto($user, $desde, $hasta));

        $efectivoMovs = $user->movimientosEfectivo()->where('es_saldo_arrastrado', false)
            ->whereDate('fecha', '>=', $desde->toDateString())->whereDate('fecha', '<=', $hasta->toDateString())
            ->selectRaw("COALESCE(SUM(CASE WHEN tipo = 'ingreso' THEN monto ELSE 0 END), 0) ingresos, COALESCE(SUM(CASE WHEN tipo = 'egreso' THEN monto ELSE 0 END), 0) egresos")->first();
        $efectivo = [
            'saldo_inicial' => round($this->ciclos->balance($user, $desde->copy()->subDay()), 2),
            'ingresos' => round((float) $efectivoMovs->ingresos, 2),
            'egresos' => round((float) $efectivoMovs->egresos, 2),
            'saldo_final' => round($this->ciclos->balance($user, $hasta), 2),
        ];

        $cuentas = $user->cuentas()->with('banco')->where('activo', true)->orderBy('nombre')->get()
            ->map(fn ($c) => ['id' => $c->id, 'nombre' => $c->nombre, 'color' => $c->color, 'logo_url' => $c->banco?->logo ? asset('storage/'.$c->banco->logo) : null, 'user_id' => $c->user_id]
                + array_map(fn ($v) => round($v, 2), $this->cuentas->resumen($c, $desde, $hasta)))->values();

        [$hoy, $inicioCiclo] = $this->compromisos->fechas($user);
        $compromisos = $user->compromisos()->with('tarjeta')->where('estado', '!=', Compromiso::CANCELADO)
            ->whereDate('fecha_esperada', '>=', $desde->toDateString())->whereDate('fecha_esperada', '<=', $hasta->toDateString())
            ->orderBy('fecha_esperada')->orderBy('id')->get()
            ->map(function (Compromiso $c) use ($hoy, $inicioCiclo) {
                $estado = $c->estadoVisual($hoy, $inicioCiclo);

                return ['id' => $c->id, 'descripcion' => $c->descripcion, 'fecha' => $c->fecha_esperada->toDateString(), 'color' => $c->tarjeta?->color,
                    'monto' => round((float) $c->monto, 2), 'pagado' => $c->montoPagado(), 'pendiente' => round((float) $c->saldo_pendiente, 2),
                    'estado' => $estado, 'estado_texto' => Compromiso::ESTADOS_VISUALES[$estado] ?? $estado];
            })->values();

        // Tarjetas ordenadas por fecha límite de pago, como en la hoja; las que no deben nada al final
        $tarjetas = $user->tarjetasCredito()->where('activo', true)->get()
            ->map(function (TarjetaCredito $t) {
                $pendiente = $t->compromisos()->where('estado', Compromiso::PENDIENTE)->orderBy('fecha_esperada')->first();

                return ['id' => $t->id, 'nombre' => $t->nombre, 'color' => $t->color, 'user_id' => $t->user_id,
                    'proximo_corte' => $this->tarjetas->proximoCorte($t, $this->tarjetas->inicioPeriodoAbierto($t))->toDateString(),
                    'fecha_limite' => $pendiente?->fecha_esperada?->toDateString(), 'por_pagar' => round((float) ($pendiente->saldo_pendiente ?? 0), 2),
                    'limite' => round((float) $t->limite_credito, 2), 'disponible' => round($this->tarjetas->disponible($t), 2), 'deuda' => round($this->tarjetas->deuda($t), 2)];
            })
            ->sortBy(fn ($t) => ($t['fecha_limite'] ?? '9999-12-31').$t['nombre'])->values();

        $nosDeben = $this->deudores->listar($user)->filter(fn (Contacto $c) => $c->pendiente > 0)
            ->map(fn (Contacto $c) => ['id' => $c->id, 'nombre' => $c->nombreCompleto(), 'pendiente' => round((float) $c->pendiente, 2)])->values();

        $totalIngresos = round((float) $ingresos->sum('monto'), 2);
        $totalGastos = round((float) $gastos->sum('monto'), 2);
        $totalCuentas = round((float) $cuentas->sum('saldo_final'), 2);
        $porPagar = round((float) $compromisos->sum('pendiente'), 2);

        return [
            'ingresos' => $ingresos->all(),
            'gastos' => $gastos->all(),
            'efectivo' => $efectivo,
            'cuentas' => $cuentas->all(),
            'compromisos' => $compromisos->all(),
            'tarjetas' => $tarjetas->all(),
            'nos_deben' => $nosDeben->all(),
            'totales' => [
                'ingresos' => $totalIngresos,
                'gastos' => $totalGastos,
                'ahorro' => round($totalIngresos - $totalGastos, 2),
                'efectivo' => $efectivo['saldo_final'],
                'cuentas' => $totalCuentas,
                'compromisos' => round((float) $compromisos->sum('monto'), 2),
                'compromisos_pagado' => round((float) $compromisos->sum('pagado'), 2),
                'por_pagar' => $porPagar,
                'liquidez' => round($efectivo['saldo_final'] + $totalCuentas - $porPagar, 2),
                'tarjetas_por_pagar' => round((float) $tarjetas->sum('por_pagar'), 2),
                'tarjetas_disponible' => round((float) $tarjetas->sum('disponible'), 2),
                'tarjetas_deuda' => round((float) $tarjetas->sum('deuda'), 2),
                'nos_deben' => round((float) $nosDeben->sum('pendiente'), 2),
            ],
        ];
    }

    // ------------------------------------------------------------------ 7.2 Gastos por categoría

    /** Cada categoría con su total, su porcentaje, sus movimientos y (si el período abarca varios ciclos) lo de cada ciclo. */
    public function gastos(User $user, Carbon $desde, Carbon $hasta): array
    {
        $movimientos = $this->dashboard->movimientosDeGasto($user, $desde, $hasta);
        $ciclos = $this->ciclosEntre($user, $desde, $hasta);
        $categorias = $this->porCategoria($movimientos, true)->map(function (array $c) use ($ciclos) {
            $c['por_ciclo'] = array_map(fn ($ci) => round((float) collect($c['movimientos'])
                ->filter(fn ($m) => $m['fecha'] >= $ci['inicio'] && $m['fecha'] <= $ci['fin'])->sum('monto'), 2), $ciclos);

            return $c;
        });

        return [
            'ciclos' => $ciclos,
            'categorias' => $categorias->values()->all(),
            'por_ciclo' => array_map(fn ($i) => round((float) $categorias->sum(fn ($c) => $c['por_ciclo'][$i]), 2), array_keys($ciclos)),
            'total' => round((float) $categorias->sum('monto'), 2),
            'promedio_por_ciclo' => count($ciclos) ? round((float) $categorias->sum('monto') / count($ciclos), 2) : 0.0,
        ];
    }

    // ------------------------------------------------------------------ 7.4 Préstamos

    /** Cada préstamo con lo pagado (capital, intereses y cargos), lo que falta y sus pagos; activos primero. */
    public function prestamos(User $user): array
    {
        $lista = $user->prestamos()->with(['banco', 'contacto', 'cargos', 'pagos' => fn ($q) => $q->orderBy('fecha')])->get()
            ->sortBy(fn (Prestamo $p) => ($p->activo ? '0' : '1').$p->nombre)
            ->map(function (Prestamo $p) {
                $r = $this->prestamos->resumen($p);

                return [
                    'id' => $p->id, 'nombre' => $p->nombre, 'acreedor' => $p->banco?->nombre ?? $p->contacto?->nombreCompleto() ?? $p->acreedor,
                    'color' => $p->color, 'user_id' => $p->user_id, 'activo' => (bool) $p->activo,
                    'monto_original' => round((float) $p->monto_original, 2), 'tasa_anual' => $p->tasa_anual !== null ? (float) $p->tasa_anual : null,
                    'plazo_meses' => $p->plazo_meses, 'fecha_otorgamiento' => $p->fecha_otorgamiento?->toDateString(),
                    'saldo' => round($r['saldo'], 2), 'cuota_total' => $r['cuota_total'], 'avance' => $r['avance'],
                    'pagos_realizados' => $p->pagos->count(),
                    'pagado_capital' => $r['pagado_capital'], 'pagado_intereses' => $r['pagado_intereses'], 'pagado_cargos' => $r['pagado_cargos'],
                    'pagado_total' => round($r['pagado_capital'] + $r['pagado_intereses'] + $r['pagado_cargos'], 2),
                    'meses_restantes' => $r['meses_restantes'], 'fecha_fin' => $r['fecha_fin'],
                    'intereses_restantes' => $r['intereses_restantes'] !== null ? round($r['intereses_restantes'], 2) : null,
                    'pagos' => $p->pagos->map(fn ($pg) => ['fecha' => $pg->fecha->toDateString(), 'capital' => round((float) $pg->capital, 2), 'interes' => round((float) $pg->interes, 2),
                        'cargos' => round((float) $pg->cargos + (float) $pg->mora, 2), 'total' => round((float) $pg->capital + (float) $pg->interes + (float) $pg->cargos + (float) $pg->mora, 2)])->values()->all(),
                ];
            })->values();
        $activos = $lista->where('activo', true);

        return [
            'prestamos' => $lista->all(),
            'totales' => [
                'saldo' => round((float) $activos->sum('saldo'), 2),
                'cuotas_al_mes' => round((float) $activos->sum('cuota_total'), 2),
                'pagado_capital' => round((float) $lista->sum('pagado_capital'), 2),
                'pagado_intereses' => round((float) $lista->sum('pagado_intereses'), 2),
                'pagado_cargos' => round((float) $lista->sum('pagado_cargos'), 2),
                'intereses_restantes' => round((float) $activos->sum('intereses_restantes'), 2),
            ],
        ];
    }

    // ------------------------------------------------------------------ 7.3 Estado de cuenta

    /** Un corte de una tarjeta con cada línea (compras, cuotas, cargos) y cuánto se ha pagado. */
    public function estadoCuenta(EstadoCuentaTarjeta $estado): array
    {
        $estado->loadMissing(['tarjeta', 'compromiso', 'items.movimiento.categoria']);
        $c = $estado->compromiso;

        return [
            'tarjeta' => ['id' => $estado->tarjeta->id, 'nombre' => $estado->tarjeta->nombre, 'color' => $estado->tarjeta->color, 'ultimos_digitos' => $estado->tarjeta->ultimos_digitos],
            'id' => $estado->id,
            'fecha_inicio' => $estado->fecha_inicio?->toDateString(),
            'fecha_corte' => $estado->fecha_corte->toDateString(),
            'fecha_limite_pago' => $estado->fecha_limite_pago?->toDateString(),
            'total' => round((float) $estado->total, 2),
            'pagado' => $c ? $c->montoPagado() : 0.0,
            'pendiente' => $c && $c->estado === Compromiso::PENDIENTE ? round((float) $c->saldo_pendiente, 2) : 0.0,
            'estado' => $c?->estado,
            'compromiso_id' => $c?->id,
            'lineas' => $estado->items->sortBy(fn ($i) => ($i->movimiento?->fecha?->toDateString() ?? '').'-'.$i->id)->map(fn ($i) => [
                'fecha' => $i->movimiento?->fecha?->toDateString(),
                'descripcion' => $i->movimiento?->descripcion ?? 'Movimiento eliminado',
                'categoria' => $i->movimiento?->categoria?->nombre,
                'tipo' => $i->movimiento?->tipo,
                'cuota' => $i->cuota_numero ? $i->cuota_numero.'/'.$i->movimiento?->cuotas : null,
                'monto' => round((float) $i->monto, 2), // los créditos ya vienen en negativo
            ])->values()->all(),
        ];
    }

    // ------------------------------------------------------------------ apoyo

    /** Agrupa movimientos de gasto por categoría, de mayor a menor, con porcentaje; con detalle si se pide. */
    private function porCategoria(Collection $movimientos, bool $detalle = false): Collection
    {
        $total = (float) $movimientos->sum('monto');

        return $movimientos->groupBy(fn ($m) => $m->categoria->nombre)
            ->map(function (Collection $g, $nombre) use ($total, $detalle) {
                $monto = round((float) $g->sum('monto'), 2);
                $fila = ['nombre' => $nombre, 'color' => $g->first()->categoria->color, 'monto' => $monto,
                    'porcentaje' => $total > 0 ? round($monto / $total * 100, 1) : 0.0, 'cantidad' => $g->count()];
                if ($detalle) {
                    $fila['movimientos'] = $g->sortBy(fn ($m) => $m->fecha->toDateString())->map(fn ($m) => $this->fila($m))->values()->all();
                }

                return $fila;
            })
            ->sortByDesc('monto')->values();
    }

    /** Un movimiento para el reporte: dónde ocurrió (efectivo, cuenta o tarjeta), con su clase e id para enlazarlo. */
    private function fila(MovimientoEfectivo|MovimientoCuenta|MovimientoTarjeta $m): array
    {
        [$clase, $donde] = match (true) {
            $m instanceof MovimientoCuenta => ['cuenta', $m->cuenta?->nombre ?? 'Cuenta'],
            $m instanceof MovimientoTarjeta => ['tarjeta', 'Tarjeta '.($m->tarjeta?->nombre ?? '')],
            default => ['efectivo', 'Efectivo'],
        };

        return ['clase' => $clase, 'id' => $m->id, 'fecha' => $m->fecha->toDateString(), 'descripcion' => $m->descripcion,
            'categoria' => $m->categoria?->nombre, 'color' => $m->categoria?->color, 'donde' => $donde, 'user_id' => $m->user_id, 'monto' => round((float) $m->monto, 2)];
    }
}
