<?php

namespace Tests\Feature;

use App\Models\Compromiso;
use App\Models\CompromisoRecurrente;
use App\Models\User;
use App\Services\CompromisoService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/** Editar la regla de una serie: se aplica a los pagos futuros sin tocar; lo pagado o abonado no cambia. */
class SerieCompromisoTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private CompromisoRecurrente $super;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-17 10:00:00');
        $this->user = User::factory()->create(['email_verified_at' => now(), 'fecha_corte_dia' => 31, 'zona_horaria' => 'America/El_Salvador', 'onboarding_completed' => true]);
        foreach (['view', 'create', 'edit', 'delete'] as $accion) {
            $this->user->givePermissionTo(Permission::firstOrCreate(['name' => "compromisos.{$accion}", 'guard_name' => 'web']));
        }
        $this->super = app(CompromisoService::class)->crearRecurrente($this->user, [
            'descripcion' => 'Super', 'monto' => 300, 'medio_pago' => 'efectivo', 'frecuencia' => 'mensual', 'dia_pago' => 20, 'fecha_inicio' => '2026-10-01',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function pago(): Compromiso
    {
        return $this->super->compromisos()->orderBy('fecha_esperada')->firstOrFail();
    }

    private function editar(array $datos)
    {
        return $this->actingAs($this->user)->putJson(route('compromisos.serie.update', $this->pago()), $datos + [
            'descripcion' => 'Super', 'monto' => '300.00', 'medio_pago' => 'efectivo', 'frecuencia' => 'mensual', 'dia_pago' => 20, 'finalizacion' => 'indefinido',
        ]);
    }

    public function test_subir_el_monto_cambia_los_pagos_futuros_sin_tocar(): void
    {
        $this->editar(['monto' => '350.00', 'descripcion' => 'Supermercado'])->assertOk();

        $pago = $this->pago();
        $this->assertSame('350.00', $pago->monto);
        $this->assertSame('350.00', $pago->saldo_pendiente);
        $this->assertSame('Supermercado', $pago->descripcion);
        $this->assertSame('350.00', $this->super->fresh()->monto);

        // Con un abono ya no se toca: el siguiente cambio solo aplica a la regla y a pagos nuevos
        app(CompromisoService::class)->abonar($pago, 100);
        $this->editar(['monto' => '400.00'])->assertOk();
        $this->assertSame('350.00', $this->pago()->monto);
        $this->assertSame('250.00', $this->pago()->saldo_pendiente);

        Carbon::setTestNow('2026-11-05 10:00:00');
        app(CompromisoService::class)->sincronizar($this->user);
        $this->assertSame('400.00', $this->super->compromisos()->orderByDesc('fecha_esperada')->first()->monto);
    }

    public function test_cambiar_el_dia_regenera_los_pagos_futuros(): void
    {
        $this->editar(['dia_pago' => 25])->assertOk();

        $fechas = $this->super->compromisos()->where('estado', Compromiso::PENDIENTE)->orderBy('fecha_esperada')->pluck('fecha_esperada')->map->toDateString()->all();
        $this->assertSame(['2026-10-25'], $fechas);
        $this->assertSame(1, $this->super->fresh()->cuotas_generadas);
    }

    public function test_ponerle_fin_detiene_la_serie(): void
    {
        $this->editar(['finalizacion' => 'cuotas', 'total_cuotas' => 1])->assertOk();
        $this->assertFalse($this->super->fresh()->activo);

        Carbon::setTestNow('2026-11-25 10:00:00');
        app(CompromisoService::class)->sincronizar($this->user);
        $this->assertSame(1, $this->super->compromisos()->count());

        // Extenderla la reactiva
        Carbon::setTestNow('2026-10-17 10:00:00');
        $this->editar(['finalizacion' => 'indefinido'])->assertOk();
        $this->assertTrue($this->super->fresh()->activo);
    }

    public function test_por_api_y_reglas(): void
    {
        Sanctum::actingAs($this->user);
        $this->putJson("/api/compromisos/{$this->pago()->id}/serie", ['descripcion' => 'Super', 'monto' => '320', 'medio_pago' => 'efectivo', 'frecuencia' => 'mensual', 'dia_pago' => 20, 'finalizacion' => 'indefinido'])
            ->assertOk()->assertJsonPath('data.monto', '320.00')->assertJsonPath('pagos_actualizados', 1);

        $this->putJson("/api/compromisos/{$this->pago()->id}/serie", ['descripcion' => 'Super', 'monto' => '320', 'medio_pago' => 'efectivo', 'frecuencia' => 'quincenal', 'dia_pago' => 20, 'finalizacion' => 'indefinido'])
            ->assertJsonValidationErrors('dia_pago_2');

        $unico = app(CompromisoService::class)->crearUnico($this->user, ['descripcion' => 'Una vez', 'monto' => 10, 'medio_pago' => 'efectivo']);
        $this->putJson("/api/compromisos/{$unico->id}/serie", ['descripcion' => 'x', 'monto' => '1', 'medio_pago' => 'efectivo', 'frecuencia' => 'mensual', 'dia_pago' => 1, 'finalizacion' => 'indefinido'])
            ->assertNotFound();
    }

    public function test_mover_el_dia_a_una_fecha_que_ya_paso_lo_deja_vencido_en_el_ciclo(): void
    {
        // Corte el 22: ciclo del 23/10 al 22/11. El pago del 21/11 se pasa al día 24 el 29/10: el 24/10 ya pasó
        $this->user->update(['fecha_corte_dia' => 22]);
        Carbon::setTestNow('2026-10-29 20:00:00');
        $serie = app(CompromisoService::class)->crearRecurrente($this->user, [
            'descripcion' => 'Cuota', 'monto' => 50, 'medio_pago' => 'efectivo', 'frecuencia' => 'mensual', 'dia_pago' => 21, 'fecha_inicio' => '2026-10-23',
        ]);
        $pago = $serie->compromisos()->sole();
        $this->assertSame('2026-11-21', $pago->fecha_esperada->toDateString());

        $respuesta = $this->actingAs($this->user)->putJson(route('compromisos.serie.update', $pago), [
            'descripcion' => 'Cuota', 'monto' => '50.00', 'medio_pago' => 'efectivo', 'frecuencia' => 'mensual', 'dia_pago' => 24, 'finalizacion' => 'indefinido',
        ])->assertOk();

        $nuevo = $serie->compromisos()->sole();
        $this->assertSame('2026-10-24', $nuevo->fecha_esperada->toDateString());
        $this->assertSame('2026-10-23', $nuevo->ciclo_inicio->toDateString());
        $this->assertSame('vencido', $nuevo->estadoVisual(Carbon::parse('2026-10-29'), Carbon::parse('2026-10-23')));
        // El detalle del pago quitado se va al que lo reemplaza
        $respuesta->assertJsonPath('redirect', route('compromisos.show', $nuevo));

        // El siguiente ciclo sigue con el 24
        Carbon::setTestNow('2026-11-25 10:00:00');
        app(CompromisoService::class)->sincronizar($this->user);
        $this->assertSame(['2026-10-24', '2026-11-24'], $serie->compromisos()->orderBy('fecha_esperada')->pluck('fecha_esperada')->map->toDateString()->all());
    }

    public function test_por_api_devuelve_el_pago_que_reemplaza_al_regenerado(): void
    {
        Sanctum::actingAs($this->user);
        $viejo = $this->pago();
        $this->putJson("/api/compromisos/{$viejo->id}/serie", ['descripcion' => 'Super', 'monto' => '300', 'medio_pago' => 'efectivo', 'frecuencia' => 'mensual', 'dia_pago' => 25, 'finalizacion' => 'indefinido'])
            ->assertOk()->assertJsonPath('data.id', $this->pago()->id)->assertJsonPath('data.fecha_esperada', '2026-10-25');
    }

    public function test_el_formulario_de_la_serie_abre(): void
    {
        $this->actingAs($this->user)->get(route('compromisos.serie.edit', $this->pago()))->assertOk()->assertSee('Editar la serie')->assertSee('Guardar la serie');
        $this->actingAs($this->user)->get(route('compromisos.show', $this->pago()))->assertOk()->assertSee('Editar la serie');
    }
}
