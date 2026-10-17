<?php

namespace Tests\Feature;

use App\Models\Banco;
use App\Models\Compromiso;
use App\Models\MarcaRed;
use App\Models\TarjetaCredito;
use App\Models\TipoCuenta;
use App\Models\User;
use App\Services\CuentaService;
use App\Services\TarjetaService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/** Tarjeta de prueba: límite $1,000, corte el 15, pago el 5. Hoy es 17/10/2026. */
class TarjetaTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hoy('2026-10-17');
        $this->user = User::factory()->create(['email_verified_at' => now(), 'fecha_corte_dia' => 25, 'zona_horaria' => 'America/El_Salvador', 'onboarding_completed' => true]);
        foreach (['tarjetas', 'cuentas', 'compromisos', 'efectivo'] as $modulo) {
            foreach (['view', 'create', 'edit', 'delete', 'export'] as $accion) {
                $this->user->givePermissionTo(Permission::firstOrCreate(['name' => "{$modulo}.{$accion}", 'guard_name' => 'web']));
            }
        }
        Banco::create(['nombre' => 'BAC', 'activo' => true]);
        MarcaRed::create(['nombre' => 'Visa', 'activo' => true]);
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

    /** Tarjeta con $300 usados: $200 en un estado de cuenta pendiente y $100 sin facturar. */
    private function tarjeta(): TarjetaCredito
    {
        $this->actingAs($this->user)->postJson(route('tarjetas.store'), [
            'nombre' => 'BAC Walmart', 'banco_id' => Banco::first()->id, 'marca_red_id' => MarcaRed::first()->id, 'color' => '#DC2626',
            'limite_credito' => '1000', 'dia_corte' => 15, 'dia_pago' => 5, 'disponible' => '700',
            'tiene_pendiente' => '1', 'pendiente_monto' => '200', 'pendiente_fecha' => '05/11/2026',
        ])->assertOk();

        return $this->user->tarjetasCredito()->first();
    }

    private function compra(TarjetaCredito $tarjeta, string $monto, ?int $cuotas = null, string $descripcion = 'Compra')
    {
        return $this->actingAs($this->user)->postJson(route('tarjetas.movimientos.store', $tarjeta), array_filter([
            'tipo' => 'compra', 'descripcion' => $descripcion, 'monto' => $monto, 'fecha' => Carbon::now('America/El_Salvador')->toDateString(), 'cuotas' => $cuotas,
        ]));
    }

    public function test_registro_con_estado_pendiente_y_consumos_sin_facturar(): void
    {
        $tarjeta = $this->tarjeta();

        $this->assertSame([300.0, 700.0], [$this->servicio()->deuda($tarjeta), $this->servicio()->disponible($tarjeta)]);
        $pendiente = $tarjeta->compromisos()->first();
        $this->assertSame(['200.00', '2026-11-05'], [$pendiente->monto, $pendiente->fecha_esperada->toDateString()]);
        $this->assertSame(100.0, $this->servicio()->porFacturar($tarjeta)->sum('monto'));

        $this->actingAs($this->user)->get(route('tarjetas.index'))->assertOk()->assertSee('BAC Walmart')->assertSee('700.00');
        $this->actingAs($this->user)->get(route('tarjetas.show', $tarjeta))->assertOk()->assertSee('Por facturar en el corte del 15/11/2026', false);
    }

    public function test_compras_descuentan_el_disponible_y_respetan_el_limite(): void
    {
        $tarjeta = $this->tarjeta();

        $this->compra($tarjeta, '400')->assertOk();
        $this->compra($tarjeta, '600', 6)->assertUnprocessable()->assertJsonValidationErrors('monto');
        $this->compra($tarjeta, '100', 3, 'TV a tasa cero')->assertOk();

        // En cuotas se descuenta el total desde ya
        $this->assertSame(200.0, $this->servicio()->disponible($tarjeta));
    }

    public function test_cortes_facturan_cuotas_y_generan_su_compromiso(): void
    {
        $tarjeta = $this->tarjeta();
        $this->compra($tarjeta, '400')->assertOk();
        $this->compra($tarjeta, '100', 3, 'TV a tasa cero')->assertOk();

        // El corte del 15/10 ya pasó cuando se registró la tarjeta: el primero es el 15/11
        $this->hoy('2026-11-16');
        $this->assertSame(1, $this->servicio()->generarEstados($tarjeta));
        $estado = $tarjeta->estadosCuenta()->first();
        $this->assertSame(['2026-10-17', '2026-11-15', '2026-12-05', '533.33'], [
            $estado->fecha_inicio->toDateString(), $estado->fecha_corte->toDateString(), $estado->fecha_limite_pago->toDateString(), $estado->total,
        ]);
        $this->assertSame(['533.33', '2026-12-05'], [$estado->compromiso->monto, $estado->compromiso->fecha_esperada->toDateString()]);

        // Cuotas siguientes, la última absorbe los centavos: 33.33 + 33.33 + 33.34
        $this->hoy('2027-01-16');
        $this->assertSame(2, $this->servicio()->generarEstados($tarjeta));
        $this->assertSame(['533.33', '33.33', '33.34'], $tarjeta->estadosCuenta()->orderBy('fecha_corte')->pluck('total')->all());
        $this->hoy('2027-02-16');
        $this->servicio()->generarEstados($tarjeta);
        $this->assertSame('0.00', $tarjeta->estadosCuenta()->orderByDesc('fecha_corte')->value('total'));
        $this->assertSame(4, $tarjeta->estadosCuenta()->count());
        $this->assertSame(3, $tarjeta->compromisos()->whereNotNull('estado_cuenta_tarjeta_id')->count()); // sin saldo no hay compromiso
    }

    public function test_pagar_el_estado_de_cuenta_recupera_el_disponible(): void
    {
        $tarjeta = $this->tarjeta();
        $cuenta = $this->user->cuentas()->create(['nombre' => 'BAC Carlos', 'tipo_cuenta_id' => TipoCuenta::create(['nombre' => 'Ahorro', 'activo' => true])->id, 'saldo_inicial' => 500, 'fecha_inicial' => '2026-10-01']);
        $pendiente = $tarjeta->compromisos()->first();

        // Sin categoría: el pago de una tarjeta no es un gasto nuevo
        $this->actingAs($this->user)->postJson(route('compromisos.pagar.store', $pendiente), [
            'origen' => 'cuenta:'.$cuenta->id, 'monto' => '150', 'fecha' => '2026-10-17', 'descripcion' => 'Pago BAC Walmart',
        ])->assertOk();

        $this->assertSame([850.0, 350.0, '50.00'], [$this->servicio()->disponible($tarjeta), app(CuentaService::class)->saldo($cuenta), $pendiente->fresh()->saldo_pendiente]);
    }

    public function test_avance_hacia_una_billetera_y_su_eliminacion(): void
    {
        $tarjeta = $this->tarjeta();
        $chivo = $this->user->cuentas()->create(['nombre' => 'Chivo', 'tipo_cuenta_id' => TipoCuenta::create(['nombre' => 'Billetera digital', 'activo' => true])->id, 'saldo_inicial' => 0, 'fecha_inicial' => '2026-10-01']);

        $this->actingAs($this->user)->postJson(route('transferencias.store'), ['origen' => 'tarjeta:'.$tarjeta->id, 'destino' => 'cuenta:'.$chivo->id, 'monto' => '800', 'fecha' => '2026-10-17'])
            ->assertUnprocessable()->assertJsonValidationErrors('monto');
        $this->actingAs($this->user)->postJson(route('transferencias.store'), ['origen' => 'tarjeta:'.$tarjeta->id, 'destino' => 'cuenta:'.$chivo->id, 'monto' => '500', 'fecha' => '2026-10-17', 'descripcion' => 'Chivo'])
            ->assertOk();
        $this->assertSame([200.0, 500.0], [$this->servicio()->disponible($tarjeta), app(CuentaService::class)->saldo($chivo)]);

        // Una vez facturado, el avance ya no se puede deshacer
        $transferencia = $this->user->transferencias()->first();
        $this->hoy('2026-11-16');
        $this->servicio()->generarEstados($tarjeta);
        $this->assertSame('600.00', $tarjeta->estadosCuenta()->value('total'));
        $this->actingAs($this->user)->deleteJson(route('transferencias.destroy', $transferencia))->assertStatus(403); // además ya es de otro ciclo
    }

    public function test_mora_de_un_estado_vencido_y_fecha_limite_editable(): void
    {
        $tarjeta = $this->tarjeta();
        $this->hoy('2026-11-16');
        $this->servicio()->generarEstados($tarjeta);
        $estado = $tarjeta->estadosCuenta()->first();

        // La fecha límite cambia este mes: el compromiso la sigue
        $this->actingAs($this->user)->patchJson(route('estados-cuenta.fecha-limite', $estado), ['fecha_limite_pago' => '2026-12-08'])->assertOk();
        $this->assertSame('2026-12-08', $estado->compromiso->fresh()->fecha_esperada->toDateString());

        // Aún no vence: no se puede registrar mora
        $this->actingAs($this->user)->postJson(route('estados-cuenta.mora.store', $estado), ['descripcion' => 'Mora', 'monto' => '15'])->assertUnprocessable();

        $this->hoy('2026-12-10');
        $this->actingAs($this->user)->postJson(route('estados-cuenta.mora.store', $estado), ['descripcion' => 'Mora de tarjeta de crédito', 'monto' => '15'])->assertOk();
        $mora = Compromiso::where('descripcion', 'Mora de tarjeta de crédito')->first();
        $this->assertSame(['15.00', $tarjeta->id], [$mora->monto, $mora->tarjeta_credito_id]);
        $this->assertSame(315.0, $this->servicio()->deuda($tarjeta));

        // La mora se cobra en su propio compromiso, no en el siguiente corte
        $this->hoy('2026-12-16');
        $this->servicio()->generarEstados($tarjeta);
        $this->assertSame('0.00', $tarjeta->estadosCuenta()->orderByDesc('fecha_corte')->value('total'));
    }

    public function test_solo_se_edita_lo_no_facturado_y_en_el_periodo_abierto(): void
    {
        $tarjeta = $this->tarjeta();
        $this->compra($tarjeta, '50')->assertOk();
        $compra = $tarjeta->movimientos()->where('descripcion', 'Compra')->first();

        $this->actingAs($this->user)->putJson(route('tarjetas.movimientos.update', [$tarjeta, $compra]), ['tipo' => 'compra', 'descripcion' => 'Compra', 'monto' => '60', 'fecha' => '2026-10-17'])->assertOk();
        $this->actingAs($this->user)->postJson(route('tarjetas.movimientos.store', $tarjeta), ['tipo' => 'compra', 'descripcion' => 'Vieja', 'monto' => '5', 'fecha' => '2026-10-10'])
            ->assertUnprocessable()->assertJsonValidationErrors('fecha');

        $this->hoy('2026-11-16');
        $this->servicio()->generarEstados($tarjeta);
        $this->actingAs($this->user)->deleteJson(route('tarjetas.movimientos.destroy', [$tarjeta, $compra]))->assertForbidden();
        $this->actingAs($this->user)->deleteJson(route('tarjetas.destroy', $tarjeta))->assertUnprocessable();

        // Pantallas con estados de cuenta y su detalle
        $this->actingAs($this->user)->get(route('tarjetas.show', $tarjeta))->assertOk()->assertSee('15/11/2026');
        $this->actingAs($this->user)->get(route('estados-cuenta.show', $tarjeta->estadosCuenta()->first()))->assertOk()->assertSee('Consumos sin facturar');
        $this->actingAs($this->user)->get(route('compromisos.index'))->assertOk()->assertSee('Tarjeta BAC Walmart');
    }
}
