<?php

namespace Tests\Feature;

use App\Models\Deuda;
use App\Models\Deudor;
use App\Models\TipoCuenta;
use App\Models\User;
use App\Services\CicloEfectivoService;
use App\Services\CuentaService;
use App\Services\DeudorService;
use App\Services\TarjetaService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class DeudorTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Deudor $aquino;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-17 15:00:00');
        $this->user = User::factory()->create(['email_verified_at' => now(), 'fecha_corte_dia' => 22, 'zona_horaria' => 'America/El_Salvador', 'onboarding_completed' => true]);
        foreach (['deudores', 'efectivo', 'cuentas', 'tarjetas'] as $modulo) {
            foreach (['view', 'create', 'edit', 'delete'] as $accion) {
                $this->user->givePermissionTo(Permission::firstOrCreate(['name' => "{$modulo}.{$accion}", 'guard_name' => 'web']));
            }
        }
        $saldo = $this->user->categoriasPersonales()->create(['nombre' => 'Saldo inicial', 'tipo' => 'ingreso']);
        $this->user->movimientosEfectivo()->create(['categoria_personal_id' => $saldo->id, 'descripcion' => 'Saldo inicial', 'monto' => 100, 'fecha' => '2026-09-23', 'tipo' => 'ingreso']);
        $this->aquino = $this->user->deudores()->create(['nombre' => 'Aquino', 'tipo' => 'persona']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function prestar(array $datos)
    {
        return $this->actingAs($this->user)->postJson(route('deudores.deudas.store', $this->aquino), $datos + [
            'descripcion' => 'Camiseta', 'monto' => '15.00', 'fecha' => '17/10/2026', 'origen' => 'efectivo',
        ]);
    }

    private function pagar(array $datos)
    {
        return $this->actingAs($this->user)->postJson(route('deudores.pagos.store', $this->aquino), $datos + [
            'monto' => '10.00', 'fecha' => '2026-10-17', 'destino' => 'efectivo',
        ]);
    }

    private function efectivo(): float
    {
        return app(CicloEfectivoService::class)->balance($this->user);
    }

    public function test_crear_deudor_y_ver_pantallas(): void
    {
        $this->actingAs($this->user)->postJson(route('deudores.store'), ['nombre' => 'Karen', 'tipo' => 'persona'])->assertOk();
        $this->actingAs($this->user)->postJson(route('deudores.store'), ['nombre' => 'Karen', 'tipo' => 'persona'])
            ->assertUnprocessable()->assertJsonValidationErrors('nombre');

        $this->prestar([]);
        $this->actingAs($this->user)->get(route('deudores.index'))->assertOk()->assertSee('Aquino')->assertSee('15.00');
        $this->actingAs($this->user)->get(route('deudores.show', $this->aquino))->assertOk()->assertSee('Camiseta');
        $this->actingAs($this->user)->get(route('deudores.deudas.create', $this->aquino))->assertOk()->assertSee('Solo anotar');
        $this->actingAs($this->user)->get(route('deudores.pagos.create', $this->aquino))->assertOk()->assertSee('Te debe');
    }

    public function test_prestar_desde_efectivo_crea_el_egreso(): void
    {
        $this->prestar([])->assertOk();

        $deuda = Deuda::firstOrFail();
        $this->assertSame('efectivo', $deuda->origen);
        $this->assertSame(85.0, $this->efectivo());
        $this->assertSame('Préstamos a terceros', $deuda->movimientoEfectivo->categoria->nombre);
        $this->assertSame(15.0, $deuda->pendiente());
    }

    public function test_prestar_desde_cuenta_o_tarjeta(): void
    {
        $tipo = TipoCuenta::create(['nombre' => 'Cuenta de Ahorro', 'activo' => true]);
        $cuenta = $this->user->cuentas()->create(['nombre' => 'Agrícola', 'tipo_cuenta_id' => $tipo->id, 'saldo_inicial' => 50, 'fecha_inicial' => '2026-10-01', 'color' => '#0284C7']);
        $tarjeta = $this->user->tarjetasCredito()->create(['nombre' => 'Promerica', 'limite_credito' => 500, 'dia_corte' => 9, 'dia_pago' => 6, 'fecha_registro' => '2026-10-10', 'color' => '#00B050']);

        $this->prestar(['origen' => 'cuenta:'.$cuenta->id, 'monto' => '20.00'])->assertOk();
        $this->prestar(['origen' => 'tarjeta:'.$tarjeta->id, 'monto' => '99.83', 'descripcion' => 'Macbook'])->assertOk();

        $this->assertSame(30.0, app(CuentaService::class)->saldo($cuenta));
        $this->assertSame(400.17, app(TarjetaService::class)->disponible($tarjeta));
        $this->assertSame(119.83, app(DeudorService::class)->resumir($this->aquino->fresh())->pendiente);
    }

    public function test_solo_anotar_permite_fechas_anteriores_sin_tocar_el_efectivo(): void
    {
        $this->prestar(['origen' => 'efectivo', 'fecha' => '2026-08-01'])->assertUnprocessable()->assertJsonValidationErrors('fecha');
        $this->prestar(['origen' => 'ninguno', 'fecha' => '2026-08-01'])->assertOk();

        $this->assertSame(100.0, $this->efectivo());
    }

    public function test_un_pago_se_reparte_de_la_deuda_mas_antigua_a_la_mas_reciente(): void
    {
        $this->prestar(['origen' => 'ninguno', 'fecha' => '2026-08-01', 'descripcion' => 'Refri', 'monto' => '8.86'])->assertOk();
        $this->prestar(['descripcion' => 'Camiseta', 'monto' => '5.00'])->assertOk();

        $this->pagar(['monto' => '10.00'])->assertOk();

        [$refri, $camiseta] = Deuda::orderBy('fecha')->withSum('pagos', 'monto')->get()->all();
        $this->assertSame('pagada', $refri->estado());
        $this->assertSame('parcial', $camiseta->estado());
        $this->assertSame(3.86, $camiseta->pendiente());
        $this->assertSame(105.0, $this->efectivo()); // 100 − 5 (camiseta) + 10 (pago)
        $this->assertSame(1, $this->user->movimientosEfectivo()->where('descripcion', 'Pago de Aquino')->count()); // un solo ingreso

        $this->pagar(['monto' => '5.00'])->assertUnprocessable()->assertJsonValidationErrors('monto'); // supera lo pendiente
    }

    public function test_pagar_una_deuda_especifica(): void
    {
        $this->prestar(['origen' => 'ninguno', 'fecha' => '2026-08-01', 'descripcion' => 'Refri', 'monto' => '8.86']);
        $this->prestar(['descripcion' => 'Camiseta', 'monto' => '5.00']);
        $camiseta = Deuda::where('descripcion', 'Camiseta')->first();

        $this->pagar(['monto' => '5.00', 'deuda_id' => $camiseta->id])->assertOk();

        $this->assertSame('pagada', $camiseta->fresh()->estado());
        $this->assertSame('activa', Deuda::where('descripcion', 'Refri')->first()->estado());
    }

    public function test_eliminar_pago_y_deuda_revierte_los_movimientos(): void
    {
        $this->prestar([]);
        $this->pagar(['monto' => '15.00']);
        $deuda = Deuda::firstOrFail();

        $this->actingAs($this->user)->deleteJson(route('deudas.destroy', $deuda))->assertUnprocessable(); // tiene pagos
        $this->actingAs($this->user)->deleteJson(route('deuda-pagos.destroy', $deuda->pagos()->first()))->assertOk();
        $this->assertSame(85.0, $this->efectivo());
        $this->actingAs($this->user)->deleteJson(route('deudas.destroy', $deuda))->assertOk();
        $this->assertSame(100.0, $this->efectivo());
        $this->assertSame(0, $this->user->movimientosEfectivo()->where('descripcion', 'like', '%Aquino%')->count());
    }

    public function test_el_movimiento_de_un_deudor_no_se_edita_desde_efectivo(): void
    {
        $this->prestar([]);
        $movimiento = Deuda::firstOrFail()->movimientoEfectivo;

        $this->actingAs($this->user)->deleteJson(route('efectivo.destroy', $movimiento))->assertForbidden();
        $this->assertSame(85.0, $this->efectivo());
    }

    public function test_deudor_con_historial_no_se_elimina_y_otro_hogar_no_lo_ve(): void
    {
        $this->prestar([]);
        $this->actingAs($this->user)->deleteJson(route('deudores.destroy', $this->aquino))->assertUnprocessable();

        $otro = User::factory()->create(['email_verified_at' => now(), 'onboarding_completed' => true]);
        $otro->givePermissionTo('deudores.view');
        $this->actingAs($otro)->get(route('deudores.show', $this->aquino))->assertNotFound();
        $this->actingAs($otro)->get(route('deudores.index'))->assertOk()->assertDontSee('Aquino');
    }
}
