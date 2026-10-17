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
            'recurrencia_tipo' => 'unica',
        ]);

        $this->egreso($user, 10, '2026-09-01', ['compromiso_id' => $compromiso->id])->assertSessionHasNoErrors();
        $compromiso->refresh();
        $this->assertSame(['5.00', 0, 'activo'], [$compromiso->saldo_pendiente, $compromiso->cuotas_pagadas, $compromiso->estado]);

        $this->egreso($user, 6, '2026-09-01', ['compromiso_id' => $compromiso->id])->assertSessionHasErrors('monto');

        $this->egreso($user, 5, '2026-09-01', ['compromiso_id' => $compromiso->id])->assertSessionHasNoErrors();
        $compromiso->refresh();
        $this->assertSame(['0.00', 1, 'pagado'], [$compromiso->saldo_pendiente, $compromiso->cuotas_pagadas, $compromiso->estado]);
        $this->assertSame(85.0, app(CicloEfectivoService::class)->balance($user));
    }
}
