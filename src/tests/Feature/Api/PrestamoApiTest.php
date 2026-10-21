<?php

namespace Tests\Feature\Api;

use App\Models\Catalogos\TipoPrestamo;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PrestamoApiTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function usuario(array $permisos = ['prestamos.view', 'prestamos.create', 'prestamos.edit', 'prestamos.delete']): User
    {
        Carbon::setTestNow('2026-10-19 15:00:00');
        $user = User::factory()->create(['email_verified_at' => now(), 'fecha_corte_dia' => 22, 'zona_horaria' => 'America/El_Salvador', 'onboarding_completed' => true]);
        foreach ($permisos as $permiso) {
            $user->givePermissionTo(Permission::firstOrCreate(['name' => $permiso, 'guard_name' => 'web']));
        }
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_flujo_de_prestamo_por_api(): void
    {
        $this->usuario();

        $id = $this->postJson('/api/prestamos', [
            'nombre' => 'Préstamo de consumo', 'tipo_prestamo_id' => TipoPrestamo::where('nombre', 'Consumo / personal')->value('id'), 'monto_original' => '21550.00', 'tasa_anual' => '8.10', 'plazo_meses' => 120,
            'dia_pago' => 8, 'cuota' => '262.60', 'saldo_inicial' => '17642.63', 'fecha_saldo' => '2026-09-08', 'medio_pago' => 'planilla',
            'cargos' => [['nombre' => 'Seguros', 'monto' => '24.97'], ['nombre' => 'IVA', 'monto' => '1.12']],
        ])->assertCreated()->assertJsonPath('data.cuota_total', '288.69')->assertJsonPath('data.datos.saldo_inicial', '17642.63')->assertJsonPath('data.datos.fecha_saldo', '2026-09-08')->json('data.id');

        $this->postJson("/api/prestamos/{$id}/pagos", ['fecha' => '2026-10-08', 'capital' => '145.14', 'interes' => '117.46', 'cargos' => '26.09', 'origen' => 'planilla'])
            ->assertCreated()->assertJsonPath('data.saldo', '17497.49')->assertJsonPath('data.pagos.0.total', '288.69');

        $this->getJson("/api/prestamos/{$id}/proyeccion?extra_mensual=100")->assertOk()
            ->assertJsonPath('data.base.filas.0.fecha', '2026-11-08')
            ->assertJsonStructure(['data' => ['base' => ['meses', 'total_intereses', 'fecha_fin'], 'pagar_hoy' => ['monto', 'ahorro'], 'extra_mensual' => ['ahorro', 'meses_menos']]]);
        $this->getJson('/api/prestamos')->assertOk()->assertJsonPath('data.0.saldo', '17497.49')->assertJsonMissingPath('data.0.pagos');
    }

    public function test_permisos_y_aislamiento(): void
    {
        $dueno = $this->usuario();
        $prestamo = $dueno->prestamos()->create(['user_id' => $dueno->id, 'nombre' => 'X', 'monto_original' => 100, 'saldo_inicial' => 100, 'fecha_saldo' => '2026-10-01']);

        $this->usuario(['prestamos.view']);
        $this->getJson("/api/prestamos/{$prestamo->id}")->assertNotFound();
        $this->postJson('/api/prestamos', [])->assertForbidden();
    }
}
