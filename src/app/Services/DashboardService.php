<?php

namespace App\Services;

use App\Models\Compromiso;
use App\Models\MovimientoCuenta;
use App\Models\MovimientoEfectivo;
use App\Models\MovimientoTarjeta;
use App\Models\TarjetaCredito;
use App\Models\User;
use App\Support\CobrosDeEventos;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Números de la página de inicio: dónde está el dinero, qué se debe, qué vence y en qué se gasta.
 *
 *  - Gasto = egresos con categoría (efectivo y cuentas, sin transferencias) + compras y cargos de tarjeta.
 *    No cuentan como gasto los movimientos entre cuentas, ajustes, préstamos a terceros ni el pago de avances.
 *  - Ingreso = ingresos con categoría (sin transferencias). No cuentan el saldo inicial, los avances de tarjeta,
 *    los préstamos recibidos, los cobros de deudas, los movimientos entre cuentas ni los ajustes.
 *  - Liquidez = efectivo + cuentas − compromisos pendientes hasta el fin del ciclo.
 */
class DashboardService
{
    public const NO_SON_GASTO = ['Movimientos entre cuentas', 'Ajustes', 'Préstamos a terceros', 'Pago de avances de tarjeta', TarjetaService::CATEGORIA_PAGO];

    public const NO_SON_INGRESO = ['Saldo inicial', 'Movimientos entre cuentas', 'Ajustes', 'Avances de tarjeta', 'Préstamos recibidos', 'Cobros de deudas'];

    public function __construct(
        private readonly CicloEfectivoService $ciclos,
        private readonly CuentaService $cuentas,
        private readonly TarjetaService $tarjetas,
        private readonly PrestamoService $prestamos,
        private readonly DeudorService $deudores,
        private readonly IngresoService $ingresos,
        private readonly CompromisoService $compromisos,
        private readonly PresupuestoService $presupuestos,
    ) {}

    /** Categorías que no cuentan para el tope de gasto del ciclo: las cuotas de préstamos ya están aparte. */
    public const FUERA_DEL_TOPE = ['Préstamos y deudas'];

    private const MINUTOS_ALERTAS = 10;

    public function resumen(User $user): array
    {
        $this->ciclos->closeIfNeeded($user);
        $this->tarjetas->generarPendientes($user);
        [$hoy, $inicio, $fin] = $this->compromisos->fechas($user);
        [$tope, $presupuestos] = $this->termometro($user, $hoy, $inicio, $fin);

        $efectivo = round($this->ciclos->balance($user), 2);
        $cuentas = $user->cuentas()->with('banco')->where('activo', true)->orderBy('nombre')->get()
            ->map(fn ($c) => ['id' => $c->id, 'nombre' => $c->nombre, 'color' => $c->color, 'banco' => $c->banco, 'user_id' => $c->user_id, 'saldo' => $this->cuentas->saldo($c)]);
        $tarjetas = $this->tarjetas($user);
        $pendientes = $user->compromisos()->where('estado', Compromiso::PENDIENTE)
            ->where(fn ($q) => $q->whereNull('fecha_esperada')->orWhereDate('fecha_esperada', '<=', $fin->toDateString()))->get();
        $porPagarCiclo = round((float) $pendientes->whereNotNull('fecha_esperada')->sum('saldo_pendiente'), 2);
        $prestamos = $user->prestamos()->where('activo', true)->with('cargos')->get()->map(fn ($p) => ['saldo' => $this->prestamos->saldo($p), 'cuota' => $this->prestamos->montoPorPagar($p) ?? 0]);
        $totalCuentas = round($cuentas->sum('saldo'), 2);

        $resumen = [
            'hoy' => $hoy->toDateString(),
            'ciclo' => ['inicio' => $inicio->toDateString(), 'fin' => $fin->toDateString(), 'dias_restantes' => $this->diasEntre($hoy, $fin)],
            'efectivo' => $efectivo,
            'cuentas' => $cuentas->values()->all(),
            'total_cuentas' => $totalCuentas,
            'tarjetas' => $tarjetas['lista'],
            'tarjetas_totales' => $tarjetas['totales'],
            'por_pagar_ciclo' => $porPagarCiclo,
            'liquidez' => round($efectivo + $totalCuentas - $porPagarCiclo, 2),
            'prestamos_ciclo' => $this->prestamosDelCiclo($user, $inicio, $fin),
            'avances_ciclo' => $this->avancesDelCiclo($user, $inicio, $fin),
            'deuda_prestamos' => round($prestamos->sum('saldo'), 2),
            'cuotas_prestamos' => round($prestamos->sum('cuota'), 2),
            'nos_deben' => round($this->deudores->listar($user)->sum('pendiente'), 2),
            'gastos_por_categoria' => $this->gastosPorCategoria($user, $inicio, $fin),
            'historial' => $this->historial($user, $fin),
            'tope' => $tope,
            'presupuestos' => $presupuestos->all(),
            'alertas' => $alertas = $this->alertas($user, $hoy, $tarjetas['lista'], $tope, $presupuestos),
            'proximos' => $user->compromisos()->with('tarjeta')->where('estado', Compromiso::PENDIENTE)->where('es_presupuesto', false)->whereNotNull('fecha_esperada')
                ->orderBy('fecha_esperada')->limit(8)->get()
                ->map(fn ($c) => ['id' => $c->id, 'descripcion' => $c->descripcion, 'fecha' => $c->fecha_esperada->toDateString(), 'monto' => (float) $c->saldo_pendiente,
                    'vencido' => $c->fecha_esperada->toDateString() < $hoy->toDateString(), 'color' => $c->tarjeta?->color])->all(),
        ];
        Cache::put(self::claveAlertas($user), $alertas, self::MINUTOS_ALERTAS * 60);

        return $resumen;
    }

