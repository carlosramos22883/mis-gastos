<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class MovimientoEfectivoTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        foreach (['efectivo.view', 'efectivo.create', 'efectivo.edit', 'efectivo.delete'] as $permission) {
            $user->givePermissionTo(Permission::firstOrCreate(['name' => $permission]));
        }

        return $user;
    }

    public function test_user_can_register_an_income_with_required_category(): void
    {
        $user = $this->user();
        $category = $user->categoriasPersonales()->create(['nombre' => 'Salario', 'tipo' => 'ingreso']);

        $this->actingAs($user)->post(route('efectivo.store'), [
            'descripcion' => 'Pago mensual',
            'monto' => '1,250.50',
            'fecha' => '2026-09-18',
            'tipo' => 'ingreso',
            'categoria_personal_id' => $category->id,
        ])->assertSessionHasErrors('monto');

        $this->actingAs($user)->post(route('efectivo.store'), [
            'descripcion' => 'Pago mensual',
            'monto' => '1250.50',
            'fecha' => '2026-09-18',
            'tipo' => 'ingreso',
            'categoria_personal_id' => $category->id,
        ])->assertRedirect(route('efectivo.index'));

        $this->assertDatabaseHas('movimientos_efectivo', [
            'user_id' => $user->id,
            'monto' => '1250.50',
            'categoria_personal_id' => $category->id,
        ]);
    }

    public function test_expense_cannot_exceed_balance_and_users_are_isolated(): void
    {
        $user = $this->user();
        $other = $this->user();
        $category = $user->categoriasPersonales()->create(['nombre' => 'Comida', 'tipo' => 'egreso']);

        $this->actingAs($user)->post(route('efectivo.store'), [
            'descripcion' => 'Compra',
            'monto' => '10.00',
            'fecha' => '2026-09-18',
            'tipo' => 'egreso',
            'categoria_personal_id' => $category->id,
        ])->assertSessionHasErrors('monto');

        $movement = $other->movimientosEfectivo()->create([
            'descripcion' => 'Privado',
            'monto' => '10.00',
            'fecha' => '2026-09-18',
            'tipo' => 'ingreso',
            'categoria_personal_id' => $other->categoriasPersonales()->create(['nombre' => 'Otro', 'tipo' => 'ingreso'])->id,
        ]);

        $this->actingAs($user)->get(route('efectivo.edit', $movement))->assertNotFound();
    }
}
