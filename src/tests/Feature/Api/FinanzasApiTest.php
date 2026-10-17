<?php

namespace Tests\Feature\Api;

use App\Models\Compromiso;
use App\Models\Moneda;
use App\Models\User;
use App\Services\CompromisoService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class FinanzasApiTest extends TestCase
{
    use RefreshDatabase;

    private const PERMISOS = [
        'categorias.view', 'categorias.create', 'categorias.edit', 'categorias.delete',
        'efectivo.view', 'efectivo.create', 'efectivo.edit', 'efectivo.delete',
        'compromisos.view', 'compromisos.create', 'compromisos.edit', 'compromisos.delete',
    ];

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Usuario con corte 31, saldo inicial de $100 el 1 de octubre y hoy 17 de octubre. */
    private function usuario(array $permisos = self::PERMISOS): User
    {
        Carbon::setTestNow('2026-10-17 15:00:00');
        $user = User::factory()->create(['email_verified_at' => now(), 'fecha_corte_dia' => 31, 'zona_horaria' => 'America/El_Salvador', 'onboarding_completed' => true]);
        foreach ($permisos as $permiso) {
            $user->givePermissionTo(Permission::firstOrCreate(['name' => $permiso, 'guard_name' => 'web']));
        }
        $saldo = $user->categoriasPersonales()->create(['nombre' => 'Saldo inicial', 'tipo' => 'ingreso']);
        $user->movimientosEfectivo()->create(['categoria_personal_id' => $saldo->id, 'descripcion' => 'Saldo inicial', 'monto' => 100, 'fecha' => '2026-10-01', 'tipo' => 'ingreso']);
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_requires_token(): void
    {
        $this->getJson('/api/efectivo')->assertUnauthorized();
        $this->getJson('/api/catalogos')->assertUnauthorized();
    }

    public function test_catalogos_returns_active_items_only(): void
    {
        Moneda::create(['nombre' => 'Dólar', 'codigo' => 'USD', 'simbolo' => '$', 'activo' => true]);
        Moneda::create(['nombre' => 'Viejo', 'codigo' => 'OLD', 'simbolo' => 'x', 'activo' => false]);
        $this->usuario();

        $this->getJson('/api/catalogos')->assertOk()
            ->assertJsonStructure(['monedas', 'bancos', 'tipos_cuenta', 'marcas_red'])
            ->assertJsonCount(1, 'monedas')
            ->assertJsonPath('monedas.0.codigo', 'USD');
    }

    public function test_categorias_crud_and_rules(): void
    {
        $user = $this->usuario();

        $id = $this->postJson('/api/categorias', ['nombre' => 'Comida', 'tipo' => 'egreso', 'color' => '#16A34A'])
            ->assertCreated()->assertJsonPath('data.editable', true)->json('data.id');
        $this->postJson('/api/categorias', ['nombre' => 'Comida', 'tipo' => 'egreso', 'color' => '#16A34A'])->assertUnprocessable()->assertJsonValidationErrors('nombre');
        $this->putJson("/api/categorias/{$id}", ['nombre' => 'Alimentación', 'tipo' => 'egreso', 'color' => '#000000', 'activo' => false])
            ->assertOk()->assertJsonPath('data.activo', false);

        $this->getJson('/api/categorias?tipo=egreso')->assertOk()->assertJsonCount(1, 'data');

        $saldoInicial = $user->categoriasPersonales()->where('nombre', 'Saldo inicial')->first();
        $this->putJson("/api/categorias/{$saldoInicial->id}", ['nombre' => 'X', 'tipo' => 'ingreso', 'color' => '#000000'])->assertForbidden();
        $this->deleteJson("/api/categorias/{$saldoInicial->id}")->assertForbidden();

        // En uso: no se elimina (antes daba error 500 por la llave foránea)
        $usada = $user->categoriasPersonales()->create(['nombre' => 'Bus', 'tipo' => 'egreso']);
        $user->movimientosEfectivo()->create(['categoria_personal_id' => $usada->id, 'descripcion' => 'Bus', 'monto' => 1, 'fecha' => '2026-10-02', 'tipo' => 'egreso']);
        $this->deleteJson("/api/categorias/{$usada->id}")->assertUnprocessable()->assertJsonValidationErrors('categoria');

        $this->deleteJson("/api/categorias/{$id}")->assertNoContent();
    }

    public function test_permissions_and_ownership(): void
    {
        $otro = User::factory()->create();
        $ajena = $otro->categoriasPersonales()->create(['nombre' => 'Ajena', 'tipo' => 'egreso']);
        $this->usuario(['categorias.view']);

        $this->postJson('/api/categorias', ['nombre' => 'X', 'tipo' => 'egreso', 'color' => '#000000'])->assertForbidden();
        $this->getJson('/api/compromisos')->assertForbidden();
        $this->getJson('/api/categorias')->assertOk()->assertJsonMissing(['nombre' => 'Ajena']);

        $this->usuario(); // otro usuario, ahora con todos los permisos: la categoría ajena sigue sin existir para él
        $this->putJson("/api/categorias/{$ajena->id}", ['nombre' => 'Mía', 'tipo' => 'egreso', 'color' => '#000000'])->assertNotFound();
    }

    public function test_efectivo_index_store_update_delete(): void
    {
        $user = $this->usuario();
        $comida = $user->categoriasPersonales()->create(['nombre' => 'Comida', 'tipo' => 'egreso']);

        $this->getJson('/api/efectivo')->assertOk()
            ->assertJsonPath('ciclo.inicio', '2026-10-01')
            ->assertJsonPath('ciclo.fin', '2026-10-31')
            ->assertJsonPath('ciclo.es_actual', true)
            ->assertJsonPath('ciclo.anterior', null)
            ->assertJsonPath('resumen.saldo', '100.00')
            ->assertJsonPath('data.0.monto', '100.00');

        $egreso = ['descripcion' => 'Almuerzo', 'monto' => '30.50', 'fecha' => '17/10/2026', 'tipo' => 'egreso', 'categoria_personal_id' => $comida->id];
        $id = $this->postJson('/api/efectivo', $egreso)->assertCreated()
            ->assertJsonPath('data.fecha', '2026-10-17')
            ->assertJsonPath('data.categoria.nombre', 'Comida')
            ->assertJsonPath('data.editable', true)
            ->json('data.id');

        $this->postJson('/api/efectivo', ['monto' => '70.00'] + $egreso)->assertUnprocessable()->assertJsonValidationErrors('monto');
        $this->postJson('/api/efectivo', ['fecha' => '2026-09-30'] + $egreso)->assertUnprocessable()->assertJsonValidationErrors('fecha');

        $this->putJson("/api/efectivo/{$id}", ['monto' => '20.00', 'fecha' => '2026-10-17'] + $egreso)->assertOk()->assertJsonPath('data.monto', '20.00');
        $this->getJson('/api/efectivo')->assertJsonPath('resumen.saldo', '80.00')->assertJsonPath('resumen.egresos', '20.00');

        $this->deleteJson("/api/efectivo/{$id}")->assertNoContent();
        $this->getJson('/api/efectivo')->assertJsonPath('resumen.saldo', '100.00');
    }

    public function test_efectivo_navigates_closed_cycles(): void
    {
        $this->usuario();
        Carbon::setTestNow('2026-11-05 15:00:00');

        $this->getJson('/api/efectivo')->assertOk()
            ->assertJsonPath('ciclo.inicio', '2026-11-01')
            ->assertJsonPath('ciclo.anterior', '2026-10-31')
            ->assertJsonPath('resumen.saldo', '100.00');

        $this->getJson('/api/efectivo?ciclo=2026-10-31')->assertOk()
            ->assertJsonPath('ciclo.inicio', '2026-10-01')
            ->assertJsonPath('ciclo.es_actual', false)
            ->assertJsonPath('ciclo.siguiente', '2026-11-01')
            ->assertJsonPath('data.0.editable', false);

        $this->getJson('/api/efectivo?ciclo=2026-12-15')->assertNotFound();
        $this->getJson('/api/efectivo?ciclo=hola')->assertNotFound();
    }

    public function test_compromisos_flow_with_payment_through_efectivo(): void
    {
        $user = $this->usuario();
        $deuda = $user->categoriasPersonales()->create(['nombre' => 'Deuda', 'tipo' => 'egreso']);

        $id = $this->postJson('/api/compromisos', ['descripcion' => 'Préstamo David', 'monto' => '40', 'medio_pago' => 'efectivo', 'categoria_personal_id' => $deuda->id])
            ->assertCreated()->assertJsonPath('data.estado_visual', 'pendiente')->json('data.id');

        $this->postJson('/api/efectivo', ['descripcion' => 'Abono', 'monto' => '15', 'fecha' => '2026-10-17', 'tipo' => 'egreso', 'categoria_personal_id' => $deuda->id, 'compromiso_id' => $id])->assertCreated();

        $this->getJson("/api/compromisos/{$id}")->assertOk()
            ->assertJsonPath('data.estado_visual', 'parcial')
            ->assertJsonPath('data.saldo_pendiente', '25.00')
            ->assertJsonPath('data.monto_pagado', '15.00')
            ->assertJsonCount(1, 'data.abonos');

        $this->putJson("/api/compromisos/{$id}", ['descripcion' => 'Préstamo David', 'monto' => '10', 'medio_pago' => 'efectivo'])
            ->assertUnprocessable()->assertJsonValidationErrors('monto');

        $serie = $this->postJson('/api/compromisos', [
            'descripcion' => 'Cuota de Gaby', 'monto' => '150', 'medio_pago' => 'transferencia', 'recurrencia_tipo' => 'recurrente',
            'frecuencia' => 'quincenal', 'dia_pago' => 15, 'dia_pago_2' => 31, 'fecha_inicio' => '2026-10-01', 'finalizacion' => 'indefinido',
        ])->assertCreated()->assertJsonPath('pagos_generados', 2)->assertJsonPath('data.recurrente.frecuencia', 'quincenal');

        $this->getJson('/api/compromisos?estado=vencido')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.fecha_esperada', '2026-10-15');

        $vencido = $serie->json('data.id');
        $this->deleteJson("/api/compromisos/{$vencido}?alcance=serie")->assertNoContent();
        $this->assertSame(['cancelado', 'cancelado'], Compromiso::where('compromiso_recurrente_id', $serie->json('recurrente_id'))->orderBy('fecha_esperada')->pluck('estado')->all());
    }

    public function test_web_index_needs_only_view_permission(): void
    {
        $user = $this->usuario(['compromisos.view']);
        app(CompromisoService::class)->crearUnico($user, ['descripcion' => 'Visible', 'monto' => 5, 'medio_pago' => 'efectivo']);

        $this->actingAs($user)->get(route('compromisos.index'))->assertOk()->assertSee('Visible');
        $this->actingAs($user)->get(route('compromisos.create'))->assertForbidden();
    }
}
