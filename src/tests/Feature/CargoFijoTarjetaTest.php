<?php

namespace Tests\Feature;

use App\Models\TarjetaCredito;
use App\Models\User;
use App\Services\TarjetaService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/** Cargos fijos (seguro…): se agregan solos a cada estado de cuenta, una vez por corte. */
class CargoFijoTarjetaTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private TarjetaCredito $promerica;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-12 12:00:00');
        $this->user = User::factory()->create(['email_verified_at' => now(), 'fecha_corte_dia' => 22, 'zona_horaria' => 'America/El_Salvador', 'onboarding_completed' => true]);
        foreach (['view', 'create', 'edit', 'delete'] as $accion) {
            $this->user->givePermissionTo(Permission::firstOrCreate(['name' => "tarjetas.{$accion}", 'guard_name' => 'web']));
        }
        $this->promerica = $this->user->tarjetasCredito()->create(['nombre' => 'Promerica', 'limite_credito' => 2500, 'dia_corte' => 9, 'dia_pago' => 6, 'fecha_registro' => '2026-10-10', 'color' => '#00A651']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_se_agrega_en_cada_corte_una_sola_vez(): void
    {
        $this->actingAs($this->user)->postJson(route('tarjetas.cargos.store', $this->promerica), ['descripcion' => 'Seguro', 'monto' => '4.50', 'desde' => '2026-11-09'])->assertOk();

        // Antes del corte se ve como lo que se sumará
        $this->assertSame(4.5, (float) app(TarjetaService::class)->cargosFijosDelProximoCorte($this->promerica)->sum('monto'));
        $this->actingAs($this->user)->get(route('tarjetas.show', $this->promerica))->assertOk()->assertSee('Cargos fijos')->assertSee('incluye Seguro');

        // Pasa el corte del 09/11: el seguro entra a ese estado de cuenta
        Carbon::setTestNow('2026-11-10 12:00:00');
        app(TarjetaService::class)->generarEstados($this->promerica->fresh());
        $estado = $this->promerica->estadosCuenta()->first();
        $this->assertSame('4.50', $estado->total);
        $this->assertSame(1, $this->promerica->movimientos()->whereNotNull('cargo_fijo_id')->count());

        // Generar de nuevo no lo repite; el siguiente corte lo vuelve a cobrar
        app(TarjetaService::class)->generarEstados($this->promerica->fresh());
        $this->assertSame(1, $this->promerica->movimientos()->whereNotNull('cargo_fijo_id')->count());
        Carbon::setTestNow('2026-12-10 12:00:00');
        app(TarjetaService::class)->generarEstados($this->promerica->fresh());
        $this->assertSame(2, $this->promerica->movimientos()->whereNotNull('cargo_fijo_id')->count());
        $this->assertSame(9.0, app(TarjetaService::class)->deuda($this->promerica->fresh())); // dos cortes × $4.50
    }

    public function test_uno_inactivo_o_que_empieza_despues_no_se_cobra(): void
    {
        $this->promerica->cargosFijos()->create(['descripcion' => 'Seguro viejo', 'monto' => 3, 'desde' => '2026-10-01', 'activo' => false]);
        $this->promerica->cargosFijos()->create(['descripcion' => 'Membresía', 'monto' => 10, 'desde' => '2027-01-09', 'activo' => true]);

        Carbon::setTestNow('2026-11-10 12:00:00');
        app(TarjetaService::class)->generarEstados($this->promerica->fresh());

        $this->assertSame(0, $this->promerica->movimientos()->count());
    }

    public function test_las_tarjetas_se_ordenan_y_se_recuerda_el_orden(): void
    {
        $this->user->tarjetasCredito()->create(['nombre' => 'Atlántida', 'limite_credito' => 100, 'deuda_inicial' => 90, 'dia_corte' => 11, 'dia_pago' => 5, 'fecha_registro' => '2026-10-12', 'color' => '#434343']);

        $this->actingAs($this->user)->get(route('tarjetas.index', ['orden' => 'disponible']))->assertOk()->assertSeeInOrder(['Promerica', 'Atlántida']);
        $this->actingAs($this->user)->get(route('tarjetas.index', ['orden' => 'uso']))->assertOk()->assertSeeInOrder(['Atlántida', 'Promerica']);
        // Sin parámetro, se usa el último elegido
        $this->actingAs($this->user)->get(route('tarjetas.index'))->assertOk()->assertSeeInOrder(['Atlántida', 'Promerica']);
    }
}
