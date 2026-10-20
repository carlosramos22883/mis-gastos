<?php

namespace App\Services;

use App\Models\Compromiso;
use App\Models\MovimientoCuenta;
use App\Models\MovimientoEfectivo;
use App\Models\MovimientoTarjeta;
use App\Models\TarjetaCredito;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

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
    public const NO_SON_GASTO = ['Movimientos entre cuentas', 'Ajustes', 'Préstamos a terceros', 'Pago de avances de tarjeta'];

    public const NO_SON_INGRESO = ['Saldo inicial', 'Movimientos entre cuentas', 'Ajustes', 'Avances de tarjeta', 'Préstamos recibidos', 'Cobros de deudas'];

    public function __construct(
        private readonly CicloEfectivoService $ciclos,
        private readonly CuentaService $cuentas,
        private readonly TarjetaService $tarjetas,
        private readonly PrestamoService $prestamos,
        private readonly DeudorService $deudores,
        private readonly IngresoService $ingresos,
        private readonly CompromisoService $compromisos,
    ) {}

    public function resumen(User $user): array
    {
        $this->ciclos->closeIfNeeded($user);
        $this->tarjetas->generarPendientes($user);
        [$hoy, $inicio, $fin] = $this->compromisos->fechas($user);

        $efectivo = round($this->ciclos->balance($user), 2);
        $cuentas = $user->cuentas()->with('banco')->where('activo', true)->orderBy('nombre')->get()
            ->map(fn ($c) => ['id' => $c->id, 'nombre' => $c->nombre, 'color' => $c->color, 'banco' => $c->banco, 'user_id' => $c->user_id, 'saldo' => $this->cuentas->saldo($c)]);
        $tarjetas = $this->tarjetas($user);
        $pendientes = $user->compromisos()->where('estado', Compromiso::PENDIENTE)
            ->where(fn ($q) => $q->whereNull('fecha_esperada')->orWhereDate('fecha_esperada', '<=', $fin->toDateString()))->get();
        $porPagarCiclo = round((float) $pendientes->whereNotNull('fecha_esperada')->sum('saldo_pendiente'), 2);
        $prestamos = $user->prestamos()->where('activo', true)->with('cargos')->get()->map(fn ($p) => ['saldo' => $this->prestamos->saldo($p), 'cuota' => $this->prestamos->montoPorPagar($p) ?? 0]);
        $totalCuentas = round($cuentas->sum('saldo'), 2);

        return [
            'hoy' => $hoy->toDateString(),
            'ciclo' => ['inicio' => $inicio->toDateString(), 'fin' => $fin->toDateString(), 'dias_restantes' => $this->diasEntre($hoy, $fin)],
            'efectivo' => $efectivo,
            'cuentas' => $cuentas->values()->all(),
            'total_cuentas' => $totalCuentas,
            'tarjetas' => $tarjetas['lista'],
            'tarjetas_totales' => $tarjetas['totales'],
            'por_pagar_ciclo' => $porPagarCiclo,
            'liquidez' => round($efectivo + $totalCuentas - $porPagarCiclo, 2),
            'deuda_prestamos' => round($prestamos->sum('saldo'), 2),
            'cuotas_prestamos' => round($prestamos->sum('cuota'), 2),
            'nos_deben' => round($this->deudores->listar($user)->sum('pendiente'), 2),
            'gastos_por_categoria' => $this->gastosPorCategoria($user, $inicio, $fin),
            'historial' => $this->historial($user, $fin),
            'alertas' => $this->alertas($user, $hoy, $tarjetas['lista']),
            'proximos' => $user->compromisos()->with('tarjeta')->where('estado', Compromiso::PENDIENTE)->whereNotNull('fecha_esperada')
                ->orderBy('fecha_esperada')->limit(8)->get()
                ->map(fn ($c) => ['id' => $c->id, 'descripcion' => $c->descripcion, 'fecha' => $c->fecha_esperada->toDateString(), 'monto' => (float) $c->saldo_pendiente,
                    'vencido' => $c->fecha_esperada->toDateString() < $hoy->toDateString(), 'color' => $c->tarjeta?->color])->all(),
        ];
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
        return $this->gastos($user, $inicio, $fin)
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
                'ingresos' => $this->ingresosEntre($user, $ini, $fi), 'gastos' => round((float) $this->gastos($user, $ini, $fi)->sum('monto'), 2)];
            $fecha = $ini->copy()->subDay();
        }

        return array_reverse($resultado);
    }

    private function gastos(User $user, Carbon $inicio, Carbon $fin): Collection
    {
        $rango = fn ($q) => $q->whereDate('fecha', '>=', $inicio->toDateString())->whereDate('fecha', '<=', $fin->toDateString())
            ->whereNotNull('categoria_personal_id')->whereHas('categoria', fn ($c) => $c->whereNotIn('nombre', self::NO_SON_GASTO))->with('categoria');

        return collect()
            ->concat($rango(MovimientoEfectivo::delHogar($user)->where('tipo', 'egreso')->whereNull('transferencia_id'))->get())
            ->concat($rango(MovimientoCuenta::delHogar($user)->where('tipo', 'egreso')->whereNull('transferencia_id'))->get())
            ->concat($rango(MovimientoTarjeta::delHogar($user)->whereIn('tipo', ['compra', 'cargo']))->get());
    }

    private function ingresosEntre(User $user, Carbon $inicio, Carbon $fin): float
    {
        $suma = fn ($q) => (float) $q->where('tipo', 'ingreso')->whereNull('transferencia_id')
            ->whereDate('fecha', '>=', $inicio->toDateString())->whereDate('fecha', '<=', $fin->toDateString())
            ->whereHas('categoria', fn ($c) => $c->whereNotIn('nombre', self::NO_SON_INGRESO))->sum('monto');

        return round($suma(MovimientoEfectivo::delHogar($user)->where('es_saldo_arrastrado', false)) + $suma(MovimientoCuenta::delHogar($user)), 2);
    }

    /** Días de calendario entre dos fechas (sin horas ni zonas horarias: ver CLAUDE.md, "Fechas"). */
    private function diasEntre(Carbon $desde, Carbon $hasta): int
    {
        return (int) Carbon::parse($desde->toDateString())->diffInDays(Carbon::parse($hasta->toDateString()), false);
    }

    /** Lo que hay que atender hoy: vencidos, lo que vence pronto, tarjetas sobregiradas e ingresos del día. */
    private function alertas(User $user, Carbon $hoy, array $tarjetas): array
    {
        $alertas = [];
        $pendientes = $user->compromisos()->where('estado', Compromiso::PENDIENTE)->whereNotNull('fecha_esperada')
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

        return collect($alertas)->sortBy(fn ($a) => $orden[$a['nivel']])->values()->all();
    }
}
