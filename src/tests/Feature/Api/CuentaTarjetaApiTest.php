<?php

namespace Tests\Feature\Api;

use App\Models\CategoriaPersonal;
use App\Models\Cuenta;
use App\Models\TarjetaCredito;
use App\Models\TipoCuenta;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/** API de cuentas, transferencias y tarjetas para las apps móviles (mismas reglas que la web). */
class CuentaTarjetaApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Cuenta $cuenta;

    private int $categoria;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-19 12:00:00');
        $this->user = User::factory()->create(['email_verified_at' => now(), 'fecha_corte_dia' => 22, 'zona_horaria' => 'America/El_Salvador', 'onboarding_completed' => true]);
        foreach (['cuentas', 'tarjetas', 'efectivo'] as $modulo) {
            foreach (['view', 'create', 'edit', 'delete'] as $accion) {
                $this->user->givePermissionTo(Permission::firstOrCreate(['name' => "{$modulo}.{$accion}", 'guard_name' => 'web']));
            }
        }
        TipoCuenta::create(['nombre' => 'Cuenta de Ahorro', 'activo' => true]);
        $this->cuenta = $this->user->cuentas()->create(['nombre' => 'Agrícola', 'tipo_cuenta_id' => TipoCuenta::first()->id, 'saldo_inicial' => 100, 'fecha_inicial' => '2026-10-01', 'color' => '#0369A1']);
        $this->categoria = CategoriaPersonal::create(['hogar_id' => $this->user->hogar_id, 'user_id' => $this->user->id, 'nombre' => 'Alimentación', 'tipo' => 'egreso', 'color' => '#16A34A', 'activo' => true])->id;
        Sanctum::actingAs($this->user);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_cuentas_con_saldo_y_movimientos_por_ciclo(): void
    {
        $this->getJson('/api/cuentas')->assertOk()->assertJsonPath('data.0.nombre', 'Agrícola')->assertJsonPath('data.0.saldo', '100.00');

        $mov = $this->postJson("/api/cuentas/{$this->cuenta->id}/movimientos", [
            'tipo' => 'egreso', 'descripcion' => 'Súper', 'monto' => '30.50', 'fecha' => '2026-10-18', 'categoria_personal_id' => $this->categoria,
        ])->assertCreated()->assertJsonPath('data.editable', true)->json('data.id');

        $this->getJson("/api/cuentas/{$this->cuenta->id}")->assertOk()
            ->assertJsonPath('cuenta.saldo', '69.50')
            ->assertJsonPath('resumen.egresos', '30.50')
            ->assertJsonPath('ciclo.inicio', '2026-09-23')
            ->assertJsonPath('ciclo.es_actual', true)
            ->assertJsonPath('data.0.categoria.nombre', 'Alimentación');

        $this->putJson("/api/cuentas/{$this->cuenta->id}/movimientos/{$mov}", [
            'tipo' => 'egreso', 'descripcion' => 'Súper', 'monto' => '40.00', 'fecha' => '2026-10-18', 'categoria_personal_id' => $this->categoria,
        ])->assertOk()->assertJsonPath('data.monto', '40.00');

        // No puede quedar en negativo
        $this->postJson("/api/cuentas/{$this->cuenta->id}/movimientos", [
            'tipo' => 'egreso', 'descripcion' => 'Demasiado', 'monto' => '500', 'fecha' => '2026-10-18', 'categoria_personal_id' => $this->categoria,
        ])->assertUnprocessable();

        $this->deleteJson("/api/cuentas/{$this->cuenta->id}/movimientos/{$mov}")->assertNoContent();
        $this->getJson('/api/cuentas')->assertJsonPath('data.0.saldo', '100.00');
    }

    public function test_crear_editar_y_eliminar_cuenta(): void
    {
        $id = $this->postJson('/api/cuentas', ['nombre' => 'Chivo', 'tipo_cuenta_id' => TipoCuenta::first()->id, 'color' => '#F59E0B',
            'saldo_inicial' => '5.00', 'fecha_inicial' => '2026-10-01'])->assertCreated()->assertJsonPath('data.saldo', '5.00')->json('data.id');

        $this->putJson("/api/cuentas/{$id}", ['nombre' => 'Chivo Wallet', 'tipo_cuenta_id' => TipoCuenta::first()->id, 'color' => '#F59E0B', 'saldo_inicial' => '9'])
            ->assertJsonValidationErrors('saldo_inicial'); // el saldo inicial solo se fija al crear
        $this->putJson("/api/cuentas/{$id}", ['nombre' => 'Chivo Wallet', 'tipo_cuenta_id' => TipoCuenta::first()->id, 'color' => '#F59E0B'])
            ->assertOk()->assertJsonPath('data.nombre', 'Chivo Wallet');

        $this->deleteJson("/api/cuentas/{$id}")->assertNoContent();
    }

    public function test_transferencia_cuenta_a_efectivo_y_su_reversion(): void
    {
        $opciones = $this->getJson('/api/transferencias/opciones')->assertOk()->json('data');
        $this->assertContains('cuenta:'.$this->cuenta->id, array_column($opciones['origenes'], 'valor'));

        $id = $this->postJson('/api/transferencias', ['origen' => 'cuenta:'.$this->cuenta->id, 'destino' => 'efectivo', 'monto' => '25', 'fecha' => '2026-10-19', 'descripcion' => 'Retiro'])
            ->assertCreated()->assertJsonPath('data.destino', 'Efectivo')->json('data.id');

        $this->getJson('/api/cuentas')->assertJsonPath('data.0.saldo', '75.00');
        $this->getJson("/api/cuentas/{$this->cuenta->id}")->assertJsonPath('data.0.transferencia.id', $id)->assertJsonPath('data.0.editable', false);
        $this->getJson('/api/efectivo')->assertJsonPath('resumen.saldo', '25.00');

        $this->deleteJson("/api/transferencias/{$id}")->assertNoContent();
        $this->getJson('/api/cuentas')->assertJsonPath('data.0.saldo', '100.00');
    }

    public function test_tarjeta_con_compras_en_cuotas_y_avance(): void
    {
        $id = $this->postJson('/api/tarjetas', ['nombre' => 'Atlántida', 'color' => '#434343', 'limite_credito' => '1000', 'dia_corte' => 11, 'dia_pago' => 5,
            'disponible' => '1000', 'permite_avances' => true])->assertCreated()->assertJsonPath('data.disponible', '1000.00')->json('data.id');

        $compra = $this->postJson("/api/tarjetas/{$id}/movimientos", ['tipo' => 'compra', 'descripcion' => 'Refri', 'monto' => '600', 'fecha' => '2026-10-19',
            'cuotas' => 6, 'categoria_personal_id' => $this->categoria])->assertCreated()->assertJsonPath('data.cuotas', 6)->json('data.id');

        // Supera el disponible
        $this->postJson("/api/tarjetas/{$id}/movimientos", ['tipo' => 'compra', 'descripcion' => 'TV', 'monto' => '500', 'fecha' => '2026-10-19'])->assertUnprocessable();

        // Avance hacia efectivo (es una transferencia)
        $this->postJson('/api/transferencias', ['origen' => "tarjeta:{$id}", 'destino' => 'efectivo', 'monto' => '50', 'fecha' => '2026-10-19'])->assertCreated();

        $this->getJson("/api/tarjetas/{$id}")->assertOk()
            ->assertJsonPath('data.tarjeta.deuda', '650.00')
            ->assertJsonPath('data.tarjeta.disponible', '350.00')
            ->assertJsonPath('data.en_cuotas.0.movimiento.id', $compra)
            ->assertJsonPath('data.por_facturar.lineas.0.cuota_numero', 1)
            ->assertJsonCount(2, 'data.movimientos');

        $this->getJson('/api/tarjetas')->assertOk()->assertJsonPath('data.0.deuda', '650.00');

        $this->deleteJson("/api/tarjetas/{$id}/movimientos/{$compra}")->assertNoContent();
        $this->getJson("/api/tarjetas/{$id}")->assertJsonPath('data.tarjeta.deuda', '50.00');
    }

    public function test_aislamiento_y_permisos(): void
    {
        $otro = User::factory()->create();
        $ajena = Cuenta::withoutGlobalScopes()->create(['hogar_id' => $otro->hogar_id, 'user_id' => $otro->id, 'nombre' => 'Ajena', 'tipo_cuenta_id' => TipoCuenta::first()->id,
            'saldo_inicial' => 10, 'fecha_inicial' => '2026-10-01', 'color' => '#000000']);
        $this->getJson("/api/cuentas/{$ajena->id}")->assertNotFound();

        $tarjeta = TarjetaCredito::withoutGlobalScopes()->create(['hogar_id' => $otro->hogar_id, 'user_id' => $otro->id, 'nombre' => 'Ajena', 'limite_credito' => 100,
            'dia_corte' => 1, 'dia_pago' => 20, 'color' => '#000000', 'fecha_registro' => '2026-10-01']);
        $this->getJson("/api/tarjetas/{$tarjeta->id}")->assertNotFound();

        $soloVer = User::factory()->create(['email_verified_at' => now()]);
        $soloVer->givePermissionTo(Permission::firstOrCreate(['name' => 'cuentas.view', 'guard_name' => 'web']));
        Sanctum::actingAs($soloVer);
        $this->getJson('/api/cuentas')->assertOk();
        $this->postJson('/api/cuentas', ['nombre' => 'X'])->assertForbidden();
        $this->getJson('/api/tarjetas')->assertForbidden();
    }
}
