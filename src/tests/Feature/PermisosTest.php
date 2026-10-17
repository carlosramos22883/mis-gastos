<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Permisos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** "Ver" es la llave de cada módulo: da acceso a la pantalla y al menú; las demás acciones exigen su propio permiso. */
class PermisosTest extends TestCase
{
    use RefreshDatabase;

    private function usuario(array $permisos): User
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'onboarding_completed' => true]);
        foreach ($permisos as $permiso) {
            $user->givePermissionTo(Permission::firstOrCreate(['name' => $permiso, 'guard_name' => 'web']));
        }

        return $user;
    }

    public function test_normalizar_descarta_modulos_sin_ver(): void
    {
        $this->assertSame(
            ['bancos.view', 'bancos.edit', 'users.view'],
            Permisos::normalizar(['bancos.view', 'bancos.edit', 'monedas.create', 'monedas.delete', 'users.view', 'bancos.view'])
        );
    }

    public function test_usuario_sin_permisos_de_catalogo_no_puede_crear_bancos(): void
    {
        $user = $this->usuario(['efectivo.view']);

        $this->actingAs($user)->get(route('admin.catalogos.bancos.index'))->assertForbidden();
        $this->actingAs($user)->post(route('admin.catalogos.bancos.store'), ['nombre' => 'Banco pirata'])->assertForbidden();
        $this->assertDatabaseMissing('bancos', ['nombre' => 'Banco pirata']);
    }

    public function test_ver_da_acceso_pero_cada_accion_exige_su_permiso(): void
    {
        $user = $this->usuario(['bancos.view', 'users.view']);
        $otro = User::factory()->create();

        $this->actingAs($user)->get(route('admin.catalogos.bancos.index'))->assertOk();
        $this->actingAs($user)->get(route('admin.catalogos.bancos.create'))->assertForbidden();
        $this->actingAs($user)->get(route('admin.usuarios.index'))->assertOk();
        $this->actingAs($user)->delete(route('admin.usuarios.destroy', $otro))->assertForbidden();
        $this->actingAs($user)->get(route('admin.usuarios.export'))->assertForbidden();
        $this->assertModelExists($otro);
    }

    public function test_menu_muestra_solo_modulos_con_permiso_de_ver(): void
    {
        $user = $this->usuario(['profile.view', 'bancos.view', 'monedas.create', 'monedas.edit']);

        $this->actingAs($user)->get(route('profile.edit'))->assertOk()
            ->assertSee(route('admin.catalogos.bancos.index'), false)
            ->assertDontSee(route('admin.catalogos.monedas.index'), false)
            ->assertDontSee(route('admin.usuarios.index'), false);
    }

    public function test_al_guardar_un_rol_se_descartan_permisos_sin_ver(): void
    {
        $admin = $this->usuario(['roles.view', 'roles.create']);
        foreach (['bancos.view', 'bancos.edit', 'monedas.create'] as $permiso) {
            Permission::firstOrCreate(['name' => $permiso, 'guard_name' => 'web']);
        }

        $this->actingAs($admin)->postJson(route('admin.roles.store'), [
            'name' => 'Cajero',
            'permissions' => ['bancos.view', 'bancos.edit', 'monedas.create'],
        ])->assertSuccessful();

        $this->assertEqualsCanonicalizing(['bancos.view', 'bancos.edit'], Role::findByName('Cajero')->permissions->pluck('name')->all());
    }
}
