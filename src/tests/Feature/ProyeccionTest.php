<?php

namespace Tests\Feature;

use App\Services\ProyeccionService;
use Tests\TestCase;

/** El motor de proyecciones con una foto armada a mano (sin base de datos). */
class ProyeccionTest extends TestCase
{
    private function base(array $deudas, float $ingreso = 1000, float $gasto = 600): array
    {
        return ['mes_inicio' => '2027-01', 'ingreso' => $ingreso, 'gasto' => $gasto, 'gasto_origen' => 'tope', 'deudas' => $deudas];
    }

    private function tarjeta(string $clave, float $saldo, float $tasa, float $limite = 5000): array
    {
        return ['clave' => $clave, 'tipo' => 'tarjeta', 'nombre' => $clave, 'saldo' => $saldo, 'tasa' => $tasa, 'limite' => $limite];
    }

    private function simular(array $base, array $escenario = []): array
    {
        return app(ProyeccionService::class)->simular($base, $escenario);
    }

    public function test_lo_que_sobra_paga_primero_la_tarjeta_mas_cara_y_luego_a_personas(): void
    {
        $r = $this->simular($this->base([
            $this->tarjeta('barata', 400, 12), $this->tarjeta('cara', 400, 48),
            ['clave' => 'mama', 'tipo' => 'personal', 'nombre' => 'Mamá', 'saldo' => 300, 'tasa' => 0],
        ]));

        // Sobran $400 al mes y los intereses del mes se suman antes de pagar: la cara (48 %) se termina en febrero,
        // la barata (12 %) en marzo y con lo que queda de marzo se le paga a mamá
        $deudas = collect($r['deudas'])->keyBy('clave');
        $this->assertSame(['2027-02', '2027-03', '2027-03'], [$deudas['cara']['cero'], $deudas['barata']['cero'], $deudas['mama']['cero']]);
        $this->assertSame(['tarjetas' => '2027-03', 'personas' => '2027-03', 'sin_deudas' => '2027-03'], $r['hitos']);
        $this->assertSame(24.93, $r['totales']['intereses_tarjetas']);
        $this->assertSame(75.07, $r['meses'][2]['ahorro']);
    }

    public function test_rotar_con_avances_pierde_un_porcentaje_y_lo_que_falta_se_va_a_las_tarjetas(): void
    {
        $base = $this->base([$this->tarjeta('t', 1000, 40)], 1000, 1100);

        $r = $this->simular($base, ['modo_tarjetas' => 'rotar', 'perdida_rotar' => 2]);
        $primero = $r['meses'][0];
        $this->assertSame([100.0, 1120.0], [$primero['faltante'], $primero['tarjetas']]); // 1000 + 2 % + 100 que faltaron
        $this->assertNull($r['hitos']['tarjetas']);
        $this->assertCount(ProyeccionService::MAX_MESES, $r['meses']); // nunca termina
    }

    public function test_prestamo_nuevo_y_venta_pagan_sus_destinos_y_un_pago_decidido_sale_ese_mes(): void
    {
        $base = $this->base([
            $this->tarjeta('t', 2000, 45),
            ['clave' => 'marlene', 'tipo' => 'personal', 'nombre' => 'Marlene', 'saldo' => 1000, 'tasa' => 0],
            ['clave' => 'evelyn', 'tipo' => 'personal', 'nombre' => 'Evelyn', 'saldo' => 500, 'tasa' => 0],
        ]);

        $r = $this->simular($base, ['palancas' => [
            ['tipo' => 'prestamo', 'mes' => '2027-01', 'monto' => 1100, 'tasa' => 12, 'plazo' => 12, 'comision' => 100, 'destino' => ['marlene']],
            ['tipo' => 'pagar', 'mes' => '2027-02', 'deuda' => 'evelyn'],
            ['tipo' => 'venta', 'mes' => '2027-03', 'monto' => 2000, 'destino' => ['t']],
        ]]);
        $deudas = collect($r['deudas'])->keyBy('clave');

        $this->assertSame(['2027-01', '2027-02', '2027-03'], [$deudas['marlene']['cero'], $deudas['evelyn']['cero'], $deudas['t']['cero']]);
        $this->assertSame(100.0, $r['totales']['comisiones']);
        $this->assertSame([0.0, 97.73], [$r['meses'][0]['cuotas'], $r['meses'][1]['cuotas']]); // la cuota del préstamo empieza al mes siguiente
        $this->assertSame(197.73, $r['meses'][1]['faltante']); // pagarle a Evelyn en febrero no alcanzaba: se fue a la tarjeta
        $this->assertSame('2027-02', $r['hitos']['personas']);
    }

