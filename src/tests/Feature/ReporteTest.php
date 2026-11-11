<?php

namespace Tests\Feature;

use App\Models\Catalogos\TipoPrestamo;
use App\Models\CategoriaPersonal;
use App\Models\Cuenta;
use App\Models\TarjetaCredito;
use App\Models\TipoCuenta;
use App\Models\User;
use App\Services\CompromisoService;
use App\Services\PrestamoService;
use App\Services\ReporteService;
use App\Services\TarjetaService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Reportes con un escenario que se puede sumar a mano (ciclo del 23/09 al 22/10, corte el 22):
 *  - Efectivo: saldo inicial 100 (01/09), comida 30 (10/09, ciclo anterior), sueldo 500, comida 50 y un movimiento entre cuentas de 20 (no es gasto).
 *  - Cuenta con 1000 iniciales y comida 100. Tarjeta: compra de comida 40. Compromiso de 25 el 15/10.
 */
class ReporteTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Cuenta $cuenta;

    private TarjetaCredito $tarjeta;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 12:00:00');
        $this->user = User::factory()->create(['email_verified_at' => now(), 'fecha_corte_dia' => 22, 'zona_horaria' => 'America/El_Salvador', 'onboarding_completed' => true]);
        foreach (['reportes.view', 'reportes.export'] as $permiso) {
            $this->user->givePermissionTo(Permission::firstOrCreate(['name' => $permiso, 'guard_name' => 'web']));
        }
        $cat = fn ($nombre, $tipo) => CategoriaPersonal::create(['hogar_id' => $this->user->hogar_id, 'user_id' => $this->user->id, 'nombre' => $nombre, 'tipo' => $tipo, 'color' => '#16A34A', 'activo' => true]);
        $saldo = $cat('Saldo inicial', 'ingreso');
        $sueldo = $cat('Sueldo', 'ingreso');
        $comida = $cat('Comida', 'egreso');
        $entreCuentas = $cat('Movimientos entre cuentas', 'egreso');
        $efectivo = fn ($c, $desc, $monto, $fecha, $tipo) => $this->user->movimientosEfectivo()->create(['user_id' => $this->user->id, 'categoria_personal_id' => $c->id, 'descripcion' => $desc, 'monto' => $monto, 'fecha' => $fecha, 'tipo' => $tipo]);
        $efectivo($saldo, 'Saldo inicial', 100, '2026-09-01', 'ingreso');
        $efectivo($comida, 'Súper', 30, '2026-09-10', 'egreso');
        $efectivo($sueldo, 'Sueldo', 500, '2026-10-01', 'ingreso');
        $efectivo($comida, 'Pupusas', 50, '2026-10-02', 'egreso');
        $efectivo($entreCuentas, 'Ajuste', 20, '2026-10-03', 'egreso');

        $this->cuenta = $this->user->cuentas()->create(['nombre' => 'Agrícola', 'tipo_cuenta_id' => TipoCuenta::create(['nombre' => 'Ahorro', 'activo' => true])->id, 'saldo_inicial' => 1000, 'fecha_inicial' => '2026-09-01', 'color' => '#0369A1']);
        $this->cuenta->movimientos()->create(['user_id' => $this->user->id, 'categoria_personal_id' => $comida->id, 'descripcion' => 'Súper', 'monto' => 100, 'fecha' => '2026-10-05', 'tipo' => 'egreso']);

        $this->tarjeta = $this->user->tarjetasCredito()->create(['nombre' => 'Promerica', 'limite_credito' => 500, 'dia_corte' => 9, 'dia_pago' => 6, 'fecha_registro' => '2026-10-01', 'color' => '#00A651']);
        app(TarjetaService::class)->registrarMovimiento($this->tarjeta, ['tipo' => 'compra', 'descripcion' => 'Walmart', 'monto' => 40, 'fecha' => '2026-10-11', 'categoria_personal_id' => $comida->id, 'user_id' => $this->user->id]);

        app(CompromisoService::class)->crearUnico($this->user, ['descripcion' => 'Internet', 'monto' => 25, 'medio_pago' => 'efectivo', 'fecha_esperada' => '2026-10-15']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function servicio(): ReporteService
    {
        return app(ReporteService::class);
    }

    public function test_ejecucion_del_ciclo(): void
    {
        $p = $this->servicio()->periodo($this->user);
        $this->assertSame(['2026-09-23', '2026-10-22', '2026-09-22', null], [$p['desde']->toDateString(), $p['hasta']->toDateString(), $p['anterior'], $p['siguiente']]);

        $e = $this->servicio()->ejecucion($this->user, $p['desde'], $p['hasta']);
        $this->assertSame([500.0, 190.0, 310.0], [$e['totales']['ingresos'], $e['totales']['gastos'], $e['totales']['ahorro']]);
        $this->assertSame(['Comida', 190.0, 100.0, 3], [$e['gastos'][0]['nombre'], $e['gastos'][0]['monto'], $e['gastos'][0]['porcentaje'], $e['gastos'][0]['cantidad']]);
        // El movimiento entre cuentas sale del efectivo pero no es gasto
        $this->assertSame(['saldo_inicial' => 70.0, 'ingresos' => 500.0, 'egresos' => 70.0, 'saldo_final' => 500.0], $e['efectivo']);
        $this->assertSame([1000.0, 100.0, 900.0], [$e['cuentas'][0]['saldo_inicial'], $e['cuentas'][0]['egresos'], $e['cuentas'][0]['saldo_final']]);
        $this->assertSame(['Internet', 25.0, 25.0], [$e['compromisos'][0]['descripcion'], $e['compromisos'][0]['monto'], $e['compromisos'][0]['pendiente']]);
        $this->assertSame(1375.0, $e['totales']['liquidez']); // 500 + 900 − 25
        $this->assertSame([460.0, 40.0], [$e['tarjetas'][0]['disponible'], $e['tarjetas'][0]['deuda']]);
        $this->assertSame(['efectivo', 'Efectivo'], [$e['ingresos'][0]['clase'], $e['ingresos'][0]['donde']]);
    }

    public function test_el_primer_ciclo_arranca_con_el_saldo_inicial_y_no_tiene_anterior(): void
    {
        $p = $this->servicio()->periodo($this->user, '2026-09-10');
        $this->assertSame(['2026-09-01', '2026-09-22', null, '2026-09-23'], [$p['desde']->toDateString(), $p['hasta']->toDateString(), $p['anterior'], $p['siguiente']]);
        $this->assertNull($this->servicio()->periodo($this->user, '2027-01-01')); // futuro
    }

    public function test_gastos_por_categoria_ciclo_por_ciclo(): void
    {
        $g = $this->servicio()->gastos($this->user, Carbon::parse('2026-09-01'), Carbon::parse('2026-10-22'));

        $this->assertSame([['inicio' => '2026-09-01', 'fin' => '2026-09-22'], ['inicio' => '2026-09-23', 'fin' => '2026-10-22']], $g['ciclos']);
        $this->assertSame([30.0, 190.0], $g['categorias'][0]['por_ciclo']);
        $this->assertSame([220.0, 110.0, [30.0, 190.0]], [$g['total'], $g['promedio_por_ciclo'], $g['por_ciclo']]);
        $this->assertCount(4, $g['categorias'][0]['movimientos']);
        $this->assertSame(['tarjeta', 'Tarjeta Promerica'], [$g['categorias'][0]['movimientos'][3]['clase'], $g['categorias'][0]['movimientos'][3]['donde']]);
    }

    public function test_prestamos_con_lo_pagado_y_lo_que_falta(): void
    {
        $prestamos = app(PrestamoService::class);
        $p = $prestamos->crear($this->user, ['nombre' => 'Consumo', 'tipo_prestamo_id' => TipoPrestamo::where('nombre', 'Consumo / personal')->value('id'), 'monto_original' => 1200, 'tasa_anual' => 12,
            'plazo_meses' => 12, 'dia_pago' => 8, 'cuota' => 106.62, 'saldo_inicial' => 1200, 'fecha_saldo' => '2026-09-08', 'medio_pago' => 'efectivo']);
        $prestamos->registrarPago($this->user, $p, ['fecha' => '2026-10-08', 'capital' => 94.62, 'interes' => 12, 'cargos' => 0, 'mora' => 0, 'origen' => 'ninguno']);

        $r = $this->servicio()->prestamos($this->user);
        $this->assertSame(['Consumo', 1105.38, 1, 94.62, 12.0], [$r['prestamos'][0]['nombre'], $r['prestamos'][0]['saldo'], $r['prestamos'][0]['pagos_realizados'], $r['prestamos'][0]['pagado_capital'], $r['prestamos'][0]['pagado_intereses']]);
        $this->assertSame(106.62, $r['prestamos'][0]['pagos'][0]['total']);
        $this->assertSame(1105.38, $r['totales']['saldo']);
        $this->assertGreaterThan(0, $r['totales']['intereses_restantes']);
    }

    public function test_estado_de_cuenta_con_cuotas_y_creditos(): void
    {
        $tarjetas = app(TarjetaService::class);
        $tarjetas->registrarMovimiento($this->tarjeta, ['tipo' => 'compra', 'descripcion' => 'TV', 'monto' => 90, 'cuotas' => 3, 'fecha' => '2026-10-12', 'user_id' => $this->user->id]);
        $tarjetas->registrarMovimiento($this->tarjeta, ['tipo' => 'credito', 'descripcion' => 'Devolución', 'monto' => 10, 'fecha' => '2026-10-13', 'user_id' => $this->user->id]);
        Carbon::setTestNow('2026-11-10 12:00:00');
        $tarjetas->generarEstados($this->tarjeta);
        $estado = $this->tarjeta->estadosCuenta()->orderByDesc('fecha_corte')->firstOrFail();

        $r = $this->servicio()->estadoCuenta($estado);
        $this->assertSame(60.0, $r['total']); // 40 + 30 (cuota 1/3) − 10
        $this->assertSame(['Walmart', 'TV', 'Devolución'], array_column($r['lineas'], 'descripcion'));
        $this->assertSame(['1/3', -10.0], [$r['lineas'][1]['cuota'], $r['lineas'][2]['monto']]);
        $this->assertSame([60.0, 'pendiente'], [$r['pendiente'], $r['estado']]);
    }

    public function test_pantallas_y_descargas(): void
    {
        $this->actingAs($this->user)->get(route('reportes.index'))->assertOk()
            ->assertSee('Ejecución financiera')->assertSee('Ciclo del 23/09/2026 al 22/10/2026')->assertSee('$ 1,375.00');
        $this->actingAs($this->user)->get(route('reportes.show', ['gastos', 'desde' => '2026-09-01', 'hasta' => '2026-10-22']))->assertOk()->assertSee('Cada categoría ciclo por ciclo');
        $this->actingAs($this->user)->get(route('reportes.show', ['prestamos']))->assertOk()->assertSee('No hay préstamos registrados.');
        $this->actingAs($this->user)->get(route('reportes.show', ['estado-cuenta', 'tarjeta' => $this->tarjeta->id]))->assertOk()->assertSee('09/10/2026'); // ya cortó el 09/10 y se ofrece ese corte

        $pdf = $this->actingAs($this->user)->get(route('reportes.exportar', ['ejecucion', 'formato' => 'pdf']));
        $pdf->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringContainsString('reporte_ejecucion_2026-10-22.pdf', $pdf->headers->get('content-disposition'));
        $this->actingAs($this->user)->get(route('reportes.exportar', ['gastos', 'formato' => 'xlsx']))->assertOk()->assertDownload('reporte_gastos_2026-10-22.xlsx');
        $this->actingAs($this->user)->get(route('reportes.exportar', ['prestamos', 'formato' => 'csv']))->assertOk()->assertDownload();
        // Estado de cuenta sin elegir corte: no hay qué descargar
        $this->actingAs($this->user)->get(route('reportes.exportar', ['estado-cuenta', 'formato' => 'pdf']))->assertNotFound();
    }

    public function test_validacion_permisos_y_aislamiento(): void
    {
        $this->actingAs($this->user)->get(route('reportes.show', ['ejecucion', 'desde' => '2026-10-10', 'hasta' => '2026-10-01']))->assertSessionHasErrors('hasta');
        $this->actingAs($this->user)->get(route('reportes.show', ['ejecucion', 'desde' => '2020-01-01', 'hasta' => '2026-10-01']))->assertSessionHasErrors('hasta');
        $this->actingAs($this->user)->get('/reportes/inventado')->assertNotFound();

        // Tarjeta y corte de otro hogar: no se aceptan
        $otro = User::factory()->create();
        $ajena = $otro->tarjetasCredito()->create(['nombre' => 'Ajena', 'limite_credito' => 100, 'dia_corte' => 1, 'dia_pago' => 20, 'fecha_registro' => '2026-10-01', 'color' => '#000000']);
        $this->actingAs($this->user)->get(route('reportes.show', ['estado-cuenta', 'tarjeta' => $ajena->id]))->assertSessionHasErrors('tarjeta');

        // Sin exportar solo se ve; sin ver, nada
        $soloVer = User::factory()->create(['email_verified_at' => now()]);
        $soloVer->givePermissionTo('reportes.view');
        $this->actingAs($soloVer)->get(route('reportes.index'))->assertOk()->assertDontSee('Excel');
        $this->actingAs($soloVer)->get(route('reportes.exportar', ['ejecucion']))->assertForbidden();
        $this->actingAs(User::factory()->create(['email_verified_at' => now()]))->get(route('reportes.index'))->assertForbidden();
    }
}
