<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class CategoriaPersonalTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_filter_own_categories_by_name_type_and_status(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        foreach (['categorias.view', 'categorias.create', 'categorias.edit', 'categorias.delete'] as $permission) {
            $user->givePermissionTo(Permission::create(['name' => $permission]));
        }
        $user->categoriasPersonales()->create(['nombre' => 'Comida', 'tipo' => 'egreso', 'activo' => true]);
        $user->categoriasPersonales()->create(['nombre' => 'Salario', 'tipo' => 'ingreso', 'activo' => false]);

        $this->actingAs($user)->getJson(route('categorias.index', [
            'search' => 'Comida',
            'tipo' => 'egreso',
            'activo' => '1',
        ]))->assertOk()->assertJsonPath('pagination', '');
    }

    public function test_category_routes_require_their_specific_permissions(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user)->get(route('categorias.index'))->assertForbidden();
        $this->actingAs($user)->post(route('categorias.store'), [
            'nombre' => 'Comida',
            'tipo' => 'egreso',
        ])->assertForbidden();
    }
}
