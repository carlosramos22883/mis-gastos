<?php

namespace Tests\Feature;

use App\Models\Compromiso;
use App\Models\CompromisoRecurrente;
use App\Models\User;
use App\Services\CompromisoService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class CompromisoTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(string $hoy = '2026-10-17', int $corte = 31): User
    {
        Carbon::setTestNow($hoy.' 10:00:00');
        $user = User::factory()->create(['email_verified_at' => now(), 'fecha_corte_dia' => $corte, 'zona_horaria' => 'America/El_Salvador', 'onboarding_completed' => true]);
        foreach (['compromisos.view', 'compromisos.create', 'compromisos.edit', 'compromisos.delete', 'efectivo.view', 'efectivo.create', 'efectivo.edit', 'efectivo.delete'] as $permission) {
            $user->givePermissionTo(Permission::firstOrCreate(['name' => $permission]));
        }

        return $user;
    }

    private function servicio(): CompromisoService
    {
        return app(CompromisoService::class);
    }

    private function fechas(array $atributos, string $desde, string $hasta): array
    {
        $regla = new CompromisoRecurrente($atributos);

        return array_map(fn (Carbon $f) => $f->toDateString(), $this->servicio()->fechasDePago($regla, Carbon::parse($desde), Carbon::parse($hasta)));
    }

    private function recurrente(User $user, array $atributos = []): CompromisoRecurrente
    {
        return $this->servicio()->crearRecurrente($user, array_merge([
            'descripcion' => 'Cuota de Gaby',
            'monto' => 150,
            'medio_pago' => 'efectivo',
            'frecuencia' => 'mensual',
            'dia_pago' => 20,
            'fecha_inicio' => '2026-10-01',
        ], $atributos));
    }

    public function test_last_day_of_month_adjusts_to_short_months(): void
    {
        $this->assertSame(
            ['2027-01-31', '2027-02-28', '2027-03-31'],
            $this->fechas(['frecuencia' => 'mensual', 'dia_pago' => 31], '2027-01-01', '2027-03-31')
        );
        $this->assertSame(
            ['2027-02-15', '2027-02-28', '2027-03-15', '2027-03-31'],
            $this->fechas(['frecuencia' => 'quincenal', 'dia_pago' => 15, 'dia_pago_2' => 31], '2027-02-01', '2027-03-31')
        );
        // 2028 es bisiesto
        $this->assertSame(['2028-02-29'], $this->fechas(['frecuencia' => 'mensual', 'dia_pago' => 31], '2028-02-01', '2028-02-29'));
    }

    public function test_weekly_dates(): void
    {
        // 2026-10-19 es lunes
        $this->assertSame(
            ['2026-10-19', '2026-10-26', '2026-11-02'],
            $this->fechas(['frecuencia' => 'semanal', 'dia_semana' => 1], '2026-10-17', '2026-11-08')
        );
    }

    public function test_recurring_commitment_generates_current_cycle_and_stops_after_last_installment(): void
    {
        $user = $this->user('2026-10-17');
        $regla = $this->recurrente($user, ['total_cuotas' => 2]);

        $this->assertSame([['2026-10-20', 1, 2, '2026-10-01']], $this->generados($user));

        Carbon::setTestNow('2026-11-05 10:00:00');
        $this->servicio()->sincronizar($user);
        $this->servicio()->sincronizar($user); // idempotente

        Carbon::setTestNow('2026-12-05 10:00:00');
        $this->servicio()->sincronizar($user);

        $this->assertSame([['2026-10-20', 1, 2, '2026-10-01'], ['2026-11-20', 2, 2, '2026-11-01']], $this->generados($user));
        $this->assertFalse($regla->fresh()->activo);
    }

    public function test_recurring_commitment_stops_at_end_date(): void
    {
        $user = $this->user('2026-10-17');
        $this->recurrente($user, ['frecuencia' => 'quincenal', 'dia_pago' => 15, 'dia_pago_2' => 31, 'fecha_fin' => '2026-11-15']);

        Carbon::setTestNow('2027-01-10 10:00:00');
        $this->servicio()->sincronizar($user);

        $this->assertSame(['2026-10-15', '2026-10-31', '2026-11-15'], array_column($this->generados($user), 0));
    }

    public function test_visual_states_match_database_filter(): void
    {
        $user = $this->user('2026-10-17');
        $crear = function (string $descripcion, ?string $fecha, float $saldo = 100) use ($user) {
            $compromiso = $this->servicio()->crearUnico($user, ['descripcion' => $descripcion, 'monto' => 100, 'medio_pago' => 'efectivo', 'fecha_esperada' => $fecha]);
            $compromiso->update(['saldo_pendiente' => $saldo]);

            return $compromiso;
        };

        $esperados = [
            'antiguo' => $crear('Antiguo', '2026-09-10'),
            'vencido' => $crear('Vencido', '2026-10-05'),
            'parcial' => $crear('Parcial', '2026-10-25', 40),
            'pendiente' => $crear('Pendiente', null),
        ];
        $proximo = $crear('Diciembre', '2026-12-10');
        [$hoy, $inicio] = [Carbon::parse('2026-10-17'), Carbon::parse('2026-10-01')];

        foreach ($esperados as $estado => $compromiso) {
            $this->assertSame($estado, $compromiso->fresh()->estadoVisual($hoy, $inicio));
            $this->assertSame([$compromiso->id], $user->compromisos()->estadoVisual($estado, $hoy, $inicio)->whereKeyNot($proximo->id)->pluck('id')->all(), $estado);
        }

        $this->assertSame('2026-12-01', $proximo->ciclo_inicio->toDateString());
        $actual = $this->actingAs($user)->getJson(route('compromisos.index'))->json('html');
        $this->assertStringNotContainsString('Diciembre', $actual);
        $this->assertStringContainsString('Antiguo', $actual);
        $proximos = $this->actingAs($user)->getJson(route('compromisos.index', ['periodo' => 'proximos']))->json('html');
        $this->assertStringContainsString('Diciembre', $proximos);
    }

    public function test_cancelling_series_keeps_paid_and_cancels_future(): void
    {
        $user = $this->user('2026-10-17');
        $this->recurrente($user, ['frecuencia' => 'semanal', 'dia_semana' => 1, 'fecha_inicio' => '2026-10-05']);
        $pagos = $user->compromisos()->orderBy('fecha_esperada')->get(); // 5, 12, 19 y 26 de octubre
        $pagos[0]->update(['estado' => Compromiso::PAGADO, 'saldo_pendiente' => 0]);

        $this->actingAs($user)->deleteJson(route('compromisos.destroy', $pagos[2]).'?alcance=serie')->assertOk();

        $this->assertSame(
            ['pagado', 'pendiente', 'cancelado', 'cancelado'],
            $user->compromisos()->orderBy('fecha_esperada')->pluck('estado')->all()
        );
        Carbon::setTestNow('2026-11-10 10:00:00');
        $this->servicio()->sincronizar($user);
        $this->assertSame(4, $user->compromisos()->count());
    }

    public function test_store_recurring_commitment_through_http(): void
    {
        $user = $this->user('2026-10-17');

        $this->actingAs($user)->postJson(route('compromisos.store'), [
            'descripcion' => 'Microbús', 'monto' => '37.50', 'medio_pago' => 'efectivo', 'recurrencia_tipo' => 'recurrente',
            'frecuencia' => 'quincenal', 'dia_pago' => 15, 'dia_pago_2' => 31, 'fecha_inicio' => '2026-10-17', 'finalizacion' => 'cuotas', 'total_cuotas' => 3,
        ])->assertOk()->assertJsonPath('success', true);

        $this->assertSame([['2026-10-31', 1, 3, '2026-10-01']], $this->generados($user));

        $this->actingAs($user)->postJson(route('compromisos.store'), [
            'descripcion' => 'Mal', 'monto' => '10', 'medio_pago' => 'efectivo', 'recurrencia_tipo' => 'recurrente',
            'frecuencia' => 'quincenal', 'dia_pago' => 15, 'dia_pago_2' => 15, 'fecha_inicio' => '2026-10-17', 'finalizacion' => 'indefinido',
        ])->assertUnprocessable()->assertJsonValidationErrors('dia_pago_2');
    }

    public function test_deleting_cash_payment_restores_commitment_balance(): void
    {
        $user = $this->user('2026-10-17');
        $saldoInicial = $user->categoriasPersonales()->create(['nombre' => 'Saldo inicial', 'tipo' => 'ingreso']);
        $user->movimientosEfectivo()->create(['categoria_personal_id' => $saldoInicial->id, 'descripcion' => 'Saldo inicial', 'monto' => 100, 'fecha' => '2026-10-01', 'tipo' => 'ingreso']);
        $categoria = $user->categoriasPersonales()->create(['nombre' => 'Deuda', 'tipo' => 'egreso']);
        $compromiso = $this->servicio()->crearUnico($user, ['descripcion' => 'Préstamo David', 'monto' => 10, 'medio_pago' => 'efectivo']);

        $this->actingAs($user)->post(route('efectivo.store'), [
            'descripcion' => 'Pago a David', 'monto' => '10.00', 'fecha' => '2026-10-17', 'tipo' => 'egreso',
            'categoria_personal_id' => $categoria->id, 'compromiso_id' => $compromiso->id,
        ])->assertSessionHasNoErrors();
        $this->assertSame('pagado', $compromiso->fresh()->estado);

        $pago = $compromiso->movimientos()->first();
        $this->actingAs($user)->delete(route('efectivo.destroy', $pago))->assertSessionHasNoErrors();

        $this->assertSame(['10.00', 'pendiente'], [$compromiso->fresh()->saldo_pendiente, $compromiso->fresh()->estado]);
    }

    private function generados(User $user): array
    {
        return $user->compromisos()->orderBy('fecha_esperada')->get()
            ->map(fn (Compromiso $c) => [$c->fecha_esperada->toDateString(), $c->numero_cuota, $c->total_cuotas, $c->ciclo_inicio->toDateString()])
            ->all();
    }
}
