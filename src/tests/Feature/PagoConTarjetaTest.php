<?php

namespace Tests\Feature;

use App\Models\Compromiso;
use App\Models\TarjetaCredito;
use App\Models\User;
use App\Services\CompromisoService;
use App\Services\TarjetaService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/** Pagar el estado de cuenta de una tarjeta (Credisiman) con otra tarjeta (Atlántida) vía NPE. */
class PagoConTarjetaTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private TarjetaCredito $atlantida;

    private TarjetaCredito $credisiman;

    private Compromiso $estado;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-19 12:00:00');
        $this->user = User::factory()->create(['email_verified_at' => now(), 'fecha_corte_dia' => 22, 'zona_horaria' => 'America/El_Salvador', 'onboarding_completed' => true]);
        foreach (['tarjetas', 'compromisos', 'efectivo', 'cuentas'] as $modulo) {
            foreach (['view', 'create', 'edit', 'delete'] as $accion) {
                $this->user->givePermissionTo(Permission::firstOrCreate(['name' => "{$modulo}.{$accion}", 'guard_name' => 'web']));
            }
        }
        $this->atlantida = $this->user->tarjetasCredito()->create(['nombre' => 'Atlantida', 'limite_credito' => 500, 'dia_corte' => 11, 'dia_pago' => 5, 'fecha_registro' => '2026-10-12', 'color' => '#434343']);
        $this->credisiman = $this->user->tarjetasCredito()->create(['nombre' => 'Credisiman', 'limite_credito' => 400, 'dia_corte' => 28, 'dia_pago' => 25, 'fecha_registro' => '2026-09-29', 'color' => '#1155CC', 'deuda_inicial' => 96.62]);
        $this->estado = app(CompromisoService::class)->crearUnico($this->user, ['descripcion' => 'Tarjeta Credisiman · corte 28/09/2026', 'monto' => 96.62,
            'fecha_esperada' => '2026-10-25', 'medio_pago' => 'por_definir', 'tarjeta_credito_id' => $this->credisiman->id]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function pagar(array $datos)
    {
        return $this->actingAs($this->user)->postJson(route('compromisos.pagar.store', $this->estado), $datos + [
            'origen' => 'tarjeta:'.$this->atlantida->id, 'monto' => '96.62', 'fecha' => '2026-10-19', 'descripcion' => 'Pago Credisiman con NPE',
        ]);
    }

    private function disponible(TarjetaCredito $t): float
    {
        return app(TarjetaService::class)->disponible($t->fresh());
    }

    public function test_el_formulario_ofrece_las_otras_tarjetas(): void
    {
        $this->actingAs($this->user)->get(route('compromisos.pagar', $this->estado))->assertOk()
            ->assertSee('Tarjeta Atlantida (disponible $ 500.00)')->assertDontSee('Tarjeta Credisiman (disponible');
    }

    public function test_abono_parcial_y_luego_el_resto_con_otra_tarjeta(): void
    {
        $this->assertSame(303.38, $this->disponible($this->credisiman));

        $this->pagar(['monto' => '50.00'])->assertOk()->assertJsonPath('message', 'Abono registrado. Pendiente: $ 46.62');
        $this->assertSame(450.0, $this->disponible($this->atlantida));   // la Atlántida carga el pago
        $this->assertSame(353.38, $this->disponible($this->credisiman)); // la Credisiman lo recupera

        $this->pagar(['monto' => '46.62'])->assertOk()->assertJsonPath('message', 'Compromiso pagado por completo.');
        $this->assertSame(Compromiso::PAGADO, $this->estado->fresh()->estado);
        $this->assertSame(400.0, $this->disponible($this->credisiman));

        $cargo = $this->atlantida->movimientos()->first();
        $this->assertNull($cargo->categoria_personal_id); // pagar otra tarjeta no es un gasto nuevo
        $this->actingAs($this->user)->get(route('compromisos.show', $this->estado))->assertOk()->assertSee('Tarjeta Atlantida');

        // Eliminar el cargo devuelve ese monto al estado de cuenta
        $this->actingAs($this->user)->deleteJson(route('tarjetas.movimientos.destroy', [$this->atlantida, $cargo]))->assertOk();
        $this->assertSame('50.00', $this->estado->fresh()->saldo_pendiente);
        $this->assertSame(Compromiso::PENDIENTE, $this->estado->fresh()->estado);
    }

    public function test_reglas(): void
    {
        // Una tarjeta no paga su propio estado de cuenta
        $this->pagar(['origen' => 'tarjeta:'.$this->credisiman->id])->assertUnprocessable()->assertJsonValidationErrors('origen');
        // Ni más de su disponible
        $this->atlantida->update(['limite_credito' => 50]);
        $this->pagar([])->assertUnprocessable()->assertJsonValidationErrors('monto');
        // Ni más de lo pendiente
        $this->atlantida->update(['limite_credito' => 500]);
        $this->pagar(['monto' => '100.00'])->assertUnprocessable()->assertJsonValidationErrors('monto');
    }

    public function test_por_api(): void
    {
        Sanctum::actingAs($this->user);
        $this->getJson("/api/compromisos/{$this->estado->id}/origenes-pago")->assertOk()->assertJsonPath('data.tarjeta:'.$this->atlantida->id, 'Tarjeta Atlantida (disponible $ 500.00)');
        $this->postJson("/api/compromisos/{$this->estado->id}/pagar", ['origen' => 'tarjeta:'.$this->atlantida->id, 'monto' => '96.62', 'fecha' => '2026-10-19', 'descripcion' => 'NPE'])
            ->assertOk()->assertJsonPath('data.estado', 'pagado');
        $this->postJson("/api/compromisos/{$this->estado->id}/pagar", ['origen' => 'efectivo', 'monto' => '1.00', 'fecha' => '2026-10-19', 'descripcion' => 'x'])
            ->assertUnprocessable();
    }
}