    /** Los avisos de la campana (los mismos del inicio), guardados unos minutos: la campana sale en todas las pantallas. */
    public function alertasCampana(User $user): array
    {
        return Cache::remember(self::claveAlertas($user), self::MINUTOS_ALERTAS * 60, function () use ($user) {
            [$hoy, $inicio, $fin] = $this->compromisos->fechas($user);
            [$tope, $presupuestos] = $this->termometro($user, $hoy, $inicio, $fin);

            return $this->alertas($user, $hoy, $this->tarjetas($user)['lista'], $tope, $presupuestos);
        });
    }

    /** Abrir un aviso de la campana: queda como visto (deja de contar) y devuelve a dónde lleva. */
    public function abrirAviso(User $user, string $clave): ?string
    {
        $aviso = collect($this->alertasCampana($user))->firstWhere('clave', $clave);
        if ($aviso) {
            $this->marcarVistos($user, [$clave]);
        }

        return $aviso['url'] ?? null;
    }

    /** Marca avisos como vistos; solo se guardan las claves de los avisos que siguen vigentes. */
    public function marcarVistos(User $user, ?array $claves = null): void
    {
        $vigentes = collect($this->alertasCampana($user))->pluck('clave');
        $vistas = $vigentes->intersect(array_merge($user->avisos_vistos ?? [], $claves ?? $vigentes->all()))->values()->all();
        $user->forceFill(['avisos_vistos' => $vistas])->save();
    }

    /** Un gasto o un compromiso cambió: los avisos de la campana de todo el hogar se recalculan en la siguiente pantalla. */
    public static function olvidarAlertas(?int $hogarId): void
    {
        if ($hogarId) {
            User::where('hogar_id', $hogarId)->pluck('id')->each(fn ($id) => Cache::forget('alertas-campana:'.$id));
        }
    }

    private static function claveAlertas(User $user): string
    {
        return 'alertas-campana:'.$user->id;
    }