    public function test_gasto_e_ingreso_cambian_desde_un_mes_y_un_prestamo_de_planilla_pagado_libera_ingreso(): void
    {
        $base = $this->base([
            ['clave' => 'banco', 'tipo' => 'banco', 'nombre' => 'Consumo', 'saldo' => 300, 'tasa' => 0, 'cuota' => 100, 'cargos' => 0, 'cuota_efectivo' => 0, 'hipoteca' => false],
            ['clave' => 'casa', 'tipo' => 'banco', 'nombre' => 'Casa', 'saldo' => 50000, 'tasa' => 6, 'cuota' => 400, 'cargos' => 0, 'cuota_efectivo' => 400, 'hipoteca' => true],
            $this->tarjeta('otra', 3000, 0),
        ], 1000, 600);

        $r = $this->simular($base, ['sobrante' => 'ahorrar', 'palancas' => [
            ['tipo' => 'gasto', 'desde' => '2027-02', 'monto' => 500], ['tipo' => 'ingreso', 'desde' => '2027-03', 'hasta' => '2027-03', 'monto' => 50],
        ]]);
        $meses = collect($r['meses'])->keyBy('mes');

        $this->assertSame([600.0, 500.0, 500.0], [$meses['2027-01']['gasto'], $meses['2027-02']['gasto'], $meses['2027-03']['gasto']]);
        $this->assertSame(1050.0, $meses['2027-03']['ingreso']); // ingreso extra solo en marzo
        $this->assertSame(1100.0, $meses['2027-04']['ingreso']); // la planilla del préstamo terminado vuelve al sueldo
        $this->assertSame('2027-03', collect($r['deudas'])->firstWhere('clave', 'banco')['cero']);
        $this->assertNotNull($r['hitos']['sin_deudas']); // la hipoteca no cuenta: termina al pagar la tarjeta
        $this->assertLessThan(50000, $meses['2027-03']['hipoteca']);
    }

    public function test_orden_de_pago_la_mas_pequena_primero_o_el_que_yo_elija(): void
    {
        $base = $this->base([
            $this->tarjeta('grande_cara', 1000, 48), $this->tarjeta('chica_barata', 100, 12),
            ['clave' => 'evelyn', 'tipo' => 'personal', 'nombre' => 'Evelyn', 'saldo' => 300, 'tasa' => 0],
        ], 1000, 800); // sobran $200 al mes
        $cero = fn (array $esc) => collect($this->simular($base, $esc)['deudas'])->pluck('cero', 'clave')->all();

        // La más cara primero: la chica espera; la más pequeña primero: la chica sale en el primer mes
        $this->assertSame('2027-06', $cero([])['grande_cara']);
        $this->assertSame('2027-01', $cero(['orden' => 'mas_pequena'])['chica_barata']);
        // Personalizado: Evelyn antes que cualquier tarjeta
        $this->assertSame('2027-02', $cero(['orden' => 'personalizado', 'prioridad' => ['evelyn']])['evelyn']);
    }

    public function test_pasar_una_tarjeta_a_cuotas_y_meta_de_cuanto_necesito_al_mes(): void
    {
        $base = $this->base([$this->tarjeta('t', 2400, 48)], 1000, 900); // sobran $100 al mes
        $s = app(ProyeccionService::class);

        // A cuotas: 12 meses al 0 % → $200 de cuota; la tarjeta queda en cero y ya no paga su 48 %
        $holgado = $this->base([$this->tarjeta('t', 2400, 48)], 1000, 750); // sobran $250
        $r = $s->simular($holgado, ['palancas' => [['tipo' => 'cuotas', 'mes' => '2027-01', 'deuda' => 't', 'tasa' => 0, 'plazo' => 12, 'comision' => 24]]]);
        $this->assertSame('2027-01', collect($r['deudas'])->firstWhere('clave', 't')['cero']);
        $this->assertSame([0.0, 0.0, 24.0], [$r['totales']['intereses_tarjetas'], $r['totales']['faltante'], $r['totales']['comisiones']]);
        $this->assertSame(202.0, $r['meses'][1]['cuotas']); // (2400 + 24) / 12

        // Si la cuota no cabe en lo que sobra, lo que falta vuelve a la tarjeta (y paga su tasa)
        $apretado = $s->simular($base, ['palancas' => [['tipo' => 'cuotas', 'mes' => '2027-01', 'deuda' => 't', 'tasa' => 0, 'plazo' => 12]]]);
        $this->assertSame(100.0, $apretado['meses'][1]['faltante']);

        // Meta: tarjetas en $0 en diciembre 2027 (12 meses): hace falta poco más de $2,400 + intereses / 12 − 100
        $meta = $s->meta($base, [], ['que' => 'tarjetas', 'mes' => '2027-12']);
        $this->assertSame(['que' => 'tarjetas', 'mes' => '2027-12'], array_intersect_key($meta, ['que' => 1, 'mes' => 1]));
        $this->assertGreaterThan(100, $meta['extra']);
        $this->assertLessThanOrEqual('2027-12', $meta['logrado_en']);
        $sinUnDolar = $s->simular($base, ['palancas' => [['tipo' => 'ingreso', 'desde' => '2027-01', 'monto' => $meta['extra'] - 1]]]);
        $this->assertGreaterThan('2027-12', $sinUnDolar['hitos']['tarjetas'] ?? '9999-99'); // con un dólar menos ya no llega

        // Si ya se logra, no hace falta nada; si es imposible, null
        $this->assertSame(0.0, $s->meta($base, [], ['que' => 'tarjetas', 'mes' => '2050-01'])['extra']);
        $this->assertNull($s->meta($this->base([$this->tarjeta('t', 10_000_000, 48)]), [], ['que' => 'tarjetas', 'mes' => '2027-02'])['extra']);
    }
}
