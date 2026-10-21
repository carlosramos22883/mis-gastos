<?php

namespace Tests\Feature\Api;

use App\Models\Catalogos\TipoIngreso;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class IngresoApiTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function usuario(array $permisos = ['ingresos.view', 'ingresos.create', 'ingresos.edit', 'ingresos.delete']): User
    {
        Carbon::setTestNow('2026-10-22 15:00:00');
        $user = User::factory()->create(['email_verified_at' => now(), 'fecha_corte_dia' => 22, 'zona_horaria' => 'America/El_Salvador', 'onboarding_completed' => true]);
        foreach ($permisos as $permiso) {
            $user->givePermissionTo(Permission::firstOrCreate(['name' => $permiso, 'guard_name' => 'web']));
        }
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_flujo_de_ingresos_por_api(): void
    {
        $this->usuario();

        $id = $this->postJson('/api/ingresos', [
            'nombre' => 'Sueldo', 'tipo_ingreso_id' => TipoIngreso::where('nombre', 'Sueldo (planilla)')->value('id'), 'frecuencia' => 'mensual', 'dia_pago' => 22, 'monto_bruto' => '1920.00', 'destino' => 'efectivo',
            'conceptos' => [['nombre' => 'AFP', 'tipo' => 'descuento', 'porcentaje' => '7.25'], ['nombre' => 'ISSS', 'tipo' => 'descuento', 'porcentaje' => '3', 'tope' => '30.00']],
        ])->assertCreated()->assertJsonCount(2, 'data.conceptos')->json('data.id');

        $this->getJson("/api/ingresos/{$id}/propuesta?bruto=2000")->assertOk()->assertJsonPath('data.neto', 1825);

        $this->postJson("/api/ingresos/{$id}/recibos", ['fecha' => '2026-10-22', 'bruto' => '1920.00', 'destino' => 'efectivo',
            'lineas' => [['nombre' => 'AFP', 'tipo' => 'descuento', 'monto' => '139.20'], ['nombre' => 'ISSS', 'tipo' => 'descuento', 'monto' => '30.00']]])
            ->assertCreated()->assertJsonPath('data.recibos.0.neto', '1750.80');

        $this->putJson("/api/ingresos/{$id}", ['nombre' => 'Sueldo Stef', 'tipo_ingreso_id' => TipoIngreso::where('nombre', 'Sueldo (planilla)')->value('id'), 'frecuencia' => 'mensual', 'destino' => 'efectivo'])
            ->assertOk()->assertJsonCount(2, 'data.conceptos'); // sin "conceptos" no se borra la plantilla
    }

    public function test_permisos_y_aislamiento(): void
    {
        $dueno = $this->usuario();
        $fuente = $dueno->fuentesIngreso()->create(['user_id' => $dueno->id, 'nombre' => 'X', 'destino' => 'efectivo']);

        $this->usuario(['ingresos.view']);
        $this->getJson("/api/ingresos/{$fuente->id}")->assertNotFound();
        $this->postJson('/api/ingresos', [])->assertForbidden();
    }
}