    /** Tope de gasto del ciclo y presupuestos abiertos (súper, gasolina…) con lo gastado y lo que queda. */
    /**
     * El tope y los presupuestos del ciclo, armados con las mismas cifras para que siempre cuadren:
     * tope − gastado − por pagar = sin asignar + lo que queda en los presupuestos − lo gastado o por pagar fuera de ellos.
     */
    private function termometro(User $user, Carbon $hoy, Carbon $inicio, Carbon $fin): array
    {
        $this->presupuestos->sincronizar($user);
        $compromisos = $user->compromisos()->with('categoria')->where('es_presupuesto', true)->where('estado', Compromiso::PENDIENTE)
            ->whereDate('ciclo_inicio', $inicio->toDateString())->orderBy('descripcion')->get();
        $presupuestos = $compromisos->map(fn ($c) => ['id' => $c->id, 'descripcion' => $c->descripcion, 'categoria' => $c->categoria?->nombre, 'color' => $c->categoria?->color,
            'categoria_id' => $c->categoria_personal_id] + $this->presupuestos->resumen($c))->values();

        // Una categoría con dos presupuestos tiene un solo gasto: se cuenta una vez.
        $porCategoria = $presupuestos->groupBy('categoria_id')->map(fn ($g) => ['gastado' => $g->first()['gastado'], 'comprometido' => $g->first()['comprometido']]);
        $conPresupuesto = $porCategoria->keys()->filter()->all();

        $fueraDelTope = fn ($nombre) => in_array($nombre, self::FUERA_DEL_TOPE, true) || in_array($nombre, self::NO_SON_GASTO, true);
        $gastosFuera = $this->movimientosDeGasto($user, $inicio, $fin)
            ->reject(fn ($m) => $fueraDelTope($m->categoria->nombre) || in_array($m->categoria_personal_id, $conPresupuesto, true));
        $pendientesFuera = $user->compromisos()->with('categoria')->where('es_presupuesto', false)->where('estado', Compromiso::PENDIENTE)
            ->whereDate('ciclo_inicio', $inicio->toDateString())->whereNotNull('categoria_personal_id')
            ->whereNotIn('categoria_personal_id', $conPresupuesto)->get()->reject(fn ($c) => $fueraDelTope($c->categoria?->nombre));
        // Cada categoría fuera de presupuesto con sus gastos y lo que falta pagar, para ver de dónde sale el monto
        $fuera = collect();
        foreach ($gastosFuera->concat($pendientesFuera)->groupBy(fn ($m) => $m->categoria->nombre) as $nombre => $g) {
            [$pendientes, $gastos] = $g->partition(fn ($m) => $m instanceof Compromiso);
            $fuera->push(['nombre' => $nombre, 'categoria_id' => $g->first()->categoria_personal_id,
                'gastado' => round((float) $gastos->sum('monto'), 2), 'comprometido' => round((float) $pendientes->sum('saldo_pendiente'), 2),
                'movimientos' => $gastos->sortByDesc(fn ($m) => $m->fecha->toDateString().'-'.str_pad((string) $m->id, 10, '0', STR_PAD_LEFT))
                    ->map(fn ($m) => $this->movimientoFuera($m))->values()->all(),
                'pendientes' => $pendientes->sortBy(fn ($c) => $c->fecha_esperada?->toDateString() ?? '9999')
                    ->map(fn ($c) => ['id' => $c->id, 'descripcion' => $c->descripcion, 'fecha' => $c->fecha_esperada?->toDateString(), 'monto' => round((float) $c->saldo_pendiente, 2)])
                    ->values()->all()]);
        }
        $fuera = $fuera->sortByDesc(fn ($f) => $f['gastado'] + $f['comprometido'])->values();

        $tope = $this->presupuestos->tope($user->hogar,
            gastado: (float) $porCategoria->sum('gastado') + (float) $fuera->sum('gastado'),
            diasRestantes: $this->diasEntre($hoy, $fin),
            comprometido: (float) $porCategoria->sum('comprometido') + (float) $fuera->sum('comprometido'),
            presupuestado: (float) $presupuestos->sum('monto'),
            fuera: $fuera->all());

        return [$tope, $presupuestos->map(fn ($p) => collect($p)->except('categoria_id')->all())];
    }

