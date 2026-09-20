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

    public function test_create_and_update_return_the_page_for_the_active_sort(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        foreach (['categorias.view', 'categorias.create', 'categorias.edit'] as $permission) {
            $user->givePermissionTo(Permission::firstOrCreate(['name' => $permission]));
        }

        foreach (range(1, 10) as $index) {
            $user->categoriasPersonales()->create(['nombre' => 'm-'.$index, 'tipo' => 'egreso']);
        }

        $response = $this->actingAs($user)->postJson(route('categorias.store', [
            'sort' => 'nombre',
            'direction' => 'asc',
            'per_page' => 10,
        ]), [
            'nombre' => 'zzzzzz',
            'tipo' => 'egreso',
            'color' => '#64748B',
        ]);

        $response->assertOk()->assertJsonPath('redirect_to_page', 2);
        $categoria = $user->categoriasPersonales()->where('nombre', 'zzzzzz')->firstOrFail();

        $this->actingAs($user)->putJson(route('categorias.update', $categoria), [
            'sort' => 'nombre',
            'direction' => 'asc',
            'per_page' => 10,
            'nombre' => 'aaaaaa',
            'tipo' => 'egreso',
            'color' => '#64748B',
        ])->assertOk()->assertJsonPath('redirect_to_page', 1);
    }

    public function test_name_order_is_case_insensitive_for_pagination(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        foreach (['categorias.view', 'categorias.create'] as $permission) {
            $user->givePermissionTo(Permission::firstOrCreate(['name' => $permission]));
        }

        $user->categoriasPersonales()->create(['nombre' => 'Comestibles', 'tipo' => 'egreso']);
        $user->categoriasPersonales()->create(['nombre' => 'Deposito', 'tipo' => 'egreso']);
        $user->categoriasPersonales()->create(['nombre' => 'aaaa', 'tipo' => 'egreso']);

        $this->actingAs($user)->getJson(route('categorias.index', [
            'sort' => 'nombre',
            'direction' => 'asc',
            'per_page' => 10,
        ]))->assertOk()->assertJsonPath('html', fn ($html) => strpos($html, 'aaaa') < strpos($html, 'Comestibles'));
    }
}
