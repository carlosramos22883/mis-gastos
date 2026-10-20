<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CompromisoService;
use App\Services\TarjetaService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class TarjetaInteresTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_costo_de_no_pagar_completo_un_estado_de_cuenta(): void
    {
        Carbon::setTestNow('2026-10-19 12:00:00');
        $user = User::factory()->create(['email_verified_at' => now(), 'fecha_corte_dia' => 22, 'onboarding_completed' => true]);
        $user->givePermissionTo(Permission::firstOrCreate(['name' => 'tarjetas.view', 'guard_name' => 'web']));
        $tarjeta = $user->tarjetasCredito()->create(['nombre' => 'Promerica', 'limite_credito' => 2500, 'dia_corte' => 9, 'dia_pago' => 6, 'fecha_registro' => '2026-10-10', 'color' => '#00B050']);
        $compromiso = app(CompromisoService::class)->crearUnico($user, ['descripcion' => 'Tarjeta Promerica · corte 09/10/2026', 'monto' => 1000,
            'fecha_esperada' => '2026-11-05', 'medio_pago' => 'por_definir', 'tarjeta_credito_id' => $tarjeta->id]);

        $this->assertNull(app(TarjetaService::class)->costoDeNoPagar($tarjeta, $compromiso));
        $this->actingAs($user)->get(route('tarjetas.show', $tarjeta))->assertOk()->assertSee('Agrega la');

        $tarjeta->update(['tasa_interes' => 36]);
        $this->assertSame(29.59, app(TarjetaService::class)->costoDeNoPagar($tarjeta, $compromiso)['interes_mes']); // 1000 × 36 % × 30 / 365
        $this->actingAs($user)->get(route('tarjetas.show', $tarjeta))->assertOk()->assertSee('29.59')->assertSee('05/11/2026');
    }
}