    /** Un gasto fuera de presupuesto, con la misma forma que los de un presupuesto (clase y origen para abrirlo). */
    private function movimientoFuera(Model $m): array
    {
        // lugar_id = la cuenta o la tarjeta donde está (null en efectivo), para abrirla desde las apps
        [$clase, $origen, $lugar] = match (true) {
            $m instanceof MovimientoCuenta => ['cuenta', $m->cuenta?->nombre ?? 'Cuenta', $m->cuenta_id],
            $m instanceof MovimientoTarjeta => ['tarjeta', 'Tarjeta '.($m->tarjeta?->nombre ?? ''), $m->tarjeta_credito_id],
            default => ['efectivo', 'Efectivo', null],
        };

        return ['clase' => $clase, 'id' => $m->id, 'lugar_id' => $lugar, 'fecha' => $m->fecha->toDateString(), 'descripcion' => $m->descripcion, 'origen' => $origen,
            'monto' => round((float) $m->monto, 2), 'cobrado' => $m->cobrado ? round((float) $m->cobrado, 2) : null];
    }

    /**
     * Cada préstamo activo con cuota o con pagos en el ciclo: la cuota del mes (cuota + cargos) frente a lo pagado (capital,
     * interés, cargos y mora; la parte de planilla ya va dentro). pagada = cubrió la cuota; extra = lo pagado de más (sin la
     * mora); falta = lo que aún no se paga; fecha = el último pago del ciclo.
     *
     * @return array{lista: array<int, array{id: int, nombre: string, cuota: float, pagado: float, mora: float, extra: float, falta: float, pagada: bool, fecha: ?string}>, cuotas: int, pagadas: int, cuota: float, pagado: float, extra: float, falta: float}
     */
    public function prestamosDelCiclo(User $user, Carbon $inicio, Carbon $fin): array
    {
        $lista = $user->prestamos()->where('activo', true)->with('cargos')->orderBy('nombre')->get()->map(function ($p) use ($inicio, $fin) {
            $cuota = round(($this->prestamos->cuota($p) ?? 0) + $this->prestamos->cargosMensuales($p), 2);
            $pagos = $p->pagos()->whereDate('fecha', '>=', $inicio->toDateString())->whereDate('fecha', '<=', $fin->toDateString())->get();
            $mora = round((float) $pagos->sum('mora'), 2);
            $pagado = round((float) $pagos->sum(fn ($g) => $g->capital + $g->interes + $g->cargos + $g->mora), 2);
            $falta = round(max(0, $cuota - ($pagado - $mora)), 2);

            return ['id' => $p->id, 'nombre' => $p->nombre, 'cuota' => $cuota, 'pagado' => $pagado, 'mora' => $mora,
                'extra' => round(max(0, $pagado - $mora - $cuota), 2), 'falta' => $falta, 'pagada' => $cuota > 0 && $falta < 0.005,
                'fecha' => $pagos->max(fn ($g) => $g->fecha->toDateString())];
        })->filter(fn ($p) => $p['cuota'] > 0 || $p['pagado'] > 0)->values();

        return ['lista' => $lista->all(), 'cuotas' => $lista->where('cuota', '>', 0)->count(), 'pagadas' => $lista->where('pagada', true)->count(),
            'cuota' => round($lista->sum('cuota'), 2), 'pagado' => round($lista->sum('pagado'), 2),
            'extra' => round($lista->sum('extra'), 2), 'falta' => round($lista->sum('falta'), 2)];
    }

