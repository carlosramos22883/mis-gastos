<?php

namespace Tests\Feature;

use App\Models\EscenarioProyeccion;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ProyeccionWebTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-11 12:00:00');
        $this->user = User::factory()->create(['email_verified_at' => now(), 'fecha_corte_dia' => 23, 'zona_horaria' => 'America/El_Salvador', 'onboarding_completed' => true]);
        foreach (['view', 'create', 'edit', 'delete'] as $accion) {
            $this->user->givePermissionTo(Permission::firstOrCreate(['name' => "proyecciones.{$accion}", 'guard_name' => 'web']));
        }
        $this->user->hogar->update(['tope_gasto_ciclo' => 1800]);
        $tarjeta = $this->user->tarjetasCredito()->create(['nombre' => 'Visa', 'limite_credito' => 3000, 'dia_corte' => 10, 'dia_pago' => 5, 'fecha_registro' => '2026-11-01', 'color' => '#1155CC', 'tasa_interes' => 40]);
        $tarjeta->movimientos()->create(['tipo' => 'compra', 'descripcion' => 'Súper', 'monto' => 1200, 'fecha' => '2026-11-05']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function datos(array $extra = []): array
    {
        return $extra + ['nombre' => 'Vender el carro', 'modo_tarjetas' => 'rotar', 'perdida_rotar' => '1.5', 'sobrante' => 'abonar',
            'palancas' => json_encode([['tipo' => 'venta', 'descripcion' => 'Kia', 'mes' => '2026-12', 'monto' => 7000, 'destino' => ['tarjeta:'.$this->user->tarjetasCredito()->first()->id]]])];
    }

    public function test_guardar_comparar_y_eliminar_un_escenario(): void
    {
        $this->actingAs($this->user)->postJson(route('proyecciones.store'), $this->datos())->assertOk()->assertJsonPath('success', true);
        $escenario = EscenarioProyeccion::firstOrFail();
        $this->assertSame($this->user->hogar_id, $escenario->hogar_id);
        $this->assertSame(['modo_tarjetas' => 'rotar', 'perdida_rotar' => 1.5, 'sobrante' => 'abonar', 'orden' => 'mas_cara', 'prioridad' => [], 'meta' => null],
            array_diff_key($escenario->datos, ['palancas' => 1]));
        $this->assertSame(7000, $escenario->datos['palancas'][0]['monto']);

        // La pantalla compara "Si sigo igual" con el escenario: con la venta, la tarjeta queda en cero en diciembre
        $this->actingAs($this->user)->get(route('proyecciones.index'))->assertOk()
            ->assertSee('Si sigo igual')->assertSee('Vender el carro')->assertSee('Dic 2026');

        $this->actingAs($this->user)->deleteJson(route('proyecciones.destroy', $escenario))->assertOk();
        $this->assertDatabaseCount('escenarios_proyeccion', 0);
    }

    public function test_palancas_incompletas_y_escenarios_de_otro_hogar(): void
    {
        $this->actingAs($this->user)->postJson(route('proyecciones.store'), $this->datos(['palancas' => json_encode([['tipo' => 'prestamo', 'mes' => '2026-12']])]))
            ->assertUnprocessable()->assertJsonValidationErrors('palancas');
        $this->actingAs($this->user)->postJson(route('proyecciones.store'), $this->datos(['modo_tarjetas' => 'magia']))->assertUnprocessable()->assertJsonValidationErrors('modo_tarjetas');

        $otro = User::factory()->create(['email_verified_at' => now(), 'onboarding_completed' => true]);
        $ajeno = EscenarioProyeccion::create(['user_id' => $otro->id, 'nombre' => 'Ajeno', 'datos' => ['palancas' => []]]);
        $this->actingAs($this->user)->get(route('proyecciones.edit', $ajeno))->assertNotFound();
        $this->actingAs($this->user)->deleteJson(route('proyecciones.destroy', $ajeno))->assertNotFound();
    }

    public function test_orden_propio_y_meta_en_la_comparacion(): void
    {
        $tarjeta = 'tarjeta:'.$this->user->tarjetasCredito()->first()->id;
        $this->actingAs($this->user)->postJson(route('proyecciones.store'), $this->datos(['orden' => 'personalizado']))
            ->assertUnprocessable()->assertJsonValidationErrors('prioridad');

        $this->actingAs($this->user)->postJson(route('proyecciones.store'), $this->datos([
            'nombre' => 'Con meta', 'palancas' => '[]', 'orden' => 'personalizado', 'prioridad' => [$tarjeta],
            'meta_que' => 'tarjetas', 'meta_mes' => '2026-12',
        ]))->assertOk();
        $datos = EscenarioProyeccion::firstOrFail()->datos;
        $this->assertSame(['orden' => 'personalizado', 'prioridad' => [$tarjeta], 'meta' => ['que' => 'tarjetas', 'mes' => '2026-12']],
            array_intersect_key($datos, ['orden' => 1, 'prioridad' => 1, 'meta' => 1]));

        // Sin ingreso registrado no sobra nada: para pagar la tarjeta en diciembre hace falta ingreso extra
        $this->actingAs($this->user)->get(route('proyecciones.index'))->assertOk()->assertSee('Tarjetas en $0 para Dic 2026')->assertSee('Necesitas');
    }
}
