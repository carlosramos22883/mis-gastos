<?php

namespace Tests\Feature;

use App\Models\Compromiso;
use App\Models\Cuenta;
use App\Models\MovimientoCuenta;
use App\Models\TarjetaCredito;
use App\Models\TipoCuenta;
use App\Models\User;
use App\Services\CompromisoService;
use App\Services\CuentaService;
use App\Services\TarjetaService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Pagar de más un estado de cuenta: la Davivienda Gold debía $78.06 del corte del 15/10/2026 y se pagaron $80.
 * Los $1.94 de más quedan como crédito en la tarjeta (suben el disponible y bajan el próximo corte).
 */
class PagoDeMasTarjetaTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private TarjetaCredito $tarjeta;

    private Cuenta $cuenta;

    private Compromiso $corte;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hoy('2026-09-20');
        $this->user = User::factory()->create(['email_verified_at' => now(), 'fecha_corte_dia' => 25, 'zona_horaria' => 'America/El_Salvador', 'onboarding_completed' => true]);
        foreach (['tarjetas', 'cuentas', 'compromisos', 'efectivo'] as $modulo) {
            foreach (['view', 'create', 'edit', 'delete'] as $accion) {
                $this->user->givePermissionTo(Permission::firstOrCreate(['name' => "{$modulo}.{$accion}", 'guard_name' => 'web']));
            }
        }
        $this->tarjeta = $this->user->tarjetasCredito()->create(['nombre' => 'Davivienda Gold', 'limite_credito' => 1000, 'dia_corte' => 15, 'dia_pago' => 5, 'fecha_registro' => '2026-09-16', 'color' => '#DC2626']);
        $this->servicio()->registrarMovimiento($this->tarjeta, ['tipo' => 'compra', 'descripcion' => 'Super', 'monto' => '78.06', 'fecha' => '2026-09-20']);
        $this->cuenta = $this->user->cuentas()->create(['nombre' => 'Agrícola', 'tipo_cuenta_id' => TipoCuenta::create(['nombre' => 'Ahorro', 'activo' => true])->id, 'saldo_inicial' => 500, 'fecha_inicial' => '2026-09-01']);

        $this->hoy('2026-10-20');
        $this->servicio()->generarEstados($this->tarjeta);
        $this->corte = $this->tarjeta->compromisos()->firstOrFail();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function hoy(string $fecha): void
    {
        Carbon::setTestNow($fecha.' 15:00:00');
    }

    private function servicio(): TarjetaService
    {
        return app(TarjetaService::class);
    }

    private function pagar(string $monto, ?Compromiso $compromiso = null)
    {
        return $this->actingAs($this->user)->postJson(route('compromisos.pagar.store', $compromiso ?? $this->corte), [
            'origen' => 'cuenta:'.$this->cuenta->id, 'monto' => $monto, 'fecha' => Carbon::now('America/El_Salvador')->toDateString(), 'descripcion' => 'Pago Davivienda',
        ]);
    }

    private function excedente()
    {
        return $this->tarjeta->movimientos()->whereNotNull('excedente_compromiso_id')->first();
    }

    private function cambiarPago(MovimientoCuenta $pago, string $monto): void
    {
        app(CuentaService::class)->actualizarMovimiento($pago, ['monto' => $monto] + $pago->only(['descripcion', 'fecha', 'tipo', 'categoria_personal_id']) + ['fecha' => $pago->fecha->toDateString()]);
    }

    public function test_lo_pagado_de_mas_queda_como_credito_en_la_tarjeta(): void
    {
        $this->assertSame('78.06', $this->corte->monto);

        $this->pagar('80.00')->assertOk()->assertJsonPath('message', 'Compromiso pagado por completo. Pagaste $ 1.94 de más: quedaron como crédito en la tarjeta.');

        $this->assertSame([Compromiso::PAGADO, '0.00'], [$this->corte->fresh()->estado, $this->corte->fresh()->saldo_pendiente]);
        $this->assertSame(420.0, app(CuentaService::class)->saldo($this->cuenta)); // sale lo que de verdad se pagó
        $credito = $this->excedente();
        $this->assertSame(['credito', '1.94', '2026-10-20', 'Pago de más al estado de cuenta del corte 15/10/2026'],
            [$credito->tipo, $credito->monto, $credito->fecha->toDateString(), $credito->descripcion]);
        $this->assertSame(TarjetaService::CATEGORIA_PAGO, $credito->categoria->nombre);
        $this->assertSame(1001.94, $this->servicio()->disponible($this->tarjeta)); // el disponible sube
        $this->assertSame(-1.94, $this->servicio()->porFacturar($this->tarjeta)->sum('monto'));
        // Se gestiona desde el pago, no desde la tarjeta
        $this->assertFalse($this->servicio()->esEditable($credito));
        $this->actingAs($this->user)->get(route('compromisos.show', $this->corte))->assertOk()->assertSee('Se pagaron $ 1.94 de más');
    }

    public function test_al_cambiar_o_eliminar_el_pago_el_excedente_lo_sigue(): void
    {
        $this->pagar('80.00')->assertOk();
        $pago = MovimientoCuenta::where('compromiso_id', $this->corte->id)->firstOrFail();

        $this->cambiarPago($pago, '79.00');
        $this->assertSame(['0.94', '0.00'], [$this->excedente()->monto, $this->corte->fresh()->saldo_pendiente]);

        $this->cambiarPago($pago->fresh(), '70.00'); // ya no alcanza: sin excedente y vuelve a quedar pendiente
        $this->assertNull($this->excedente());
        $this->assertSame([Compromiso::PENDIENTE, '8.06'], [$this->corte->fresh()->estado, $this->corte->fresh()->saldo_pendiente]);

        $this->cambiarPago($pago->fresh(), '85.00');
        $this->assertSame('6.94', $this->excedente()->monto);

        app(CuentaService::class)->eliminarMovimiento($pago->fresh());
        $this->assertNull($this->excedente());
        $this->assertSame('78.06', $this->corte->fresh()->saldo_pendiente);
        $this->assertSame(1000.0 - 78.06, $this->servicio()->disponible($this->tarjeta));
    }

    public function test_sin_movimientos_el_saldo_a_favor_pasa_al_corte_siguiente(): void
    {
        $this->pagar('80.00')->assertOk();

        // Corte del 15/11 sin compras: queda a favor y no genera pago
        $this->hoy('2026-11-20');
        $this->servicio()->generarEstados($this->tarjeta);
        $noviembre = $this->tarjeta->estadosCuenta()->whereDate('fecha_corte', '2026-11-15')->firstOrFail();
        $this->assertSame(['-1.94', null], [$noviembre->total, $noviembre->compromiso]);
        $this->assertSame(-1.94, $this->servicio()->saldoAFavor($this->tarjeta));

        // En el corte del 15/12 se descuenta de las compras
        $this->servicio()->registrarMovimiento($this->tarjeta, ['tipo' => 'compra', 'descripcion' => 'Farmacia', 'monto' => '50', 'fecha' => '2026-11-20']);
        $this->hoy('2026-12-20');
        $this->servicio()->generarEstados($this->tarjeta);
        $diciembre = $this->tarjeta->estadosCuenta()->whereDate('fecha_corte', '2026-12-15')->with('compromiso')->firstOrFail();
        $this->assertSame(['-1.94', '48.06', '48.06'], [$diciembre->saldo_anterior, $diciembre->total, $diciembre->compromiso->monto]);
        $this->actingAs($this->user)->get(route('estados-cuenta.show', $diciembre))->assertOk()->assertSee('Saldo a favor del corte anterior');

        // Un crédito que faltó anotar en noviembre también llega a diciembre
        $this->servicio()->registrarMovimiento($this->tarjeta, ['tipo' => 'credito', 'descripcion' => 'Devolución', 'monto' => '10', 'fecha' => '2026-11-10'], $noviembre);
        $diciembre->refresh();
        $this->assertSame(['-11.94', '38.06', '38.06'], [$diciembre->saldo_anterior, $diciembre->total, $diciembre->compromiso->fresh()->monto]);
    }

    public function test_por_api_informa_el_excedente(): void
    {
        Sanctum::actingAs($this->user);
        $this->postJson("/api/compromisos/{$this->corte->id}/pagar", [
            'origen' => 'cuenta:'.$this->cuenta->id, 'monto' => '80.00', 'fecha' => '2026-10-20', 'descripcion' => 'Pago Davivienda',
        ])->assertOk()->assertJsonPath('data.estado', Compromiso::PAGADO)->assertJsonPath('data.excedente', '1.94');

        $this->getJson("/api/tarjetas/{$this->tarjeta->id}")->assertOk()->assertJsonPath('data.por_facturar.total', '-1.94')
            ->assertJsonPath('data.movimientos.0.gestionado_en', 'Compromisos')->assertJsonPath('data.movimientos.0.editable', false);
    }

    public function test_un_compromiso_que_no_es_de_tarjeta_no_acepta_pagar_de_mas(): void
    {
        $luz = app(CompromisoService::class)->crearUnico($this->user, ['descripcion' => 'Luz', 'monto' => 30, 'fecha_esperada' => '2026-10-25', 'medio_pago' => 'por_definir']);
        $categoria = $this->user->categoriasPersonales()->create(['nombre' => 'Servicios', 'tipo' => 'egreso', 'activo' => true, 'color' => '#000000']);

        $this->actingAs($this->user)->postJson(route('compromisos.pagar.store', $luz), [
            'origen' => 'cuenta:'.$this->cuenta->id, 'monto' => '31.00', 'fecha' => '2026-10-20', 'descripcion' => 'Luz', 'categoria_personal_id' => $categoria->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('monto');
    }
}