    /**
     * Cómo se movió el dinero entre tarjetas en el ciclo: lo que se sacó de cada una (avances a efectivo o a una cuenta,
     * y pagos a otra tarjeta hechos con ella) y lo que se le pagó. flujos = de dónde a dónde, para verlo de un vistazo.
     *
     * total = todo lo sacado de las tarjetas en el ciclo (0 = no hubo avances ni pagos con otra tarjeta: no se muestra).
     *
     * @return array{lista: array<int, array{id: int, nombre: string, color: ?string, avances: float, pagos_a_otras: float, sacado: float, recibido: float}>, flujos: array<int, array{de: string, a: string, monto: float, tipo: string}>, total: float}
     */
    public function avancesDelCiclo(User $user, Carbon $inicio, Carbon $fin): array
    {
        $enCiclo = fn ($q) => $q->whereDate('fecha', '>=', $inicio->toDateString())->whereDate('fecha', '<=', $fin->toDateString());
        $flujos = collect();
        $lista = $user->tarjetasCredito()->where('activo', true)->orderBy('nombre')->get()->map(function (TarjetaCredito $t) use ($enCiclo, $flujos, $inicio, $fin) {
            $avances = $enCiclo(MovimientoTarjeta::with('transferencia.destinoCuenta')->where('tarjeta_credito_id', $t->id)->where('tipo', 'avance'))->get();
            foreach ($avances as $a) {
                $flujos->push(['de' => $t->nombre, 'a' => $a->transferencia?->nombreDestino() ?? 'Efectivo', 'monto' => (float) $a->monto, 'tipo' => 'avance']);
            }
            $pagosAOtras = $enCiclo(MovimientoTarjeta::with('pagoCompromiso.tarjeta')->where('tarjeta_credito_id', $t->id)->whereNotNull('pago_compromiso_id'))->get();
            foreach ($pagosAOtras as $p) {
                $flujos->push(['de' => $t->nombre, 'a' => 'Tarjeta '.($p->pagoCompromiso?->tarjeta?->nombre ?? ''), 'monto' => (float) $p->monto, 'tipo' => 'pago']);
            }
            $recibido = $this->tarjetas->pagos($t, 500)->filter(fn ($p) => $p['fecha']->toDateString() >= $inicio->toDateString() && $p['fecha']->toDateString() <= $fin->toDateString())->sum('monto');
            [$av, $po] = [round((float) $avances->sum('monto'), 2), round((float) $pagosAOtras->sum('monto'), 2)];

            return ['id' => $t->id, 'nombre' => $t->nombre, 'color' => $t->color, 'avances' => $av, 'pagos_a_otras' => $po,
                'sacado' => round($av + $po, 2), 'recibido' => round((float) $recibido, 2)];
        })->filter(fn ($t) => $t['sacado'] > 0 || $t['recibido'] > 0)->values();

        $flujos = $flujos->groupBy(fn ($f) => $f['de'].'→'.$f['a'].'|'.$f['tipo'])
            ->map(fn ($g) => ['de' => $g[0]['de'], 'a' => $g[0]['a'], 'monto' => round((float) $g->sum('monto'), 2), 'tipo' => $g[0]['tipo']])
            ->sortByDesc('monto')->values();

        return ['lista' => $lista->all(), 'flujos' => $flujos->all(), 'total' => round((float) $flujos->sum('monto'), 2)];
    }

    /** El "cuadrito" de la hoja: cada tarjeta con corte, fecha límite, límite, disponible y lo pendiente de pago. */
    private function tarjetas(User $user): array
    {
        $lista = $user->tarjetasCredito()->with('banco', 'marcaRed')->where('activo', true)->orderBy('dia_corte')->get()
            ->map(function (TarjetaCredito $t) {
                $pendiente = $t->compromisos()->where('estado', Compromiso::PENDIENTE)->orderBy('fecha_esperada')->get();

                return ['id' => $t->id, 'nombre' => $t->nombre, 'color' => $t->color, 'banco' => $t->banco, 'marca' => $t->marcaRed, 'user_id' => $t->user_id,
                    'dia_corte' => $t->dia_corte, 'dia_pago' => $t->dia_pago, 'limite' => (float) $t->limite_credito,
                    'disponible' => $this->tarjetas->disponible($t), 'deuda' => $this->tarjetas->deuda($t), 'permite_avances' => (bool) $t->permite_avances,
                    'por_pagar' => round((float) $pendiente->sum('saldo_pendiente'), 2), 'vence' => $pendiente->first()?->fecha_esperada?->toDateString()];
            });

        return ['lista' => $lista->values()->all(), 'totales' => [
            'limite' => round($lista->sum('limite'), 2),
            'disponible' => round($lista->sum('disponible'), 2),
            'disponible_avances' => round($lista->where('permite_avances', true)->sum(fn ($t) => max(0, $t['disponible'])), 2),
            'deuda' => round($lista->sum('deuda'), 2),
            'por_pagar' => round($lista->sum('por_pagar'), 2),
        ]];
    }

    /** @return array<int, array{nombre: string, color: ?string, monto: float}> de mayor a menor */
    public function gastosPorCategoria(User $user, Carbon $inicio, Carbon $fin): array
    {
        return $this->movimientosDeGasto($user, $inicio, $fin)
            ->groupBy(fn ($m) => $m->categoria->nombre)
            ->map(fn (Collection $g, $nombre) => ['nombre' => $nombre, 'color' => $g->first()->categoria->color, 'monto' => round((float) $g->sum('monto'), 2)])
            ->sortByDesc('monto')->values()->all();
    }

