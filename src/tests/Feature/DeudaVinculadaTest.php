<?php

namespace Tests\Feature;

use App\Models\Catalogos\TipoContacto;
use App\Models\CategoriaPersonal;
use App\Models\Contacto;
use App\Models\Deuda;
use App\Models\MovimientoTarjeta;
use App\Models\TarjetaCredito;
use App\Models\TipoCuenta;
use App\Models\User;
use App\Services\CuentaService;
use App\Services\DeudorService;
use App\Services\TarjetaService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/** "Me lo debe": una compra ya registrada (ej. con la tarjeta que se le prestó a alguien) se liga a una deuda del contacto. */
class DeudaVinculadaTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Contacto $roberto;

    private TarjetaCredito $tarjeta;

    private CategoriaPersonal $ropa;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-17 15:00:00');
        $this->user = User::factory()->create(['email_verified_at' => now(), 'fecha_corte_dia' => 22, 'zona_horaria' => 'America/El_Salvador', 'onboarding_completed' => true]);
        foreach (['deudores', 'contactos', 'efectivo', 'cuentas', 'tarjetas'] as $modulo) {
            foreach (['view', 'create', 'edit', 'delete'] as $accion) {
                $this->user->givePermissionTo(Permission::firstOrCreate(['name' => "{$modulo}.{$accion}", 'guard_name' => 'web']));
            }
        }
        $this->roberto = $this->user->contactos()->create(['nombre' => 'Roberto', 'tipo_contacto_id' => TipoContacto::where('nombre', 'Persona')->value('id')]);
        $this->tarjeta = $this->user->tarjetasCredito()->create(['nombre' => 'Credisiman', 'limite_credito' => 500, 'dia_corte' => 9, 'dia_pago' => 6, 'fecha_registro' => '2026-10-10', 'color' => '#00B050']);
        $this->ropa = $this->user->categoriasPersonales()->create(['nombre' => 'Ropa', 'tipo' => 'egreso']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function compra(string $monto = '108.05'): MovimientoTarjeta
    {
        return app(TarjetaService::class)->registrarMovimiento($this->tarjeta, [
            'tipo' => 'compra', 'descripcion' => 'Zapatos', 'monto' => $monto, 'fecha' => '2026-10-15', 'categoria_personal_id' => $this->ropa->id,
        ]);
    }

    private function vincular(MovimientoTarjeta $compra, array $datos = [])
    {
        return $this->actingAs($this->user)->postJson(route('movimientos.deuda.store', ['tarjeta', $compra->id]), $datos + [
            'contacto_id' => (string) $this->roberto->id, 'descripcion' => 'Zapatos', 'monto' => '108.05',
        ]);
    }

    public function test_ligar_una_compra_de_tarjeta_la_deja_como_deuda_y_deja_de_ser_gasto(): void
    {
        $compra = $this->compra();
        $this->actingAs($this->user)->get(route('tarjetas.show', $this->tarjeta))->assertOk()->assertSee(route('movimientos.deuda.create', ['tarjeta', $compra->id]));
        $this->actingAs($this->user)->get(route('movimientos.deuda.create', ['tarjeta', $compra->id]))->assertOk()->assertSee('Me lo debe');

        $this->vincular($compra)->assertOk();

        $deuda = Deuda::firstOrFail();
        $this->assertTrue($deuda->vinculada);
        $this->assertSame('tarjeta', $deuda->origen);
        $this->assertSame($compra->id, $deuda->movimiento_tarjeta_id);
        $this->assertSame('2026-10-15', $deuda->fecha->toDateString());
        $compra->refresh();
        $this->assertSame($this->roberto->id, $compra->contacto_id);
        $this->assertSame(DeudorService::CATEGORIA_PRESTAMO, $compra->categoria->nombre);
        $this->assertSame(391.95, app(TarjetaService::class)->disponible($this->tarjeta)); // la tarjeta se sigue debiendo
        $this->assertSame(108.05, app(DeudorService::class)->resumir($this->roberto->fresh())->pendiente);

        // Ya no se modifica desde la tarjeta ni se puede ligar otra vez
        $this->actingAs($this->user)->deleteJson(route('tarjetas.movimientos.destroy', [$this->tarjeta, $compra]))->assertForbidden();
        $this->actingAs($this->user)->get(route('movimientos.deuda.create', ['tarjeta', $compra->id]))->assertForbidden();
        $this->vincular($compra)->assertUnprocessable();
    }

    public function test_un_abono_por_transferencia_a_la_cuenta_baja_lo_pendiente(): void
    {
        $tipo = TipoCuenta::create(['nombre' => 'Cuenta de Ahorro', 'activo' => true]);
        $cuenta = $this->user->cuentas()->create(['nombre' => 'Agrícola', 'tipo_cuenta_id' => $tipo->id, 'saldo_inicial' => 50, 'fecha_inicial' => '2026-10-01', 'color' => '#0284C7']);
        $this->vincular($this->compra())->assertOk();
        $deuda = Deuda::firstOrFail();

        $this->actingAs($this->user)->postJson(route('contactos.pagos.store', $this->roberto), [
            'monto' => '50.00', 'fecha' => '2026-10-17', 'destino' => 'cuenta:'.$cuenta->id, 'deuda_id' => $deuda->id,
        ])->assertOk();

        $this->assertSame(100.0, app(CuentaService::class)->saldo($cuenta));
        $this->assertSame(58.05, $deuda->fresh()->pendiente());
        $this->assertSame(Deuda::PARCIAL, $deuda->fresh()->estado());
    }

    public function test_por_una_parte_conserva_la_categoria_y_no_supera_el_monto(): void
    {
        $compra = $this->compra();

        $this->vincular($compra, ['monto' => '108.06'])->assertUnprocessable()->assertJsonValidationErrors('monto');
        $this->vincular($compra, ['monto' => '40.00'])->assertOk();

        $this->assertSame($this->ropa->id, $compra->fresh()->categoria_personal_id);
        $this->assertSame(40.0, app(DeudorService::class)->resumir($this->roberto->fresh())->pendiente);
    }

    public function test_eliminar_la_deuda_deja_la_compra_con_su_categoria(): void
    {
        $compra = $this->compra();
        $this->vincular($compra)->assertOk();

        $this->actingAs($this->user)->deleteJson(route('deudas.destroy', Deuda::firstOrFail()))->assertOk()->assertJsonFragment(['message' => 'Deuda eliminada. El movimiento sigue registrado.']);

        $compra->refresh();
        $this->assertSame($this->ropa->id, $compra->categoria_personal_id);
        $this->assertSame(391.95, app(TarjetaService::class)->disponible($this->tarjeta));
        $this->assertTrue(app(TarjetaService::class)->esEditable($compra));
    }

    public function test_al_registrar_la_compra_con_me_lo_debe(): void
    {
        $datos = ['tipo' => 'compra', 'descripcion' => 'Zapatos', 'monto' => '108.05', 'fecha' => '2026-10-15', 'me_lo_debe' => '1'];
        $this->actingAs($this->user)->get(route('tarjetas.movimientos.create', [$this->tarjeta, 'tipo' => 'compra']))->assertOk()->assertSee('me_lo_debe');

        $this->actingAs($this->user)->postJson(route('tarjetas.movimientos.store', $this->tarjeta), $datos)
            ->assertUnprocessable()->assertJsonValidationErrors('contacto_id');
        $this->assertSame(0, MovimientoTarjeta::count());

        $this->actingAs($this->user)->postJson(route('tarjetas.movimientos.store', $this->tarjeta), $datos + ['contacto_id' => (string) $this->roberto->id])->assertOk();

        $deuda = Deuda::firstOrFail();
        $this->assertTrue($deuda->vinculada);
        $this->assertSame(108.05, $deuda->pendiente());
        $this->assertSame(DeudorService::CATEGORIA_PRESTAMO, $deuda->movimientoTarjeta->categoria->nombre);
    }

    public function test_solo_gastos_propios_y_del_hogar(): void
    {
        $credito = app(TarjetaService::class)->registrarMovimiento($this->tarjeta, ['tipo' => 'credito', 'descripcion' => 'Devolución', 'monto' => '5.00', 'fecha' => '2026-10-15']);
        $this->actingAs($this->user)->get(route('movimientos.deuda.create', ['tarjeta', $credito->id]))->assertForbidden();
        $ingreso = $this->user->movimientosEfectivo()->create(['descripcion' => 'Sueldo', 'monto' => 100, 'fecha' => '2026-10-15', 'tipo' => 'ingreso']);
        $this->actingAs($this->user)->get(route('movimientos.deuda.create', ['efectivo', $ingreso->id]))->assertForbidden();

        $gasto = $this->user->movimientosEfectivo()->create(['descripcion' => 'Almuerzo', 'monto' => 8, 'fecha' => '2026-10-15', 'tipo' => 'egreso']);
        $otro = User::factory()->create(['email_verified_at' => now(), 'onboarding_completed' => true]);
        $otro->givePermissionTo('deudores.create');
        $this->actingAs($otro)->get(route('movimientos.deuda.create', ['efectivo', $gasto->id]))->assertNotFound();
        $this->actingAs($this->user)->get(route('movimientos.deuda.create', ['efectivo', $gasto->id]))->assertOk();
    }
}
