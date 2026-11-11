<?php

namespace Tests\Feature;

use App\Models\TarjetaCredito;
use App\Models\User;
use App\Services\TarjetaService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/** Cargos fijos (seguro, Netflix…): se registran solos el día de su cobro, cada mes entre su inicio y su fin. */
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

    private function servicio(): TarjetaService
    {
        return app(TarjetaService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_se_registra_el_dia_de_su_cobro_y_entra_en_su_corte(): void
    {
        // Promerica corta el 9; Netflix se cobra el 15 desde el 01/11
        $this->actingAs($this->user)->postJson(route('tarjetas.cargos.store', $this->promerica), ['descripcion' => 'Netflix', 'monto' => '9.99', 'dia' => 15, 'desde' => '2026-11-01'])->assertOk();
        $cargo = $this->promerica->cargosFijos()->first();
        $this->assertSame('2026-11-15', $this->servicio()->proximoCobro($cargo)->toDateString());

        // Antes del 15 todavía no es movimiento ni descuenta el disponible
        Carbon::setTestNow('2026-11-14 12:00:00');
        $this->actingAs($this->user)->get(route('tarjetas.show', $this->promerica))->assertOk()->assertSee('El día 15 de cada mes', false)->assertSee('próximo cobro: 15/11/2026', false);
        $this->assertSame(0, $this->promerica->movimientos()->count());

        // El 15 se crea el movimiento con esa fecha y descuenta el disponible desde ya
        Carbon::setTestNow('2026-11-15 12:00:00');
        $this->servicio()->generarEstados($this->promerica->fresh());
        $this->assertSame(['2026-11-15'], $this->promerica->movimientos()->pluck('fecha')->map->toDateString()->all());
        $this->assertSame(2490.01, $this->servicio()->disponible($this->promerica->fresh()));

        // Generar de nuevo no lo repite; entra en el corte del 09/12 (el de diciembre, el 15/12, va al siguiente)
        $this->servicio()->generarEstados($this->promerica->fresh());
        $this->assertSame(1, $this->promerica->movimientos()->count());
        Carbon::setTestNow('2026-12-10 12:00:00');
        $this->servicio()->generarEstados($this->promerica->fresh());
        $estado = $this->promerica->estadosCuenta()->orderByDesc('fecha_corte')->first();
        $this->assertSame(['2026-12-09', '9.99'], [$estado->fecha_corte->toDateString(), $estado->total]);
        Carbon::setTestNow('2026-12-15 12:00:00');
        $this->servicio()->generarEstados($this->promerica->fresh());
        $this->assertSame(['2026-11-15', '2026-12-15'], $this->promerica->movimientos()->orderBy('fecha')->pluck('fecha')->map->toDateString()->all());
    }

    public function test_cambiar_el_dia_no_cobra_dos_veces_el_mismo_mes_y_la_fecha_fin_lo_termina(): void
    {
        $cargo = $this->promerica->cargosFijos()->create(['descripcion' => 'Netflix', 'monto' => 10, 'dia' => 15, 'desde' => '2026-11-01', 'hasta' => '2027-01-31', 'activo' => true]);
        Carbon::setTestNow('2026-11-15 12:00:00');
        $this->servicio()->generarEstados($this->promerica->fresh());
        $this->assertSame(1, $this->promerica->movimientos()->count());

        // Pasa a cobrarse el 16: en noviembre ya se cobró, el siguiente es el 16/12
        $this->actingAs($this->user)->putJson(route('tarjetas.cargos.update', [$this->promerica, $cargo]), ['descripcion' => 'Netflix', 'monto' => '10', 'dia' => 16, 'desde' => '2026-11-01', 'hasta' => '2027-01-31', 'activo' => '1'])->assertOk();
        Carbon::setTestNow('2026-11-16 12:00:00');
        $this->servicio()->generarEstados($this->promerica->fresh());
        $this->assertSame(1, $this->promerica->movimientos()->count());
        $this->assertSame('2026-12-16', $this->servicio()->proximoCobro($cargo->fresh())->toDateString());

        // Borrar un cobro no lo vuelve a crear; después de la fecha fin ya no se cobra
        $this->promerica->movimientos()->first()->delete();
        Carbon::setTestNow('2027-03-20 12:00:00');
        $this->servicio()->generarEstados($this->promerica->fresh());
        $this->assertSame(['2026-12-16', '2027-01-16'], $this->promerica->movimientos()->orderBy('fecha')->pluck('fecha')->map->toDateString()->all());
        $this->assertNull($this->servicio()->proximoCobro($cargo->fresh()));
    }

    public function test_dia_31_es_el_ultimo_dia_y_no_se_cobra_en_un_corte_ya_facturado(): void
    {
        // Inicio en el pasado: el 31/10 cae antes del período abierto (la tarjeta se registró el 10/10, ya cortó el 09/11)
        Carbon::setTestNow('2026-11-30 12:00:00');
        $this->servicio()->generarEstados($this->promerica);
        $this->promerica->cargosFijos()->create(['descripcion' => 'Seguro', 'monto' => 2, 'dia' => 31, 'desde' => '2026-10-01', 'activo' => true]);
        $this->servicio()->generarEstados($this->promerica->fresh());

        $this->assertSame(['2026-11-30'], $this->promerica->movimientos()->pluck('fecha')->map->toDateString()->all());
    }

    public function test_uno_inactivo_o_que_empieza_despues_no_se_cobra(): void
    {
        $this->promerica->cargosFijos()->create(['descripcion' => 'Seguro viejo', 'monto' => 3, 'dia' => 9, 'desde' => '2026-10-01', 'activo' => false]);
        $this->promerica->cargosFijos()->create(['descripcion' => 'Membresía', 'monto' => 10, 'dia' => 9, 'desde' => '2027-01-09', 'activo' => true]);

        Carbon::setTestNow('2026-11-10 12:00:00');
        $this->servicio()->generarEstados($this->promerica->fresh());

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

    public function test_se_administran_por_api(): void
    {
        Sanctum::actingAs($this->user);
        // Sin día (apps anteriores) se usa el de la fecha de inicio
        $id = $this->postJson("/api/tarjetas/{$this->promerica->id}/cargos-fijos", ['descripcion' => 'Seguro', 'monto' => '4.50', 'desde' => '2026-11-09'])
            ->assertCreated()->assertJsonPath('data.monto', '4.50')->assertJsonPath('data.activo', true)
            ->assertJsonPath('data.dia', 9)->assertJsonPath('data.hasta', null)->assertJsonPath('data.proximo_cobro', '2026-11-09')->json('data.id');

        $this->getJson("/api/tarjetas/{$this->promerica->id}")->assertOk()
            ->assertJsonPath('data.cargos_fijos.0.descripcion', 'Seguro')->assertJsonPath('data.cargos_proximo_corte', '4.50');

        $this->putJson("/api/tarjetas/{$this->promerica->id}/cargos-fijos/{$id}", ['descripcion' => 'Seguro de vida', 'monto' => '5.00', 'dia' => 31, 'desde' => '2026-11-09', 'hasta' => '2027-06-30', 'activo' => false])
            ->assertOk()->assertJsonPath('data.descripcion', 'Seguro de vida')->assertJsonPath('data.dia', 31)->assertJsonPath('data.hasta', '2027-06-30')->assertJsonPath('data.activo', false);
        $this->putJson("/api/tarjetas/{$this->promerica->id}/cargos-fijos/{$id}", ['descripcion' => 'X', 'monto' => '5', 'dia' => 32, 'desde' => '2026-11-09', 'hasta' => '2026-11-01'])
            ->assertJsonValidationErrors(['dia', 'hasta']);
        $this->postJson("/api/tarjetas/{$this->promerica->id}/cargos-fijos", ['descripcion' => '', 'monto' => '1.234'])->assertJsonValidationErrors(['descripcion', 'monto', 'desde']);

        // De otra tarjeta u otro hogar → 404
        $otra = User::factory()->create()->tarjetasCredito()->create(['nombre' => 'Ajena', 'limite_credito' => 100, 'dia_corte' => 1, 'dia_pago' => 20, 'fecha_registro' => '2026-10-01', 'color' => '#000000']);
        $this->deleteJson("/api/tarjetas/{$otra->id}/cargos-fijos/{$id}")->assertNotFound();

        $this->deleteJson("/api/tarjetas/{$this->promerica->id}/cargos-fijos/{$id}")->assertNoContent();
        $this->assertSame(0, $this->promerica->cargosFijos()->count());
    }
}