    /** Ingresos y gastos de los últimos ciclos (el actual incluido). */
    public function historial(User $user, Carbon $fin, int $ciclos = 6): array
    {
        $primera = $this->ciclos->primeraFecha($user);
        $resultado = [];
        $fecha = $fin->copy();
        for ($i = 0; $i < $ciclos; $i++) {
            [$ini, $fi] = $this->ciclos->cycleFor($user, $fecha);
            if ($primera && $fi->toDateString() < $primera->toDateString()) {
                break;
            }
            $resultado[] = ['inicio' => $ini->toDateString(), 'fin' => $fi->toDateString(),
                'ingresos' => $this->ingresosEntre($user, $ini, $fi), 'gastos' => round((float) $this->movimientosDeGasto($user, $ini, $fi)->sum('monto'), 2)];
            $fecha = $ini->copy()->subDay();
        }

        return array_reverse($resultado);
    }

    /**
     * Movimientos que cuentan como gasto (efectivo, cuentas y tarjetas), con su categoría. Ver la definición arriba.
     * El pago de un evento cuenta sin lo ya cobrado a los contactos (CobrosDeEventos).
     */
    public function movimientosDeGasto(User $user, Carbon $inicio, Carbon $fin): Collection
    {
        $rango = fn ($q) => $q->whereDate('fecha', '>=', $inicio->toDateString())->whereDate('fecha', '<=', $fin->toDateString())
            ->whereNotNull('categoria_personal_id')->whereHas('categoria', fn ($c) => $c->whereNotIn('nombre', self::NO_SON_GASTO))->with('categoria');

        return CobrosDeEventos::descontar(collect()
            ->concat($rango(MovimientoEfectivo::delHogar($user)->where('tipo', 'egreso')->whereNull('transferencia_id'))->get())
            ->concat($rango(MovimientoCuenta::delHogar($user)->where('tipo', 'egreso')->whereNull('transferencia_id'))->with('cuenta')->get())
            ->concat($rango(MovimientoTarjeta::delHogar($user)->whereIn('tipo', ['compra', 'cargo']))->with('tarjeta')->get()));
    }

    /** Movimientos que cuentan como ingreso (efectivo y cuentas), con su categoría. */
    public function movimientosDeIngreso(User $user, Carbon $inicio, Carbon $fin): Collection
    {
        $rango = fn ($q) => $q->where('tipo', 'ingreso')->whereNull('transferencia_id')
            ->whereDate('fecha', '>=', $inicio->toDateString())->whereDate('fecha', '<=', $fin->toDateString())
            ->whereHas('categoria', fn ($c) => $c->whereNotIn('nombre', self::NO_SON_INGRESO))->with('categoria');

        return collect()
            ->concat($rango(MovimientoEfectivo::delHogar($user)->where('es_saldo_arrastrado', false))->get())
            ->concat($rango(MovimientoCuenta::delHogar($user))->with('cuenta')->get());
    }

    private function ingresosEntre(User $user, Carbon $inicio, Carbon $fin): float
    {
        return round((float) $this->movimientosDeIngreso($user, $inicio, $fin)->sum('monto'), 2);
    }

    /** Días de calendario entre dos fechas (sin horas ni zonas horarias: ver CLAUDE.md, "Fechas"). */
    private function diasEntre(Carbon $desde, Carbon $hasta): int
    {
        return (int) Carbon::parse($desde->toDateString())->diffInDays(Carbon::parse($hasta->toDateString()), false);
    }

