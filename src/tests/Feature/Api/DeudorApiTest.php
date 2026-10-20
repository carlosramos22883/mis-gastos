<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Services\CicloEfectivoService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class DeudorApiTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function usuario(array $permisos = ['deudores.view', 'deudores.create', 'deudores.edit', 'deudores.delete']): User
    {
        Carbon::setTestNow('2026-10-17 15:00:00');
        $user = User::factory()->create(['email_verified_at' => now(), 'fecha_corte_dia' => 22, 'zona_horaria' => 'America/El_Salvador', 'onboarding_completed' => true]);
        foreach ($permisos as $permiso) {
            $user->givePermissionTo(Permission::firstOrCreate(['name' => $permiso, 'guard_name' => 'web']));
        }
        $saldo = $user->categoriasPersonales()->create(['nombre' => 'Saldo inicial', 'tipo' => 'ingreso']);
        $user->movimientosEfectivo()->create(['categoria_personal_id' => $saldo->id, 'descripcion' => 'Saldo inicial', 'monto' => 100, 'fecha' => '2026-09-23', 'tipo' => 'ingreso']);
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_flujo_de_deudor_por_api(): void
    {
        $user = $this->usuario();

        $id = $this->postJson('/api/deudores', ['nombre' => 'Aquino', 'tipo' => 'persona'])->assertCreated()->json('data.id');
        $this->postJson("/api/deudores/{$id}/deudas", ['descripcion' => 'Refri', 'monto' => '8.86', 'fecha' => '2026-08-01', 'origen' => 'ninguno'])
            ->assertCreated()->assertJsonPath('data.pendiente', '8.86');
        $this->postJson("/api/deudores/{$id}/deudas", ['descripcion' => 'Camiseta', 'monto' => '5.00', 'fecha' => '2026-10-17', 'origen' => 'efectivo'])
            ->assertCreated()->assertJsonPath('data.pendiente', '13.86');

        $this->postJson("/api/deudores/{$id}/pagos", ['monto' => '10.00', 'fecha' => '2026-10-17', 'destino' => 'efectivo'])
            ->assertCreated()
            ->assertJsonPath('data.pendiente', '3.86')
            ->assertJsonPath('data.deudas.0.estado', 'pagada')
            ->assertJsonPath('data.deudas.1.estado', 'parcial');

        $this->getJson('/api/deudores')->assertOk()->assertJsonPath('data.0.pendiente', '3.86')->assertJsonMissingPath('data.0.deudas');
        $this->assertSame(105.0, app(CicloEfectivoService::class)->balance($user));
    }

    public function test_permisos_y_aislamiento(): void
    {
        $dueno = $this->usuario();
        $deudor = $dueno->deudores()->create(['nombre' => 'Aquino', 'tipo' => 'persona']);

        $this->usuario(['deudores.view']);
        $this->getJson("/api/deudores/{$deudor->id}")->assertNotFound();
        $this->postJson('/api/deudores', ['nombre' => 'X', 'tipo' => 'persona'])->assertForbidden();
    }
}
