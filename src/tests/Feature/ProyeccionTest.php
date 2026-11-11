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
}
