<?php

namespace Tests\Feature;

use App\Models\Cuenta;
use App\Models\TipoCuenta;
use App\Models\User;
use App\Services\CicloEfectivoService;
use App\Services\CompromisoService;
use App\Services\CuentaService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class CuentaTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private int $gasto;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-17 15:00:00');
        $this->user = User::factory()->create(['email_verified_at' => now(), 'fecha_corte_dia' => 25, 'zona_horaria' => 'America/El_Salvador', 'onboarding_completed' => true]);
        foreach (['cuentas', 'efectivo', 'compromisos', 'categorias'] as $modulo) {
            foreach (['view', 'create', 'edit', 'delete', 'export'] as $accion) {
                $this->user->givePermissionTo(Permission::firstOrCreate(['name' => "{$modulo}.{$accion}", 'guard_name' => 'web']));
            }
        }
        // $100 en efectivo desde el inicio del ciclo (26/09)
        $saldo = $this->user->categoriasPersonales()->create(['nombre' => 'Saldo inicial', 'tipo' => 'ingreso']);
        $this->user->movimientosEfectivo()->create(['categoria_personal_id' => $saldo->id, 'descripcion' => 'Saldo inicial', 'monto' => 100, 'fecha' => '2026-09-26', 'tipo' => 'ingreso']);
        $this->gasto = $this->user->categoriasPersonales()->create(['nombre' => 'Deuda', 'tipo' => 'egreso'])->id;
        TipoCuenta::create(['nombre' => 'Billetera digital', 'activo' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function cuenta(float $saldo = 50, string $nombre = 'BAC'): Cuenta
    {
        return $this->user->cuentas()->create(['nombre' => $nombre, 'tipo_cuenta_id' => TipoCuenta::first()->id, 'saldo_inicial' => $saldo, 'fecha_inicial' => '2026-10-01', 'color' => '#0284C7']);
    }

    private function saldo(Cuenta $cuenta): float
    {
        return app(CuentaService::class)->saldo($cuenta);
    }

    private function efectivo(): float
    {
        return app(CicloEfectivoService::class)->balance($this->user);
    }

    public function test_crea_cuenta_con_numero_encriptado(): void
    {
        $this->actingAs($this->user)->postJson(route('cuentas.store'), [
            'nombre' => 'Chivo', 'tipo_cuenta_id' => TipoCuenta::first()->id, 'numero_cuenta' => '200123456789',
            'saldo_inicial' => '25.50', 'fecha_inicial' => '15/10/2026', 'color' => '#F59E0B',
        ])->assertOk();

        $cuenta = $this->user->cuentas()->first();
        $this->assertSame('200123456789', $cuenta->numero_cuenta);
        $this->assertSame('•••• 6789', $cuenta->numeroEnmascarado());
        $this->assertNotSame('200123456789', DB::table('cuentas')->value('numero_cuenta'));
        $this->assertSame(25.5, $this->saldo($cuenta));

        // El saldo inicial no se cambia al editar
        $this->actingAs($this->user)->putJson(route('cuentas.update', $cuenta), [
            'nombre' => 'Chivo', 'tipo_cuenta_id' => TipoCuenta::first()->id, 'color' => '#F59E0B', 'saldo_inicial' => '999',
        ])->assertUnprocessable()->assertJsonValidationErrors('saldo_inicial');
    }

    public function test_movimientos_no_dejan_la_cuenta_en_negativo(): void
    {
        $cuenta = $this->cuenta(50);
        $mov = ['descripcion' => 'Super', 'fecha' => '2026-10-10', 'tipo' => 'egreso', 'categoria_personal_id' => $this->gasto];

        $this->actingAs($this->user)->postJson(route('cuentas.movimientos.store', $cuenta), ['monto' => '60'] + $mov)
            ->assertUnprocessable()->assertJsonValidationErrors('monto');
        $this->actingAs($this->user)->postJson(route('cuentas.movimientos.store', $cuenta), ['monto' => '30'] + $mov)->assertOk();
        // Antes de la fecha en que se registró la cuenta
        $this->actingAs($this->user)->postJson(route('cuentas.movimientos.store', $cuenta), ['monto' => '1', 'fecha' => '2026-09-30'] + $mov)
            ->assertUnprocessable()->assertJsonValidationErrors('fecha');

        $this->assertSame(20.0, $this->saldo($cuenta));
    }

    public function test_transferencia_de_efectivo_a_cuenta_y_su_eliminacion(): void
    {
        $cuenta = $this->cuenta(0);

        // "Metí al banco"
        $this->actingAs($this->user)->postJson(route('transferencias.store'), [
            'origen' => 'efectivo', 'destino' => 'cuenta:'.$cuenta->id, 'monto' => '80', 'fecha' => '2026-10-17', 'descripcion' => 'Metí al banco',
        ])->assertOk();
        $this->assertSame([20.0, 80.0], [$this->efectivo(), $this->saldo($cuenta)]);

        // No alcanza el efectivo; mismo origen y destino
        $this->actingAs($this->user)->postJson(route('transferencias.store'), ['origen' => 'efectivo', 'destino' => 'cuenta:'.$cuenta->id, 'monto' => '21', 'fecha' => '2026-10-17'])
            ->assertUnprocessable()->assertJsonValidationErrors('monto');
        $this->actingAs($this->user)->postJson(route('transferencias.store'), ['origen' => 'efectivo', 'destino' => 'efectivo', 'monto' => '1', 'fecha' => '2026-10-17'])
            ->assertUnprocessable()->assertJsonValidationErrors('destino');

        // Los movimientos de la transferencia no se editan sueltos
        $salida = $this->user->movimientosEfectivo()->whereNotNull('transferencia_id')->first();
        $this->actingAs($this->user)->get(route('efectivo.edit', $salida))->assertForbidden();

        // Si el dinero ya se gastó en la cuenta, la transferencia no se puede eliminar
        $transferencia = $this->user->transferencias()->first();
        $gasto = app(CuentaService::class)->registrarMovimiento($cuenta, ['descripcion' => 'Pago', 'monto' => 70, 'fecha' => '2026-10-17', 'tipo' => 'egreso', 'categoria_personal_id' => $this->gasto]);
        $this->actingAs($this->user)->deleteJson(route('transferencias.destroy', $transferencia))->assertUnprocessable();

        app(CuentaService::class)->eliminarMovimiento($gasto);
        $this->actingAs($this->user)->deleteJson(route('transferencias.destroy', $transferencia))->assertOk();
        $this->assertSame([100.0, 0.0], [$this->efectivo(), $this->saldo($cuenta)]);
        $this->assertSame(0, $this->user->movimientosEfectivo()->whereNotNull('transferencia_id')->count());
    }

    public function test_transferencia_entre_cuentas_y_retiro_a_efectivo(): void
    {
        $bac = $this->cuenta(200, 'BAC');
        $chivo = $this->cuenta(0, 'Chivo');

        $this->actingAs($this->user)->postJson(route('transferencias.store'), ['origen' => 'cuenta:'.$bac->id, 'destino' => 'cuenta:'.$chivo->id, 'monto' => '150', 'fecha' => '2026-10-17'])->assertOk();
        $this->actingAs($this->user)->postJson(route('transferencias.store'), ['origen' => 'cuenta:'.$chivo->id, 'destino' => 'efectivo', 'monto' => '100', 'fecha' => '2026-10-17'])->assertOk();

        $this->assertSame([50.0, 50.0, 200.0], [$this->saldo($bac), $this->saldo($chivo), $this->efectivo()]);
    }

    public function test_pagar_compromiso_desde_cuenta(): void
    {
        $cuenta = $this->cuenta(100);
        $compromiso = app(CompromisoService::class)->crearUnico($this->user, ['descripcion' => 'Cuota de Gaby', 'monto' => 150, 'medio_pago' => 'transferencia']);

        $this->actingAs($this->user)->get(route('compromisos.pagar', $compromiso))->assertOk()->assertSee('BAC');
        $this->actingAs($this->user)->postJson(route('compromisos.pagar.store', $compromiso), [
            'origen' => 'cuenta:'.$cuenta->id, 'monto' => '100', 'fecha' => '2026-10-17', 'descripcion' => 'Cuota de Gaby', 'categoria_personal_id' => $this->gasto,
        ])->assertOk()->assertJsonPath('success', true);

        $this->assertSame(['50.00', 'pendiente'], [$compromiso->fresh()->saldo_pendiente, $compromiso->fresh()->estado]);
        $this->assertSame(0.0, $this->saldo($cuenta));

        // El abono aparece en el detalle con el nombre de la cuenta
        $this->actingAs($this->user)->get(route('compromisos.show', $compromiso))->assertOk()->assertSeeInOrder(['Abonos', 'BAC']);

        // Eliminar el egreso devuelve el saldo al compromiso
        $pago = $cuenta->movimientos()->first();
        $this->actingAs($this->user)->deleteJson(route('cuentas.movimientos.destroy', [$cuenta, $pago]))->assertOk();
        $this->assertSame('150.00', $compromiso->fresh()->saldo_pendiente);
    }

    public function test_resumen_por_ciclo_y_pantallas(): void
    {
        $cuenta = $this->cuenta(0);
        $cuenta->update(['fecha_inicial' => '2026-08-01']);
        $cuenta->movimientos()->create(['user_id' => $this->user->id, 'descripcion' => 'Sueldo', 'monto' => 500, 'fecha' => '2026-09-10', 'tipo' => 'ingreso']);
        $cuenta->movimientos()->create(['user_id' => $this->user->id, 'descripcion' => 'Super', 'monto' => 120, 'fecha' => '2026-10-05', 'tipo' => 'egreso']);
        [$inicio, $fin] = app(CicloEfectivoService::class)->cicloConsultado($this->user);

        $this->assertSame(['saldo_inicial' => 500.0, 'ingresos' => 0.0, 'egresos' => 120.0, 'saldo_final' => 380.0], app(CuentaService::class)->resumen($cuenta, $inicio, $fin));

        $this->actingAs($this->user)->get(route('cuentas.index'))->assertOk()->assertSee('380.00');
        $this->actingAs($this->user)->get(route('cuentas.show', $cuenta))->assertOk()->assertSee('Super')->assertDontSee('Sueldo');
        $this->actingAs($this->user)->get(route('cuentas.show', ['cuenta' => $cuenta, 'cycle' => '2026-09-10']))->assertOk()->assertSee('Sueldo')->assertSee('Ciclo cerrado');
        $this->actingAs($this->user)->get(route('cuentas.export', ['cuenta' => $cuenta, 'format' => 'csv']))->assertOk();
    }

    public function test_cuenta_con_movimientos_no_se_elimina_y_cuentas_ajenas_no_se_ven(): void
    {
        $cuenta = $this->cuenta(10);
        $vacia = $this->cuenta(0, 'Vacía');
        $cuenta->movimientos()->create(['user_id' => $this->user->id, 'descripcion' => 'X', 'monto' => 1, 'fecha' => '2026-10-05', 'tipo' => 'egreso', 'categoria_personal_id' => $this->gasto]);

        $this->actingAs($this->user)->deleteJson(route('cuentas.destroy', $cuenta))->assertUnprocessable();
        $this->actingAs($this->user)->deleteJson(route('cuentas.destroy', $vacia))->assertOk();
        // La categoría está en uso por un movimiento de cuenta
        $this->actingAs($this->user)->deleteJson(route('categorias.destroy', $this->gasto))->assertUnprocessable();

        $otro = User::factory()->create(['email_verified_at' => now()]);
        $otro->givePermissionTo('cuentas.view');
        $this->actingAs($otro)->get(route('cuentas.show', $cuenta))->assertNotFound();
    }

    public function test_exportar_compromisos_usa_su_ruta(): void
    {
        $this->actingAs($this->user)->get(route('compromisos.export', ['format' => 'csv']))->assertOk();
        $this->actingAs($this->user)->get(route('compromisos.index'))->assertSee('data-export-url="'.route('compromisos.export').'"', false);
    }
}
