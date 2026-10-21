<?php

namespace Tests\Feature\Api;

use App\Models\Catalogos\TipoContacto;
use App\Models\User;
use App\Services\CicloEfectivoService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ContactoApiTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function usuario(array $permisos = ['deudores.view', 'deudores.create', 'deudores.edit', 'deudores.delete', 'contactos.view', 'contactos.create', 'contactos.edit', 'contactos.delete']): User
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

    public function test_flujo_de_contacto_y_deudas_por_api(): void
    {
        $user = $this->usuario();

        $id = $this->postJson('/api/contactos', ['nombre' => 'Aquino', 'tipo_contacto_id' => TipoContacto::where('nombre', 'Persona')->value('id')])->assertCreated()->json('data.id');
        $this->postJson("/api/contactos/{$id}/deudas", ['descripcion' => 'Refri', 'monto' => '8.86', 'fecha' => '2026-08-01', 'origen' => 'ninguno'])
            ->assertCreated()->assertJsonPath('data.pendiente', '8.86');
        $this->postJson("/api/contactos/{$id}/deudas", ['descripcion' => 'Camiseta', 'monto' => '5.00', 'fecha' => '2026-10-17', 'origen' => 'efectivo'])
            ->assertCreated()->assertJsonPath('data.pendiente', '13.86');

        $this->postJson("/api/contactos/{$id}/pagos", ['monto' => '10.00', 'fecha' => '2026-10-17', 'destino' => 'efectivo'])
            ->assertCreated()
            ->assertJsonPath('data.pendiente', '3.86')
            ->assertJsonPath('data.deudas.0.estado', 'pagada')
            ->assertJsonPath('data.deudas.1.estado', 'parcial');

        $this->getJson('/api/contactos?filtro=deben')->assertOk()->assertJsonPath('data.0.pendiente', '3.86')->assertJsonMissingPath('data.0.deudas');
        $this->assertSame(105.0, app(CicloEfectivoService::class)->balance($user));
    }

    public function test_permisos_y_aislamiento(): void
    {
        $dueno = $this->usuario();
        $contacto = $dueno->contactos()->create(['nombre' => 'Aquino', 'tipo_contacto_id' => TipoContacto::where('nombre', 'Persona')->value('id')]);

        $this->usuario(['deudores.view', 'contactos.view']);
        $this->getJson("/api/contactos/{$contacto->id}")->assertNotFound();
        $this->postJson('/api/contactos', ['nombre' => 'X', 'tipo_contacto_id' => TipoContacto::where('nombre', 'Persona')->value('id')])->assertForbidden();
    }
}
