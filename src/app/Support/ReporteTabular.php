<?php

namespace App\Support;

use Carbon\Carbon;

/**
 * Un reporte como secciones de tabla (título, columnas, filas y total) para exportarlo igual en PDF, XLSX y CSV.
 * La pantalla web tiene su propia vista más rica; esto es solo para los archivos.
 */
class ReporteTabular
{
    public const TITULOS = [
        'ejecucion' => 'Ejecución financiera',
        'gastos' => 'Gastos por categoría',
        'prestamos' => 'Préstamos',
        'estado-cuenta' => 'Estado de cuenta',
    ];

    /**
     * @param  callable(?int): ?string  $quien  nombre corto de un miembro del hogar
     * @return array<int, array{titulo: string, columnas: array<int, string>, numericas: array<int, int>, filas: array<int, array>, total: ?array}>
     */
    public static function secciones(string $reporte, array $d, callable $quien): array
    {
        $f = fn (?string $fecha) => $fecha ? Carbon::parse($fecha)->format('d/m/Y') : '';

        return match ($reporte) {
            'ejecucion' => [
                self::seccion('Resumen', ['Concepto', 'Monto'], [1], [
                    ['Ingresos', $d['totales']['ingresos']],
                    ['Gastos', $d['totales']['gastos']],
                    ['Ahorro (ingresos − gastos)', $d['totales']['ahorro']],
                    ['Efectivo al final', $d['totales']['efectivo']],
                    ['En cuentas al final', $d['totales']['cuentas']],
                    ['Compromisos por pagar', $d['totales']['por_pagar']],
                    ['Al pagar los compromisos quedaría', $d['totales']['liquidez']],
                    ['Tarjetas: por pagar (hoy)', $d['totales']['tarjetas_por_pagar']],
                    ['Tarjetas: disponible (hoy)', $d['totales']['tarjetas_disponible']],
                    ['Nos deben (hoy)', $d['totales']['nos_deben']],
                ]),
                self::seccion('Ingresos', ['Fecha', 'Descripción', 'Categoría', 'Dónde', 'Quién', 'Monto'], [5],
                    array_map(fn ($m) => [$f($m['fecha']), $m['descripcion'], $m['categoria'], $m['donde'], $quien($m['user_id']), $m['monto']], $d['ingresos']),
                    ['Total', '', '', '', '', $d['totales']['ingresos']]),
                self::seccion('Gastos por categoría', ['Categoría', 'Movimientos', '%', 'Monto'], [2, 3],
                    array_map(fn ($c) => [$c['nombre'], $c['cantidad'], $c['porcentaje'], $c['monto']], $d['gastos']),
                    ['Total', '', '', $d['totales']['gastos']]),
                self::seccion('Efectivo', ['Saldo inicial', 'Entró', 'Salió', 'Saldo final'], [0, 1, 2, 3],
                    [[$d['efectivo']['saldo_inicial'], $d['efectivo']['ingresos'], $d['efectivo']['egresos'], $d['efectivo']['saldo_final']]]),
                self::seccion('Cuentas', ['Cuenta', 'Saldo inicial', 'Entró', 'Salió', 'Saldo final'], [1, 2, 3, 4],
                    array_map(fn ($c) => [$c['nombre'], $c['saldo_inicial'], $c['ingresos'], $c['egresos'], $c['saldo_final']], $d['cuentas']),
                    ['Total', '', '', '', $d['totales']['cuentas']]),
                self::seccion('Compromisos', ['Fecha', 'Descripción', 'Estado', 'Monto', 'Pagado', 'Pendiente'], [3, 4, 5],
                    array_map(fn ($c) => [$f($c['fecha']), $c['descripcion'], $c['estado_texto'], $c['monto'], $c['pagado'], $c['pendiente']], $d['compromisos']),
                    ['Total', '', '', $d['totales']['compromisos'], $d['totales']['compromisos_pagado'], $d['totales']['por_pagar']]),
                self::seccion('Tarjetas (hoy, por fecha límite de pago)', ['Tarjeta', 'Titular', 'Fecha límite', 'Por pagar', 'Próximo corte', 'Disponible', 'Deuda'], [3, 5, 6],
                    array_map(fn ($t) => [$t['nombre'], $quien($t['user_id']), $f($t['fecha_limite']), $t['por_pagar'], $f($t['proximo_corte']), $t['disponible'], $t['deuda']], $d['tarjetas']),
                    ['Total', '', '', $d['totales']['tarjetas_por_pagar'], '', $d['totales']['tarjetas_disponible'], $d['totales']['tarjetas_deuda']]),
                self::seccion('Nos deben (hoy)', ['Contacto', 'Pendiente'], [1],
                    array_map(fn ($c) => [$c['nombre'], $c['pendiente']], $d['nos_deben']),
                    ['Total', $d['totales']['nos_deben']]),
            ],
            'gastos' => [
                self::seccion('Por categoría', array_merge(['Categoría'], array_map(fn ($c) => $f($c['inicio']).' – '.$f($c['fin']), $d['ciclos']), ['Total', '%']),
                    range(1, count($d['ciclos']) + 2),
                    array_map(fn ($c) => array_merge([$c['nombre']], $c['por_ciclo'], [$c['monto'], $c['porcentaje']]), $d['categorias']),
                    array_merge(['Total'], $d['por_ciclo'], [$d['total'], 100])),
                self::seccion('Detalle', ['Categoría', 'Fecha', 'Descripción', 'Dónde', 'Quién', 'Monto'], [5],
                    array_merge(...array_map(fn ($c) => array_map(fn ($m) => [$c['nombre'], $f($m['fecha']), $m['descripcion'], $m['donde'], $quien($m['user_id']), $m['monto']], $c['movimientos']), $d['categorias'] ?: [['movimientos' => []]]))),
            ],
            'prestamos' => array_merge(
                [self::seccion('Resumen', ['Préstamo', 'Acreedor', 'Original', 'Saldo', 'Cuota al mes', 'Pagos', 'Capital pagado', 'Intereses pagados', 'Intereses que faltan', 'Termina'], [2, 3, 4, 6, 7, 8],
                    array_map(fn ($p) => [$p['nombre'].($p['activo'] ? '' : ' (pagado)'), $p['acreedor'], $p['monto_original'], $p['saldo'], $p['cuota_total'], $p['pagos_realizados'],
                        $p['pagado_capital'], $p['pagado_intereses'], $p['intereses_restantes'], $f($p['fecha_fin'])], $d['prestamos']),
                    ['Total', '', '', $d['totales']['saldo'], $d['totales']['cuotas_al_mes'], '', $d['totales']['pagado_capital'], $d['totales']['pagado_intereses'], $d['totales']['intereses_restantes'], ''])],
                array_map(fn ($p) => self::seccion('Pagos de '.$p['nombre'], ['Fecha', 'Capital', 'Interés', 'Cargos y mora', 'Total'], [1, 2, 3, 4],
                    array_map(fn ($pg) => [$f($pg['fecha']), $pg['capital'], $pg['interes'], $pg['cargos'], $pg['total']], $p['pagos']),
                    ['Total', $p['pagado_capital'], $p['pagado_intereses'], $p['pagado_cargos'], $p['pagado_total']]), array_values(array_filter($d['prestamos'], fn ($p) => $p['pagos'])))
            ),
            'estado-cuenta' => $d ? [
                self::seccion('Tarjeta '.$d['tarjeta']['nombre'].' · corte del '.$f($d['fecha_corte']), ['Fecha', 'Descripción', 'Categoría', 'Cuota', 'Monto'], [4],
                    array_map(fn ($l) => [$f($l['fecha']), $l['descripcion'], $l['categoria'], $l['cuota'], $l['monto']], $d['lineas']),
                    ['Total', '', '', '', $d['total']]),
                self::seccion('Pago', ['Fecha límite', 'Total', 'Pagado', 'Pendiente'], [1, 2, 3],
                    [[$f($d['fecha_limite_pago']), $d['total'], $d['pagado'], $d['pendiente']]]),
            ] : [],
            default => [],
        };
    }

    private static function seccion(string $titulo, array $columnas, array $numericas, array $filas, ?array $total = null): array
    {
        return compact('titulo', 'columnas', 'numericas', 'filas', 'total');
    }
}
