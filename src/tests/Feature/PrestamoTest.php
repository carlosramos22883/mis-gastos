<?php

namespace Tests\Feature;

use App\Models\Compromiso;
use App\Models\Prestamo;
use App\Models\TipoCuenta;
use App\Models\User;
use App\Services\CicloEfectivoService;
use App\Services\CompromisoService;
use App\Services\CuentaService;
use App\Services\PrestamoService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PrestamoTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-19 15:00:00');
        $this->user = User::factory()->create(['email_verified_at' => now(), 'fecha_corte_dia' => 22, 'zona_horaria' => 'America/El_Salvador', 'onboarding_completed' => true]);
        foreach (['prestamos', 'efectivo', 'cuentas', 'compromisos'] as $modulo) {
            foreach (['view', 'create', 'edit', 'delete'] as $accion) {
                $this->user->givePermissionTo(Permission::firstOrCreate(['name' => "{$modulo}.{$accion}", 'guard_name' => 'web']));
            }
        }
        $saldo = $this->user->categoriasPersonales()->create(['nombre' => 'Saldo inicial', 'tipo' => 'ingreso']);
        $this->user->movimientosEfectivo()->create(['categoria_personal_id' => $saldo->id, 'descripcion' => 'Saldo inicial', 'monto' => 1000, 'fecha' => '2026-09-23', 'tipo' => 'ingreso']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function servicio(): PrestamoService
    {
        return app(PrestamoService::class);
    }

    /** Préstamo de consumo de Carlos tal como estaba antes del pago del 08/10/2026. */
    private function consumo(array $extra = [], bool $conCompromiso = false): Prestamo
    {
        return $this->servicio()->crear($this->user, $extra + [
            'nombre' => 'Préstamo de consumo', 'tipo' => 'consumo', 'monto_original' => 21550, 'tasa_anual' => 8.10, 'plazo_meses' => 120,
            'dia_pago' => 8, 'cuota' => 262.60, 'saldo_inicial' => 17642.63, 'fecha_saldo' => '2026-09-08', 'medio_pago' => 'transferencia',
        ], [['nombre' => 'Seguros', 'monto' => 24.97], ['nombre' => 'IVA', 'monto' => 1.12]], $conCompromiso);
    }

    public function test_el_desglose_coincide_con_el_banco(): void
    {
        $prestamo = $this->consumo();

        // Pago real del 08/10/2026: interés 117.46, amortización 145.14, seguros 24.97, IVA 1.12 = 288.69
        $s = $this->servicio()->sugerencia($prestamo, Carbon::parse('2026-10-08'));
        $this->assertSame([117.46, 145.14, 26.09, 288.69], [$s['interes'], $s['capital'], $s['cargos'], $s['total']]);
    }

    public function test_interes_acumulado_coincide_con_la_app_del_banco(): void
    {
        $casa = $this->servicio()->crear($this->user, ['nombre' => 'Casa', 'tipo' => 'hipotecario', 'monto_original' => 109979.10, 'tasa_anual' => 6.95,
            'plazo_meses' => 360, 'fecha_otorgamiento' => '2023-03-16', 'dia_pago' => 26, 'cuota' => 728.00, 'saldo_inicial' => 105840.39,
            'fecha_saldo' => '2026-09-28', 'medio_pago' => 'planilla']);
        $personal = $this->servicio()->crear($this->user, ['nombre' => 'Personal', 'tipo' => 'consumo', 'monto_original' => 23161.36, 'tasa_anual' => 9.50,
            'plazo_meses' => 102, 'dia_pago' => 26, 'cuota' => 331.81, 'saldo_inicial' => 15599.44, 'fecha_saldo' => '2026-09-26', 'medio_pago' => 'planilla']);

        // "Saldo de intereses" que mostraban las apps el 19/10/2026
        $this->assertSame(423.22, $this->servicio()->interesAl($casa, Carbon::parse('2026-10-19')));
        $this->assertSame(93.38, $this->servicio()->interesAl($personal, Carbon::parse('2026-10-19')));
    }

    public function test_proyeccion_y_escenarios(): void
    {
        $prestamo = $this->consumo(['saldo_inicial' => 17497.49, 'fecha_saldo' => '2026-10-08']);

        $e = $this->servicio()->escenarios($prestamo, ['extra_mensual' => 100, 'abono' => 1000, 'modo' => 'plazo']);
        $base = $e['base'];
        // 21,550 a 120 meses con cuota 262.60: con este saldo faltan ~89 cuotas
        $this->assertGreaterThanOrEqual(85, $base['meses']);
        $this->assertLessThanOrEqual(92, $base['meses']);
        $this->assertSame(0.0, end($base['filas'])['saldo']);
        $this->assertSame('2026-11-08', $base['filas'][0]['fecha']);
        // Pagar hoy: saldo + interés de 11 días
        $this->assertSame(round(17497.49 + 42.71, 2), $e['pagar_hoy']['monto']);
        $this->assertGreaterThan(0, $e['extra_mensual']['ahorro']);
        $this->assertGreaterThan(0, $e['extra_mensual']['meses_menos']);
        $this->assertGreaterThan(0, $e['abono']['meses_menos']);

        $bajarCuota = $this->servicio()->escenarios($prestamo, ['abono' => 1000, 'modo' => 'cuota'])['abono'];
        $this->assertLessThan(262.60, $bajarCuota['cuota_nueva']);
        $this->assertSame($base['meses'], $bajarCuota['tabla']['meses']);
    }

    public function test_prestamo_de_persona_sin_interes_se_proyecta_con_lo_que_puedo_pagar(): void
    {
        $eve = $this->servicio()->crear($this->user, ['nombre' => 'Evelyn', 'tipo' => 'persona', 'acreedor' => 'Evelyn', 'monto_original' => 4000,
            'tasa_anual' => 0, 'saldo_inicial' => 4000, 'fecha_saldo' => '2026-08-31', 'medio_pago' => 'por_definir']);

        $this->assertNull($this->servicio()->escenarios($eve)['base']);
        $this->assertSame(8, $this->servicio()->escenarios($eve, ['cuota' => 500])['base']['meses']);
        $this->actingAs($this->user)->get(route('prestamos.simular', [$eve, 'cuota' => 500]))->assertOk()->assertSee('8 cuotas');
    }

    public function test_pagar_desde_efectivo_crea_el_egreso_y_paga_la_cuota_del_mes(): void
    {
        $prestamo = $this->consumo(['saldo_inicial' => 17497.49, 'fecha_saldo' => '2026-10-08', 'dia_pago' => 20], true);
        $compromiso = Compromiso::where('compromiso_recurrente_id', $prestamo->fresh()->compromiso_recurrente_id)->firstOrFail();
        $this->assertSame('288.69', $compromiso->monto);

        $this->actingAs($this->user)->postJson(route('prestamos.pagos.store', $prestamo), [
            'fecha' => '19/10/2026', 'capital' => '219.89', 'interes' => '42.71', 'cargos' => '26.09', 'mora' => '0', 'origen' => 'efectivo',
        ])->assertOk();

        $this->assertSame(round(17497.49 - 219.89, 2), $this->servicio()->saldo($prestamo));
        $this->assertSame(round(1000 - 288.69, 2), app(CicloEfectivoService::class)->balance($this->user));
        $this->assertSame(Compromiso::PAGADO, $compromiso->fresh()->estado);

        // El egreso se gestiona desde Préstamos
        $mov = $prestamo->pagos()->first()->movimientoEfectivo;
        $this->actingAs($this->user)->deleteJson(route('efectivo.destroy', $mov))->assertForbidden();

        // Eliminar el pago revierte todo
        $this->actingAs($this->user)->deleteJson(route('prestamo-pagos.destroy', $prestamo->pagos()->first()))->assertOk();
        $this->assertSame(17497.49, $this->servicio()->saldo($prestamo));
        $this->assertSame(1000.0, app(CicloEfectivoService::class)->balance($this->user));
        $this->assertSame(Compromiso::PENDIENTE, $compromiso->fresh()->estado);
    }

    public function test_pagar_compromiso_de_un_prestamo_abre_el_formulario_del_prestamo(): void
    {
        $prestamo = $this->consumo(['saldo_inicial' => 17497.49, 'fecha_saldo' => '2026-10-08', 'dia_pago' => 20], true);
        $compromiso = Compromiso::where('compromiso_recurrente_id', $prestamo->fresh()->compromiso_recurrente_id)->firstOrFail();

        $this->actingAs($this->user)->get(route('compromisos.pagar', $compromiso))->assertOk()
            ->assertSee(route('prestamos.pagos.store', $prestamo), false)->assertSee('Interés');
    }

    public function test_pago_por_planilla_no_mueve_dinero_y_desde_cuenta_si(): void
    {
        $prestamo = $this->consumo(['saldo_inicial' => 17497.49, 'fecha_saldo' => '2026-10-08']);
        $tipo = TipoCuenta::create(['nombre' => 'Cuenta de Ahorro', 'activo' => true]);
        $cuenta = $this->user->cuentas()->create(['nombre' => 'Agrícola', 'tipo_cuenta_id' => $tipo->id, 'saldo_inicial' => 500, 'fecha_inicial' => '2026-10-01', 'color' => '#0284C7']);

        $this->actingAs($this->user)->postJson(route('prestamos.pagos.store', $prestamo), [
            'fecha' => '2026-10-10', 'capital' => '10.00', 'interes' => '7.77', 'origen' => 'planilla',
        ])->assertOk();
        $this->assertSame(1000.0, app(CicloEfectivoService::class)->balance($this->user));

        $this->actingAs($this->user)->postJson(route('prestamos.pagos.store', $prestamo), [
            'fecha' => '2026-10-19', 'capital' => '100.00', 'interes' => '35.00', 'origen' => 'cuenta:'.$cuenta->id,
        ])->assertOk();
        $this->assertSame(365.0, app(CuentaService::class)->saldo($cuenta));

        // No se registra un pago con fecha anterior al último, ni más capital que el saldo
        $this->actingAs($this->user)->postJson(route('prestamos.pagos.store', $prestamo), ['fecha' => '2026-10-15', 'capital' => '1', 'interes' => '0', 'origen' => 'ninguno'])
            ->assertUnprocessable()->assertJsonValidationErrors('fecha');
        $this->actingAs($this->user)->postJson(route('prestamos.pagos.store', $prestamo), ['fecha' => '2026-10-19', 'capital' => '99999', 'interes' => '0', 'origen' => 'ninguno'])
            ->assertUnprocessable()->assertJsonValidationErrors('capital');
        // Solo se elimina el último pago
        $this->actingAs($this->user)->deleteJson(route('prestamo-pagos.destroy', $prestamo->pagos()->first()))->assertUnprocessable();
    }

    public function test_pantallas_y_formulario(): void
    {
        $this->actingAs($this->user)->postJson(route('prestamos.store'), [
            'nombre' => 'Casa', 'tipo' => 'hipotecario', 'monto_original' => '109979.10', 'tasa_anual' => '6.95', 'plazo_meses' => 360,
            'dia_pago' => 26, 'cuota' => '728.00', 'saldo_inicial' => '105840.39', 'fecha_saldo' => '28/09/2026', 'medio_pago' => 'planilla',
            'cargos' => [['nombre' => 'Seguro de vida', 'monto' => '65.99'], ['nombre' => 'Daños', 'monto' => '48.95'], ['nombre' => '', 'monto' => '']],
            'crear_compromiso' => '1',
        ])->assertOk();
        $casa = Prestamo::firstOrFail();
        $this->assertSame(2, $casa->cargos()->count());
        $this->assertNull($casa->compromiso_recurrente_id); // por planilla no hay compromiso

        $this->actingAs($this->user)->get(route('prestamos.index'))->assertOk()->assertSee('Casa')->assertSee('842.94');
        $this->actingAs($this->user)->get(route('prestamos.show', $casa))->assertOk()->assertSee('Tabla de pagos que faltan');
        $this->actingAs($this->user)->get(route('prestamos.edit', $casa))->assertOk();
        $this->actingAs($this->user)->get(route('prestamos.pagos.create', $casa))->assertOk()->assertSee('Descuento de planilla');
        $this->actingAs($this->user)->getJson(route('prestamos.simular', [$casa, 'abono' => 200000]))->assertUnprocessable()->assertJsonValidationErrors('abono');

        $otro = User::factory()->create();
        $otro->givePermissionTo('prestamos.view');
        $this->actingAs($otro)->get(route('prestamos.show', $casa))->assertNotFound();
    }

    public function test_casa_mixta_planilla_y_ventanilla(): void
    {
        // $842.93 al mes: $526.50 se descuentan del sueldo y $316.43 se pagan en ventanilla
        $casa = $this->servicio()->crear($this->user, ['nombre' => 'Casa', 'tipo' => 'hipotecario', 'monto_original' => 109979.10, 'tasa_anual' => 6.95,
            'plazo_meses' => 360, 'dia_pago' => 26, 'cuota' => 727.99, 'saldo_inicial' => 105840.39, 'fecha_saldo' => '2026-09-28',
            'medio_pago' => 'efectivo', 'monto_planilla' => 526.50], [['nombre' => 'Vida', 'monto' => 65.99], ['nombre' => 'Daños', 'monto' => 48.95]], true);
        Carbon::setTestNow('2026-10-20 10:00:00');
        app(CompromisoService::class)->sincronizar($this->user, Carbon::parse('2026-11-22'));
        $compromiso = Compromiso::where('compromiso_recurrente_id', $casa->fresh()->compromiso_recurrente_id)->firstOrFail();
        $this->assertSame('316.43', $compromiso->monto);

        $s = $this->servicio()->sugerencia($casa->fresh(), Carbon::parse('2026-10-20'));
        $this->actingAs($this->user)->postJson(route('prestamos.pagos.store', $casa), [
            'fecha' => '2026-10-20', 'capital' => number_format($s['capital'], 2, '.', ''), 'interes' => number_format($s['interes'], 2, '.', ''),
            'cargos' => '114.94', 'origen' => 'efectivo',
        ])->assertOk();

        $pago = $casa->pagos()->first();
        $this->assertSame('526.50', $pago->planilla);
        $this->assertSame(round($s['total'] - 526.50, 2), (float) $pago->movimientoEfectivo->monto);
        $this->assertSame(Compromiso::PAGADO, $compromiso->fresh()->estado);
    }
}
