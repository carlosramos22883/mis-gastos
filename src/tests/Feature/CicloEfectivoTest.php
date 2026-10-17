<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CicloEfectivoService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class CicloEfectivoTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(int $corte, string $fechaInicial, float $efectivoInicial): User
    {
        Carbon::setTestNow($fechaInicial.' 10:00:00');
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'fecha_corte_dia' => $corte,
            'zona_horaria' => 'UTC',
            'onboarding_completed' => true,
        ]);
        foreach (['efectivo.view', 'efectivo.create', 'efectivo.edit', 'efectivo.delete'] as $permission) {
            $user->givePermissionTo(Permission::firstOrCreate(['name' => $permission]));
        }

        $categoria = $user->categoriasPersonales()->create(['nombre' => 'Saldo inicial', 'tipo' => 'ingreso']);
        $user->movimientosEfectivo()->create([
            'categoria_personal_id' => $categoria->id,
            'descripcion' => 'Saldo inicial',
            'monto' => $efectivoInicial,
            'fecha' => $fechaInicial,
            'tipo' => 'ingreso',
        ]);

        return $user;
    }

    private function egreso(User $user, float $monto, string $fecha, array $extra = [])
    {
        $categoria = $user->categoriasPersonales()->firstOrCreate(['nombre' => 'Comida', 'tipo' => 'egreso']);

        return $this->actingAs($user)->post(route('efectivo.store'), array_merge([
            'descripcion' => 'Gasto',
            'monto' => number_format($monto, 2, '.', ''),
            'fecha' => $fecha,
            'tipo' => 'egreso',
            'categoria_personal_id' => $categoria->id,
        ], $extra));
    }

    private function arrastres(User $user): array
    {
        return $user->movimientosEfectivo()->where('es_saldo_arrastrado', true)->orderBy('fecha')
            ->get()->map(fn ($m) => [$m->fecha->toDateString(), (float) $m->monto])->all();
    }

    public function test_cycle_closing_does_not_double_count_cash(): void
    {
        $user = $this->user(31, '2026-09-01', 10);
        Carbon::setTestNow('2026-09-10 10:00:00');
        $this->egreso($user, 5, '2026-09-10')->assertSessionHasNoErrors();

        Carbon::setTestNow('2026-10-02 10:00:00');
        app(CicloEfectivoService::class)->closeIfNeeded($user);

        $this->assertSame([['2026-10-01', 5.0]], $this->arrastres($user));
        $this->assertSame(5.0, app(CicloEfectivoService::class)->balance($user));
        $this->egreso($user, 6, '2026-10-02')->assertSessionHasErrors('monto');
        $this->egreso($user, 5, '2026-10-02')->assertSessionHasNoErrors();
    }

    public function test_cutoff_day_before_start_day_closes_every_pending_cycle(): void
    {
        $user = $this->user(5, '2026-09-20', 100);

        Carbon::setTestNow('2026-12-10 10:00:00');
        app(CicloEfectivoService::class)->closeIfNeeded($user);

        $this->assertSame([['2026-10-06', 100.0], ['2026-11-06', 100.0], ['2026-12-06', 100.0]], $this->arrastres($user));
        $this->assertSame(100.0, app(CicloEfectivoService::class)->balance($user));
    }

    public function test_cycle_end_adjusts_to_short_months(): void
    {
        $user = User::factory()->create(['fecha_corte_dia' => 31, 'zona_horaria' => 'UTC']);
        [$start, $end] = app(CicloEfectivoService::class)->cycleFor($user, Carbon::parse('2026-02-10'));

        $this->assertSame(['2026-02-01', '2026-02-28'], [$start->toDateString(), $end->toDateString()]);
    }

    public function test_carried_over_balance_cannot_be_edited(): void
    {
        $user = $this->user(31, '2026-09-01', 10);
        Carbon::setTestNow('2026-10-02 10:00:00');
        app(CicloEfectivoService::class)->closeIfNeeded($user);
        $arrastre = $user->movimientosEfectivo()->where('es_saldo_arrastrado', true)->first();

        $this->actingAs($user)->get(route('efectivo.edit', $arrastre))->assertForbidden();
    }

    public function test_income_cannot_be_deleted_if_cash_would_go_negative(): void
    {
        $user = $this->user(31, '2026-09-01', 0);
        $ingreso = $user->movimientosEfectivo()->create([
            'categoria_personal_id' => $user->categoriasPersonales()->create(['nombre' => 'Regalo', 'tipo' => 'ingreso'])->id,
            'descripcion' => 'Regalo',
            'monto' => 10,
            'fecha' => '2026-09-01',
            'tipo' => 'ingreso',
        ]);
        $this->egreso($user, 10, '2026-09-01')->assertSessionHasNoErrors();

        $this->actingAs($user)->delete(route('efectivo.destroy', $ingreso))->assertSessionHasErrors('monto');
        $this->assertDatabaseHas('movimientos_efectivo', ['id' => $ingreso->id]);
    }

    public function test_partial_commitment_payments_track_pending_balance(): void
    {
        $user = $this->user(31, '2026-09-01', 100);
        $compromiso = $user->compromisos()->create([
            'descripcion' => 'Pagar préstamo a David',
            'monto' => 15,
            'saldo_pendiente' => 15,
            'medio_pago' => 'efectivo',
            'estado' => 'pendiente',
        ]);

        $this->egreso($user, 10, '2026-09-01', ['compromiso_id' => $compromiso->id])->assertSessionHasNoErrors();
        $compromiso->refresh();
        $this->assertSame(['5.00', 'pendiente'], [$compromiso->saldo_pendiente, $compromiso->estado]);

        $this->egreso($user, 6, '2026-09-01', ['compromiso_id' => $compromiso->id])->assertSessionHasErrors('monto');

        $this->egreso($user, 5, '2026-09-01', ['compromiso_id' => $compromiso->id])->assertSessionHasNoErrors();
        $compromiso->refresh();
        $this->assertSame(['0.00', 'pagado'], [$compromiso->saldo_pendiente, $compromiso->estado]);
        $this->assertSame(85.0, app(CicloEfectivoService::class)->balance($user));
    }

    public function test_first_day_movement_is_editable_in_non_utc_timezone(): void
    {
        $user = $this->user(31, '2026-10-01', 10);
        $user->update(['zona_horaria' => 'America/El_Salvador']);
        Carbon::setTestNow('2026-10-17 10:00:00');
        $ingreso = $user->movimientosEfectivo()->create([
            'categoria_personal_id' => $user->categoriasPersonales()->create(['nombre' => 'Regalo', 'tipo' => 'ingreso'])->id,
            'descripcion' => 'Regalo', 'monto' => 5, 'fecha' => '2026-10-01', 'tipo' => 'ingreso',
        ]);

        $this->actingAs($user)->get(route('efectivo.edit', $ingreso))->assertOk();
    }

    public function test_navigates_between_cycles_using_cutoff_rule(): void
    {
        $user = $this->user(30, '2027-01-10', 50);
        Carbon::setTestNow('2027-03-15 10:00:00');
        app(CicloEfectivoService::class)->closeIfNeeded($user);

        // Ciclo actual: 1 al 30 de marzo (febrero termina el 28, así que marzo arranca el 1)
        $actual = $this->actingAs($user)->get(route('efectivo.index'))->assertOk();
        $actual->assertSeeInOrder(['del 01/03/2027 al', '30/03/2027'], false)->assertSee('cycle=2027-02-28', false)->assertDontSee('Ciclo siguiente');

        // Ciclo anterior: 31 de enero al 28 de febrero
        $this->actingAs($user)->get(route('efectivo.index', ['cycle' => '2027-02-28']))->assertOk()
            ->assertSeeInOrder(['del 31/01/2027 al', '28/02/2027'], false)
            ->assertSee('cycle=2027-01-30', false)
            ->assertSee('cycle=2027-03-01', false)
            ->assertSee('name="cycle" value="2027-02-28"', false);

        // El primer ciclo empieza con el saldo inicial y ya no ofrece "anterior"
        $this->actingAs($user)->get(route('efectivo.index', ['cycle' => '2027-01-30']))->assertOk()
            ->assertSeeInOrder(['del 10/01/2027 al', '30/01/2027'], false)
            ->assertDontSee('Ciclo anterior');

        // Un ciclo futuro no existe
        $this->actingAs($user)->get(route('efectivo.index', ['cycle' => '2027-04-15']))->assertNotFound();
    }

    public function test_ajax_table_keeps_requested_cycle(): void
    {
        $user = $this->user(31, '2026-09-01', 10);
        Carbon::setTestNow('2026-10-05 10:00:00');
        app(CicloEfectivoService::class)->closeIfNeeded($user);

        $html = $this->actingAs($user)->getJson(route('efectivo.index', ['cycle' => '2026-09-15']))->assertOk()->json('html');

        $this->assertStringContainsString('01/09/2026', $html);
        $this->assertStringNotContainsString('01/10/2026', $html);
    }
}
