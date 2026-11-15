<?php

namespace Tests\Feature;

use App\Models\Catalogos\TipoPrestamo;
use App\Models\TipoCuenta;
use App\Models\User;
use App\Services\CompromisoService;
use App\Services\DashboardService;
use App\Services\PagoCompromisoService;
use App\Services\PrestamoService;
use App\Services\PresupuestoService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-19 12:00:00');
        $this->user = User::factory()->create(['email_verified_at' => now(), 'fecha_corte_dia' => 22, 'zona_horaria' => 'America/El_Salvador', 'onboarding_completed' => true]);
        foreach (['efectivo.view', 'cuentas.view', 'tarjetas.view', 'compromisos.view', 'ingresos.view'] as $p) {
            $this->user->givePermissionTo(Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']));
        }
        $saldo = $this->user->categoriasPersonales()->create(['nombre' => 'Saldo inicial', 'tipo' => 'ingreso']);
        $super = $this->user->categoriasPersonales()->create(['nombre' => 'Alimentación', 'tipo' => 'egreso', 'color' => '#16A34A']);
        $entre = $this->user->categoriasPersonales()->create(['nombre' => 'Movimientos entre cuentas', 'tipo' => 'egreso']);
        $sueldo = $this->user->categoriasPersonales()->create(['nombre' => 'Sueldo y trabajos', 'tipo' => 'ingreso']);
        $this->user->movimientosEfectivo()->create(['categoria_personal_id' => $saldo->id, 'descripcion' => 'Saldo inicial', 'monto' => 100, 'fecha' => '2026-09-23', 'tipo' => 'ingreso']);
        $this->user->movimientosEfectivo()->create(['categoria_personal_id' => $sueldo->id, 'descripcion' => 'Sueldo', 'monto' => 500, 'fecha' => '2026-09-30', 'tipo' => 'ingreso']);
        $this->user->movimientosEfectivo()->create(['categoria_personal_id' => $super->id, 'descripcion' => 'Super', 'monto' => 80, 'fecha' => '2026-10-01', 'tipo' => 'egreso']);
        $this->user->movimientosEfectivo()->create(['categoria_personal_id' => $entre->id, 'descripcion' => 'Metí al banco (sin pareja)', 'monto' => 20, 'fecha' => '2026-10-02', 'tipo' => 'egreso']);
        $tipo = TipoCuenta::create(['nombre' => 'Cuenta de Ahorro', 'activo' => true]);
        $this->user->cuentas()->create(['nombre' => 'Agrícola', 'tipo_cuenta_id' => $tipo->id, 'saldo_inicial' => 300, 'fecha_inicial' => '2026-09-23', 'color' => '#0369A1']);
        $tarjeta = $this->user->tarjetasCredito()->create(['nombre' => 'Walmart', 'limite_credito' => 900, 'dia_corte' => 24, 'dia_pago' => 18, 'fecha_registro' => '2026-09-25', 'color' => '#CFE2F3']);
        $tarjeta->movimientos()->create(['tipo' => 'compra', 'descripcion' => 'Walmart', 'monto' => 950, 'fecha' => '2026-10-04', 'categoria_personal_id' => $super->id]);
        $tienda = $this->user->tarjetasCredito()->create(['nombre' => 'Credisiman', 'limite_credito' => 400, 'dia_corte' => 28, 'dia_pago' => 25, 'fecha_registro' => '2026-09-29', 'color' => '#1155CC', 'permite_avances' => false]);
        app(CompromisoService::class)->crearUnico($this->user, ['descripcion' => 'Tarjeta Walmart · corte', 'monto' => 200, 'fecha_esperada' => '2026-10-18', 'medio_pago' => 'por_definir', 'tarjeta_credito_id' => $tarjeta->id]);
        app(CompromisoService::class)->crearUnico($this->user, ['descripcion' => 'Internet', 'monto' => 46, 'fecha_esperada' => '2026-10-20', 'medio_pago' => 'efectivo']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_presupuesto_se_descuenta_con_los_gastos_de_su_categoria_y_no_vence(): void
    {
        $super = $this->user->categoriasPersonales()->where('nombre', 'Alimentación')->first();
        $servicio = app(CompromisoService::class);
        $servicio->crearRecurrente($this->user, ['descripcion' => 'Supermercado', 'monto' => 1100, 'medio_pago' => 'efectivo', 'categoria_personal_id' => $super->id,
            'es_presupuesto' => true, 'frecuencia' => 'mensual', 'dia_pago' => 25, 'fecha_inicio' => '2026-09-23']);
        $servicio->sincronizar($this->user);
        $presupuesto = $this->user->compromisos()->where('es_presupuesto', true)->firstOrFail();

        // Gastado: $80 en efectivo + $950 con tarjeta; vence al cerrar el ciclo, nunca antes
        $this->assertSame(['2026-10-22', '70.00', 'parcial'], [$presupuesto->fecha_esperada->toDateString(), $presupuesto->saldo_pendiente, $presupuesto->estadoVisual(now(), Carbon::parse('2026-09-23'))]);

        // Un gasto nuevo de su categoría lo baja solo; si se pasa queda en 0 y avisa
        $this->user->movimientosEfectivo()->create(['categoria_personal_id' => $super->id, 'descripcion' => 'Selectos', 'monto' => 100, 'fecha' => '2026-10-19', 'tipo' => 'egreso']);
        $this->assertSame('0.00', $presupuesto->fresh()->saldo_pendiente);
        $d = app(DashboardService::class)->resumen($this->user);
        $this->assertSame([1100.0, 1130.0, -30.0], [$d['presupuestos'][0]['monto'], $d['presupuestos'][0]['gastado'], $d['presupuestos'][0]['queda']]);
        $this->assertStringContainsString('Supermercado: te pasaste por $ 30.00', collect($d['alertas'])->pluck('texto')->implode(' | '));
        $this->assertNotContains($presupuesto->id, collect($d['proximos'])->pluck('id')->all());

        // No se paga
        try {
            app(PagoCompromisoService::class)->pagar($this->user, $presupuesto->fresh(), ['origen' => 'efectivo', 'monto' => 10, 'fecha' => '2026-10-19', 'descripcion' => 'x']);
            $this->fail('Un presupuesto no debería poder pagarse');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('origen', $e->errors());
        }

        // Borrar un gasto lo devuelve; al empezar el ciclo siguiente se cierra (no queda "vencido")
        $this->user->tarjetasCredito()->first()->movimientos()->where('monto', 950)->first()->delete();
        $this->assertSame('920.00', $presupuesto->fresh()->saldo_pendiente);
        Carbon::setTestNow('2026-10-23 08:00:00');
        $servicio->sincronizar($this->user);
        $this->assertSame('pagado', $presupuesto->fresh()->estado);
        $this->assertSame(1, $this->user->compromisos()->where('es_presupuesto', true)->where('estado', 'pendiente')->count()); // el del ciclo nuevo
    }

    public function test_presupuesto_descuenta_compromisos_de_su_categoria_y_la_lista_ordena_por_estado(): void
    {
        $gaby = $this->user->categoriasPersonales()->create(['nombre' => 'Gaby', 'tipo' => 'egreso']);
        $servicio = app(CompromisoService::class);
        $servicio->crearRecurrente($this->user, ['descripcion' => 'Gaby', 'monto' => 250, 'medio_pago' => 'efectivo', 'categoria_personal_id' => $gaby->id,
            'es_presupuesto' => true, 'frecuencia' => 'mensual', 'dia_pago' => 25, 'fecha_inicio' => '2026-09-23']);
        $cuota = $servicio->crearUnico($this->user, ['descripcion' => 'Cuota de Gaby', 'monto' => 150, 'fecha_esperada' => '2026-10-21', 'medio_pago' => 'efectivo', 'categoria_personal_id' => $gaby->id]);
        $servicio->sincronizar($this->user);
        $presupuesto = $this->user->compromisos()->where('es_presupuesto', true)->firstOrFail();

        // La cuota ya aparta sus $150: del presupuesto quedan $100 (no se reservan dos veces)
        $this->assertSame('100.00', $presupuesto->fresh()->saldo_pendiente);
        $this->user->movimientosEfectivo()->create(['categoria_personal_id' => $gaby->id, 'descripcion' => 'Útiles', 'monto' => 30, 'fecha' => '2026-10-19', 'tipo' => 'egreso']);
        $this->assertSame(['monto' => 250.0, 'monto_base' => 250.0, 'recibido' => 0.0, 'cedido' => 0.0, 'gastado' => 30.0, 'comprometido' => 150.0, 'queda' => 70.0], app(PresupuestoService::class)->resumen($presupuesto->fresh()));

        // La API trae el resumen y, en el detalle, los gastos que lo descuentan
        $this->user->givePermissionTo(Permission::firstOrCreate(['name' => 'compromisos.view', 'guard_name' => 'web']));
        Sanctum::actingAs($this->user);
        $this->getJson(route('api.compromisos.show', $presupuesto))->assertOk()
            ->assertJsonPath('data.presupuesto.queda', '70.00')->assertJsonPath('data.presupuesto.movimientos.0.descripcion', 'Útiles')
            ->assertJsonPath('data.presupuesto.movimientos.0.origen', 'Efectivo');

        // Por defecto: vencidos primero, luego pendientes, al final los pagados
        $cuota->update(['estado' => 'pagado', 'saldo_pendiente' => 0]);
        $orden = $servicio->consultar($this->user)->get()->map(fn ($c) => $c->descripcion)->all();
        $this->assertSame(['Tarjeta Walmart · corte', 'Internet', 'Gaby', 'Cuota de Gaby'], $orden);
    }

    public function test_avisos_de_la_campana_vistos_y_sin_ver(): void
    {
        $servicio = app(DashboardService::class);
        $avisos = $servicio->alertasCampana($this->user);
        $this->assertNotEmpty($avisos);
        $vencido = collect($avisos)->firstWhere('etiqueta', 'Vencido');

        // Abrir un aviso lo marca como visto y lleva a su pantalla; una clave que no existe no hace nada
        $this->actingAs($this->user)->get(route('avisos.abrir', $vencido['clave']))->assertRedirect($vencido['url']);
        $this->assertSame([$vencido['clave']], $this->user->fresh()->avisos_vistos);
        $this->actingAs($this->user)->get(route('avisos.abrir', str_repeat('a', 16)))->assertRedirect(route('dashboard'));

        // Marcar todo; la campana sigue mostrándolos pero ya no cuentan
        $this->actingAs($this->user)->postJson(route('avisos.vistos'))->assertOk();
        $this->assertEqualsCanonicalizing(collect($avisos)->pluck('clave')->all(), $this->user->fresh()->avisos_vistos);
        $this->actingAs($this->user->fresh())->get(route('dashboard'))->assertOk()->assertSee('Avisos y notificaciones: 0 sin ver');
    }

    public function test_tope_de_gasto_del_ciclo(): void
    {
        $this->assertNull(app(DashboardService::class)->resumen($this->user)['tope']);
        $this->user->givePermissionTo(Permission::firstOrCreate(['name' => 'compromisos.edit', 'guard_name' => 'web']));
        $this->actingAs($this->user)->patchJson(route('hogar.tope'), ['tope_gasto_ciclo' => '1200'])->assertOk();

        // Gastos del ciclo: $80 + $950 (el movimiento entre cuentas no es gasto); quedan $170 para hoy y 3 días más
        $tope = app(DashboardService::class)->resumen($this->user)['tope'];
        $this->assertSame(['tope' => 1200.0, 'gastado' => 1030.0, 'comprometido' => 0.0, 'queda' => 170.0, 'por_dia' => 42.5, 'porcentaje' => 86, 'dias_restantes' => 3],
            collect($tope)->only(['tope', 'gastado', 'comprometido', 'queda', 'por_dia', 'porcentaje', 'dias_restantes'])->all());
        $this->assertStringContainsString('Llevas el 86 % del tope', collect(app(DashboardService::class)->resumen($this->user)['alertas'])->pluck('texto')->implode(' | '));

        Sanctum::actingAs($this->user);
        $this->patchJson(route('api.hogar.tope'), ['tope_gasto_ciclo' => null])->assertOk()->assertJsonPath('data.tope_gasto_ciclo', null);
    }

    public function test_prestamos_del_ciclo_con_la_cuota_lo_pagado_y_lo_de_mas(): void
    {
        $prestamos = app(PrestamoService::class);
        $p = $prestamos->crear($this->user, ['nombre' => 'Consumo', 'tipo_prestamo_id' => TipoPrestamo::value('id'), 'monto_original' => 1200, 'tasa_anual' => 12,
            'plazo_meses' => 12, 'dia_pago' => 8, 'cuota' => 106.62, 'saldo_inicial' => 1200, 'fecha_saldo' => '2026-09-08', 'medio_pago' => 'efectivo']);
        // Pagó la cuota y abonó $50 de más en el ciclo (23/09 al 22/10)
        $prestamos->registrarPago($this->user, $p, ['fecha' => '2026-10-08', 'capital' => 144.62, 'interes' => 12, 'cargos' => 0, 'mora' => 0, 'origen' => 'ninguno']);

        $c = app(DashboardService::class)->resumen($this->user)['prestamos_ciclo'];

        $this->assertSame(['id' => $p->id, 'nombre' => 'Consumo', 'cuota' => 106.62, 'pagado' => 156.62, 'mora' => 0.0, 'extra' => 50.0, 'falta' => 0.0,
            'pagada' => true, 'fecha' => '2026-10-08'], $c['lista'][0]);
        $this->assertSame([1, 1, 106.62, 156.62, 50.0], [$c['cuotas'], $c['pagadas'], $c['cuota'], $c['pagado'], $c['extra']]);
        $this->user->givePermissionTo(Permission::firstOrCreate(['name' => 'prestamos.view', 'guard_name' => 'web']));
        $this->actingAs($this->user)->get(route('dashboard'))->assertOk()->assertSee('Préstamos del ciclo')->assertSee(route('prestamos.show', $p->id))
            ->assertSee('1 de 1 cuota pagada')->assertSee('Pagada')->assertSee('pagaste $ 50.00 de más', false);
    }

    public function test_la_parte_de_planilla_va_dentro_del_pago_y_no_cuenta_como_de_mas(): void
    {
        $prestamos = app(PrestamoService::class);
        $tipo = TipoPrestamo::value('id');
        $p = $prestamos->crear($this->user, ['nombre' => 'Vivienda', 'tipo_prestamo_id' => $tipo, 'monto_original' => 1200, 'tasa_anual' => 12,
            'plazo_meses' => 12, 'dia_pago' => 8, 'cuota' => 106.62, 'saldo_inicial' => 1200, 'fecha_saldo' => '2026-09-08', 'medio_pago' => 'efectivo']);
        $sinPagar = $prestamos->crear($this->user, ['nombre' => 'Moto', 'tipo_prestamo_id' => $tipo, 'monto_original' => 600, 'tasa_anual' => 12,
            'plazo_meses' => 12, 'dia_pago' => 20, 'cuota' => 53.31, 'saldo_inicial' => 600, 'fecha_saldo' => '2026-09-20', 'medio_pago' => 'efectivo']);
        // La cuota exacta: $60 descontados de planilla y el resto pagado aparte
        $prestamos->registrarPago($this->user, $p, ['fecha' => '2026-10-08', 'capital' => 94.62, 'interes' => 12, 'cargos' => 0, 'mora' => 0, 'planilla' => 60, 'origen' => 'ninguno']);

        $c = collect(app(DashboardService::class)->resumen($this->user)['prestamos_ciclo']['lista'])->keyBy('nombre');

        $this->assertSame([106.62, 0.0, true], [$c['Vivienda']['pagado'], $c['Vivienda']['extra'], $c['Vivienda']['pagada']]);
        $this->assertSame([0.0, 53.31, false], [$c['Moto']['pagado'], $c['Moto']['falta'], $c['Moto']['pagada']]);
        $this->actingAs($this->user)->get(route('dashboard'))->assertSee('1 de 2 cuotas pagadas')->assertSee('Sin pagar');
    }

    public function test_avances_y_pagos_entre_tarjetas_del_ciclo(): void
    {
        $walmart = $this->user->tarjetasCredito()->where('nombre', 'Walmart')->first();
        $walmart->movimientos()->create(['tipo' => 'avance', 'descripcion' => 'Avance a efectivo', 'monto' => 300, 'fecha' => '2026-10-10']);
        $walmart->movimientos()->create(['tipo' => 'avance', 'descripcion' => 'Fuera del ciclo', 'monto' => 999, 'fecha' => '2026-09-01']);

        $a = app(DashboardService::class)->resumen($this->user)['avances_ciclo'];

        $this->assertSame(['nombre' => 'Walmart', 'avances' => 300.0, 'pagos_a_otras' => 0.0, 'sacado' => 300.0],
            collect($a['lista'])->firstWhere('nombre', 'Walmart') ? collect(collect($a['lista'])->firstWhere('nombre', 'Walmart'))->only(['nombre', 'avances', 'pagos_a_otras', 'sacado'])->all() : null);
        $this->assertSame([['de' => 'Walmart', 'a' => 'Efectivo', 'monto' => 300.0, 'tipo' => 'avance']], $a['flujos']);
        $this->assertSame(300.0, $a['total']);
        $this->actingAs($this->user)->get(route('dashboard'))->assertOk()->assertSee('Avances y pagos entre tarjetas')->assertSee('A dónde fue lo sacado')
            ->assertSee('Total sacado de las tarjetas');
    }

    public function test_sin_avances_en_el_ciclo_no_se_ve_la_seccion_de_tarjetas(): void
    {
        $this->assertSame(0.0, app(DashboardService::class)->resumen($this->user)['avances_ciclo']['total']);
        $this->actingAs($this->user)->get(route('dashboard'))->assertOk()->assertDontSee('Avances y pagos entre tarjetas');
    }

    public function test_el_resumen_va_primero_y_el_cuadro_de_tarjetas_al_final(): void
    {
        $this->actingAs($this->user)->get(route('dashboard'))->assertOk()
            ->assertSeeInOrder(['Al pagar mis compromisos tendré', 'Gasto del ciclo', 'Próximos compromisos', 'Límite']);
    }

    public function test_resumen_de_inicio(): void
    {
        $d = app(DashboardService::class)->resumen($this->user);

        $this->assertSame(['inicio' => '2026-09-23', 'fin' => '2026-10-22', 'dias_restantes' => 3], $d['ciclo']);
        $this->assertSame(500.0, $d['efectivo']);
        $this->assertSame(300.0, $d['total_cuentas']);
        $this->assertSame(246.0, $d['por_pagar_ciclo']);
        $this->assertSame(554.0, $d['liquidez']); // 500 + 300 − 246
        // Disponible: Walmart −50 (sobregirada) + Credisiman 400; "de las que sí puedo sacar" excluye la de tienda y no resta sobregiros
        $this->assertSame(350.0, $d['tarjetas_totales']['disponible']);
        $this->assertSame(0.0, $d['tarjetas_totales']['disponible_avances']);
        // Gastos: super en efectivo + compra en tarjeta; "Movimientos entre cuentas" no es gasto
        $this->assertSame([['nombre' => 'Alimentación', 'color' => '#16A34A', 'monto' => 1030.0]], $d['gastos_por_categoria']);
        $this->assertSame(500.0, end($d['historial'])['ingresos']); // el saldo inicial no es ingreso
        // Alertas: vencido primero, luego lo de mañana y la tarjeta sobre su límite
        $this->assertSame('critico', $d['alertas'][0]['nivel']);
        $this->assertStringContainsString('venció hace 1 día', $d['alertas'][0]['texto']);
        $this->assertTrue(collect($d['alertas'])->contains(fn ($a) => str_contains($a['texto'], 'sobre su límite')));
        $this->assertTrue(collect($d['alertas'])->contains(fn ($a) => str_contains($a['texto'], 'vence mañana')));
    }

    public function test_pagina_y_api(): void
    {
        $this->actingAs($this->user)->get(route('dashboard'))->assertOk()
            ->assertSee('Al pagar mis compromisos tendré')->assertSee('554.00')->assertSee('Gastos del ciclo por categoría')->assertDontSee('Todas las sesiones');

        Sanctum::actingAs($this->user);
        $this->getJson('/api/dashboard')->assertOk()->assertJsonPath('data.liquidez', 554)->assertJsonPath('data.tarjetas.0.nombre', 'Walmart');
    }
}
