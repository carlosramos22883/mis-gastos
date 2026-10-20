<?php

namespace Tests\Feature\Api;

use App\Models\HogarInvitacion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class HogarApiTest extends TestCase
{
    use RefreshDatabase;

    private function usuario(string $nombre, string $email, array $permisos = ['hogar.view', 'hogar.edit']): User
    {
        $user = User::factory()->create(['name' => $nombre, 'email' => $email, 'email_verified_at' => now(), 'fecha_corte_dia' => 22]);
        foreach ($permisos as $permiso) {
            $user->givePermissionTo(Permission::firstOrCreate(['name' => $permiso, 'guard_name' => 'web']));
        }

        return $user;
    }

    public function test_flujo_completo_de_invitacion(): void
    {
        Notification::fake();
        $carlos = $this->usuario('Carlos', 'carlos@test.com');
        $stef = $this->usuario('Stefany', 'stefany@test.com');

        Sanctum::actingAs($carlos);
        $this->patchJson('/api/hogar', ['nombre' => 'Casa Ramos'])->assertOk()->assertJsonPath('data.nombre', 'Casa Ramos');
        $this->postJson('/api/hogar/invitaciones', ['email' => 'stefany@test.com'])->assertCreated();
        $this->getJson('/api/hogar')->assertOk()->assertJsonCount(1, 'data.invitaciones_enviadas');

        Sanctum::actingAs($stef);
        $id = $this->getJson('/api/hogar')->assertOk()->json('data.invitaciones_recibidas.0.id');
        $this->postJson("/api/hogar/invitaciones/{$id}/aceptar")->assertOk()
            ->assertJsonPath('data.nombre', 'Casa Ramos')
            ->assertJsonPath('data.es_administrador', false)
            ->assertJsonCount(2, 'data.miembros');

        // Solo quien administra invita o quita miembros
        $this->postJson('/api/hogar/invitaciones', ['email' => 'otro@test.com'])->assertForbidden();
        $this->deleteJson("/api/hogar/miembros/{$carlos->id}")->assertForbidden();

        Sanctum::actingAs($carlos);
        $this->deleteJson("/api/hogar/miembros/{$stef->id}")->assertNoContent();
        $this->assertNotSame($carlos->hogar_id, $stef->fresh()->hogar_id);
    }

    public function test_no_se_puede_aceptar_una_invitacion_ajena(): void
    {
        Notification::fake();
        $carlos = $this->usuario('Carlos', 'carlos@test.com');
        $this->usuario('Stefany', 'stefany@test.com');
        $intruso = $this->usuario('Intruso', 'intruso@test.com');

        Sanctum::actingAs($carlos);
        $this->postJson('/api/hogar/invitaciones', ['email' => 'stefany@test.com'])->assertCreated();

        Sanctum::actingAs($intruso);
        $this->postJson('/api/hogar/invitaciones/'.HogarInvitacion::first()->id.'/aceptar')->assertNotFound();
    }

    public function test_sin_permiso_de_ver_no_entra(): void
    {
        Sanctum::actingAs($this->usuario('Carlos', 'carlos@test.com', []));

        $this->getJson('/api/hogar')->assertForbidden();
    }
}