    /** Lo que hay que atender hoy: vencidos, lo que vence pronto, tarjetas sobregiradas e ingresos del día. */
    private function alertas(User $user, Carbon $hoy, array $tarjetas, ?array $tope = null, ?Collection $presupuestos = null): array
    {
        $alertas = [];
        $dinero = fn (float $v) => '$ '.number_format($v, 2);
        if ($tope && $tope['queda'] < 0) {
            $alertas[] = ['nivel' => 'critico', 'etiqueta' => 'Tope', 'texto' => 'Te pasaste del tope del ciclo por '.$dinero(-$tope['queda']).' (llevas '.$dinero($tope['gastado']).' de '.$dinero($tope['tope']).')', 'url' => route('dashboard')];
        } elseif ($tope && $tope['porcentaje'] >= 80) {
            $alertas[] = ['nivel' => 'serio', 'etiqueta' => 'Tope', 'texto' => "Llevas el {$tope['porcentaje']} % del tope del ciclo: te quedan ".$dinero($tope['queda']).' para '.($tope['dias_restantes'] + 1).' días', 'url' => route('dashboard')];
        }
        foreach ($presupuestos ?? [] as $p) {
            if ($p['queda'] < 0) {
                $alertas[] = ['nivel' => 'serio', 'etiqueta' => 'Presupuesto', 'texto' => "{$p['descripcion']}: te pasaste por ".$dinero(-$p['queda']).' (gastado '.$dinero($p['gastado']).' de '.$dinero($p['monto']).')', 'url' => route('compromisos.show', $p['id'])];
            } elseif ($p['monto'] > 0 && $p['gastado'] >= $p['monto'] * 0.8) {
                $alertas[] = ['nivel' => 'aviso', 'etiqueta' => 'Presupuesto', 'texto' => "{$p['descripcion']}: te quedan ".$dinero($p['queda']).' de '.$dinero($p['monto']), 'url' => route('compromisos.show', $p['id'])];
            }
        }
        $pendientes = $user->compromisos()->where('estado', Compromiso::PENDIENTE)->where('es_presupuesto', false)->whereNotNull('fecha_esperada')
            ->whereDate('fecha_esperada', '<=', $hoy->copy()->addDays(3)->toDateString())->orderBy('fecha_esperada')->get();
        foreach ($pendientes as $c) {
            $dias = $this->diasEntre($hoy, $c->fecha_esperada);
            $alertas[] = [
                'nivel' => $dias < 0 ? 'critico' : ($dias === 0 ? 'serio' : 'aviso'),
                'etiqueta' => $dias < 0 ? 'Vencido' : ($dias === 0 ? 'Hoy' : 'Pronto'),
                'texto' => $c->descripcion.': $ '.number_format((float) $c->saldo_pendiente, 2).' '.match (true) {
                    $dias < 0 => 'venció hace '.abs($dias).(abs($dias) === 1 ? ' día' : ' días'),
                    $dias === 0 => 'vence hoy',
                    $dias === 1 => 'vence mañana',
                    default => "vence en {$dias} días",
                },
                'url' => route('compromisos.show', $c),
            ];
        }
        foreach ($tarjetas as $t) {
            if ($t['disponible'] < 0) {
                $alertas[] = ['nivel' => 'serio', 'etiqueta' => 'Atención', 'texto' => "{$t['nombre']} está sobre su límite por $ ".number_format(abs($t['disponible']), 2), 'url' => route('tarjetas.show', $t['id'])];
            }
        }
        foreach ($user->fuentesIngreso()->where('activo', true)->get() as $f) {
            $proxima = $this->ingresos->proximaFecha($f, $hoy);
            if ($proxima && $proxima->toDateString() === $hoy->toDateString() && ! $f->ingresos()->whereDate('fecha', $hoy->toDateString())->exists()) {
                $alertas[] = ['nivel' => 'info', 'etiqueta' => 'Ingreso', 'texto' => "Hoy es día de pago de «{$f->nombre}»: regístralo para que entre a la cuenta", 'url' => route('ingresos.show', $f)];
            }
        }

        $orden = ['critico' => 0, 'serio' => 1, 'aviso' => 2, 'info' => 3];
        // Clave estable del aviso en el ciclo (cambia si sube de nivel): con ella la campana sabe cuáles ya se abrieron
        $ciclo = $this->compromisos->fechas($user)[1]->toDateString();

        return collect($alertas)->sortBy(fn ($a) => $orden[$a['nivel']])
            ->map(fn ($a) => $a + ['clave' => substr(sha1($ciclo.'|'.$a['etiqueta'].'|'.$a['url'].'|'.$a['nivel']), 0, 16)])->values()->all();
    }
}
