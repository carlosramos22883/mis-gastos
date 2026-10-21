<?php

namespace Tests\Feature;

use App\Models\Catalogos\TipoIngreso;
use App\Models\Catalogos\TipoPrestamo;
use App\Models\FuenteIngreso;
use App\Models\Ingreso;
use App\Models\TipoCuenta;
use App\Models\User;
use App\Services\CuentaService;
use App\Services\IngresoService;
use App\Services\PrestamoService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class IngresoTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-22 15:00:00');
        $this->user = User::factory()->create(['email_verified_at' => now(), 'fecha_corte_dia' => 22, 'zona_horaria' => 'America/El_Salvador', 'onboarding_completed' => true]);
        foreach (['ingresos', 'efectivo', 'cuentas', 'prestamos'] as $modulo) {
            foreach (['view', 'create', 'edit', 'delete'] as $accion) {
                $this->user->givePermissionTo(Permission::firstOrCreate(['name' => "{$modulo}.{$accion}", 'guard_name' => 'web']));
            }
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Sueldo de Stef: 1,920 − renta 231.16 − AFP 7.25 % − ISSS 3 % (tope 30) − préstamo 350.24 − casa 526.50 = 642.90 */
    private function sueldoStef(): array
    {
        $tipo = TipoCuenta::create(['nombre' => 'Cuenta Planillera', 'activo' => true]);
        $cuenta = $this->user->cuentas()->create(['nombre' => 'Agrícola Stefany', 'tipo_cuenta_id' => $tipo->id, 'saldo_inicial' => 0, 'fecha_inicial' => '2026-09-01', 'color' => '#DB2777']);
        $prestamos = app(PrestamoService::class);
        $personal = $prestamos->crear($this->user, ['nombre' => 'Consumo portafolio', 'tipo_prestamo_id' => TipoPrestamo::where('nombre', 'Consumo / personal')->value('id'), 'monto_original' => 23161.36, 'tasa_anual' => 9.5,
            'dia_pago' => 26, 'cuota' => 331.81, 'saldo_inicial' => 15599.44, 'fecha_saldo' => '2026-09-26', 'medio_pago' => 'planilla'],
            [['nombre' => 'Vida', 'monto' => 7.90], ['nombre' => 'Desempleo', 'monto' => 10.53]]);
        $casa = $prestamos->crear($this->user, ['nombre' => 'Casa', 'tipo_prestamo_id' => TipoPrestamo::where('nombre', 'Hipotecario')->value('id'), 'monto_original' => 109979.10, 'tasa_anual' => 6.95,
            'dia_pago' => 26, 'cuota' => 727.99, 'saldo_inicial' => 105840.39, 'fecha_saldo' => '2026-09-28', 'medio_pago' => 'efectivo', 'monto_planilla' => 526.50]);

        $this->actingAs($this->user)->postJson(route('ingresos.store'), [
            'nombre' => 'Sueldo Stefany', 'tipo_ingreso_id' => TipoIngreso::where('nombre', 'Sueldo (planilla)')->value('id'), 'frecuencia' => 'mensual', 'dia_pago' => 22, 'monto_bruto' => '1920.00', 'destino' => 'cuenta:'.$cuenta->id,
            'conceptos' => [
                ['nombre' => 'Renta', 'tipo' => 'descuento', 'monto' => '231.16'],
                ['nombre' => 'AFP', 'tipo' => 'descuento', 'porcentaje' => '7.25'],
                ['nombre' => 'ISSS', 'tipo' => 'descuento', 'porcentaje' => '3', 'tope' => '30.00'],
                ['nombre' => 'Préstamo personal', 'tipo' => 'descuento', 'monto' => '350.24', 'prestamo_id' => $personal->id],
                ['nombre' => 'Descuento hipotecario', 'tipo' => 'descuento', 'monto' => '526.50', 'prestamo_id' => $casa->id],
                ['nombre' => '', 'tipo' => 'descuento', 'monto' => ''],
            ],
        ])->assertOk();

        return [FuenteIngreso::firstOrFail(), $cuenta, $personal, $casa];
    }

    public function test_la_plantilla_calcula_el_neto_de_stef(): void
    {
        [$fuente] = $this->sueldoStef();

        $p = app(IngresoService::class)->propuesta($fuente->fresh('conceptos'));
        $this->assertSame(5, count($p['lineas']));
        $this->assertSame(139.20, $p['lineas'][1]['monto']); // AFP 7.25 %
        $this->assertSame(30.0, $p['lineas'][2]['monto']);   // ISSS con tope
        $this->assertSame(642.90, $p['neto']);
    }

    public function test_registrar_el_pago_mete_el_neto_y_paga_el_prestamo_de_planilla(): void
    {
        [$fuente, $cuenta, $personal, $casa] = $this->sueldoStef();
        $p = app(IngresoService::class)->propuesta($fuente->fresh('conceptos'));

        $this->actingAs($this->user)->postJson(route('ingresos.recibos.store', $fuente), [
            'fecha' => '22/10/2026', 'bruto' => '1920.00', 'destino' => 'cuenta:'.$cuenta->id,
            'lineas' => array_merge($p['lineas'], [['nombre' => 'Bono', 'tipo' => 'adicion', 'monto' => '224.82']]),
        ])->assertOk();

        $ingreso = Ingreso::firstOrFail();
        $this->assertSame('867.72', $ingreso->neto); // 642.90 + bono
        $this->assertSame(867.72, app(CuentaService::class)->saldo($cuenta));
        // El préstamo personal (todo por planilla) se pagó solo: 350.24 con su desglose
        $pago = $personal->pagos()->firstOrFail();
        $this->assertSame('planilla', $pago->origen);
        $this->assertSame(350.24, $pago->total());
        $this->assertSame(18.43, (float) $pago->cargos);
        // La casa es mixta: su pago se registra al pagar la ventanilla, no aquí
        $this->assertSame(0, $casa->pagos()->count());

        // El ingreso se gestiona desde Ingresos; al eliminarlo se revierte todo
        $this->actingAs($this->user)->deleteJson(route('cuentas.movimientos.destroy', [$cuenta, $ingreso->movimientoCuenta]))->assertForbidden();
        $this->actingAs($this->user)->deleteJson(route('recibos.destroy', $ingreso))->assertOk();
        $this->assertSame(0.0, app(CuentaService::class)->saldo($cuenta));
        $this->assertSame(0, $personal->pagos()->count());
    }

    public function test_servicios_profesionales_con_comision(): void
    {
        $this->actingAs($this->user)->postJson(route('ingresos.store'), [
            'nombre' => 'Servicios profesionales', 'tipo_ingreso_id' => TipoIngreso::where('nombre', 'Servicios profesionales')->value('id'), 'frecuencia' => 'mensual', 'dia_pago' => 30, 'destino' => 'efectivo',
            'conceptos' => [['nombre' => 'Comisión por transferencia', 'tipo' => 'descuento']],
        ])->assertOk();
        $fuente = FuenteIngreso::firstOrFail();

        $this->actingAs($this->user)->postJson(route('ingresos.recibos.store', $fuente), [
            'fecha' => '2026-10-22', 'bruto' => '1500.00', 'destino' => 'efectivo',
            'lineas' => [['nombre' => 'Comisión por transferencia', 'tipo' => 'descuento', 'monto' => '12.50']],
        ])->assertOk();
        $this->assertSame('1487.50', Ingreso::firstOrFail()->neto);

        $this->actingAs($this->user)->postJson(route('ingresos.recibos.store', $fuente), [
            'fecha' => '2026-10-22', 'bruto' => '10.00', 'destino' => 'efectivo',
            'lineas' => [['nombre' => 'Comisión', 'tipo' => 'descuento', 'monto' => '12.50']],
        ])->assertUnprocessable()->assertJsonValidationErrors('bruto');
    }

    public function test_pantallas_resumen_y_aislamiento(): void
    {
        [$fuente, $cuenta] = $this->sueldoStef();
        $this->actingAs($this->user)->get(route('ingresos.show', $fuente))->assertOk()->assertSee('Aún no hay pagos registrados');
        $p = app(IngresoService::class)->propuesta($fuente->fresh('conceptos'));
        $this->actingAs($this->user)->postJson(route('ingresos.recibos.store', $fuente), ['fecha' => '2026-10-22', 'bruto' => '1920.00', 'destino' => 'cuenta:'.$cuenta->id, 'lineas' => $p['lineas']])->assertOk();

        $this->actingAs($this->user)->get(route('ingresos.index'))->assertOk()->assertSee('Sueldo Stefany')->assertSee('642.90');
        $this->actingAs($this->user)->get(route('ingresos.show', $fuente))->assertOk()->assertSee('Resumen 2026')->assertSee('231.16');
        $this->actingAs($this->user)->get(route('ingresos.edit', $fuente))->assertOk();
        $this->actingAs($this->user)->get(route('ingresos.recibos.create', $fuente))->assertOk()->assertSee('Neto');
        $this->assertSame(139.20, collect(app(IngresoService::class)->resumenAnual($fuente, 2026)['conceptos'])->firstWhere('nombre', 'AFP')['monto']);

        // Una fuente con pagos no se elimina
        $this->actingAs($this->user)->deleteJson(route('ingresos.destroy', $fuente))->assertUnprocessable();
        $otro = User::factory()->create();
        $otro->givePermissionTo('ingresos.view');
        $this->actingAs($otro)->get(route('ingresos.show', $fuente))->assertNotFound();
    }
}
