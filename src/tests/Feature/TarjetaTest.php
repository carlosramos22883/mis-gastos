<?php

namespace Tests\Feature;

use App\Models\Banco;
use App\Models\Compromiso;
use App\Models\MarcaRed;
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
        $this->compra($tarjeta, '600', 6)->assertUnprocessable()->assertJsonValidationErrors('confirmar')->assertJsonMissingValidationErrors('monto');
        $this->compra($tarjeta, '100', 3, 'TV a tasa cero')->assertOk();

        // En cuotas se descuenta el total desde ya
        $this->assertSame(200.0, $this->servicio()->disponible($tarjeta));
    }

    public function test_una_compra_puede_sobregirar_la_tarjeta_si_se_confirma(): void
    {
        $tarjeta = $this->tarjeta();
        $this->compra($tarjeta, '690')->assertOk();

        $this->compra($tarjeta, '25')->assertUnprocessable()
            ->assertJsonPath('errors.confirmar.0', 'El monto supera el disponible de la tarjeta: solo quedan $ 10.00 y quedaría sobregirada por $ 15.00. ¿Deseas registrarlo de todas formas?');
        $this->assertSame(10.0, $this->servicio()->disponible($tarjeta));

        $this->actingAs($this->user)->postJson(route('tarjetas.movimientos.store', $tarjeta), [
            'tipo' => 'compra', 'descripcion' => 'Sobregiro', 'monto' => '25', 'fecha' => Carbon::now('America/El_Salvador')->toDateString(), 'confirmar' => '1',
        ])->assertOk();
        $this->assertSame(-15.0, $this->servicio()->disponible($tarjeta));

        // Ya sobregirada, editar una compra también pide confirmación
        $compra = $tarjeta->movimientos()->where('descripcion', 'Sobregiro')->first();
        $this->editarMovimiento($tarjeta, $compra, ['monto' => '30', 'fecha' => $compra->fecha->toDateString()])->assertUnprocessable()->assertJsonValidationErrors('confirmar');
        $this->editarMovimiento($tarjeta, $compra, ['monto' => '30', 'fecha' => $compra->fecha->toDateString(), 'confirmar' => true])->assertOk();
        $this->assertSame(-20.0, $this->servicio()->disponible($tarjeta));
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

    public function test_cuotas_con_dia_de_cobro_entran_en_el_corte_de_su_fecha(): void
    {
        $tarjeta = $this->tarjeta(); // corta el 15; hoy 17/10
        $this->actingAs($this->user)->postJson(route('tarjetas.movimientos.store', $tarjeta), [
            'tipo' => 'compra', 'descripcion' => 'TV', 'monto' => '100', 'fecha' => '2026-10-17', 'cuotas' => 3, 'dia_cuota' => 20,
        ])->assertOk();
        $tv = $tarjeta->movimientos()->where('descripcion', 'TV')->first();
        $this->assertSame(['2026-10-20', '2026-11-20', '2026-12-20'], collect([1, 2, 3])->map(fn ($n) => $this->servicio()->fechaCuota($tv, $n)->toDateString())->all());

        // El total se descuenta del disponible desde ya; la cuota 1 aún no llega: se avisa y no suma a lo cobrado
        $this->assertSame(600.0, $this->servicio()->disponible($tarjeta));
        $linea = $this->servicio()->porFacturar($tarjeta)->firstWhere('cuota_numero', 1);
        $this->assertSame(['2026-10-20', true], [$linea['fecha']->toDateString(), $linea['programada']]);
        $this->actingAs($this->user)->get(route('tarjetas.show', $tarjeta))->assertOk()
            ->assertSee('cuota 1 de 3 de TV el 20/10', false)->assertSee('El día 20 de cada mes · próxima: 20/10/2026', false);
        $this->actingAs($this->user)->getJson("/api/tarjetas/{$tarjeta->id}")->assertOk()->assertJsonPath('data.por_facturar.total', '100.00');

        // El 20 ya cuenta; cada cuota va al corte que contiene su fecha y la última absorbe los centavos
        $this->hoy('2026-10-20');
        $this->assertFalse($this->servicio()->porFacturar($tarjeta)->firstWhere('cuota_numero', 1)['programada']);
        $this->hoy('2027-01-16');
        $this->servicio()->generarEstados($tarjeta);
        $this->assertSame(['133.33', '33.33', '33.34'], $tarjeta->estadosCuenta()->orderBy('fecha_corte')->pluck('total')->all());
    }

    public function test_cuotas_atrasadas_entran_juntas_y_sin_cuotas_no_hay_dia(): void
    {
        $tarjeta = $this->tarjeta();
        // Día 5: la primera es el 05/11 (el 5 de octubre ya pasó)
        $this->actingAs($this->user)->postJson(route('tarjetas.movimientos.store', $tarjeta), [
            'tipo' => 'compra', 'descripcion' => 'Sala', 'monto' => '60', 'fecha' => '2026-10-17', 'cuotas' => 2, 'dia_cuota' => 5,
        ])->assertOk();
        $sala = $tarjeta->movimientos()->where('descripcion', 'Sala')->first();
        $this->assertSame('2026-11-05', $this->servicio()->fechaCuota($sala, 1)->toDateString());

        // Un corte el 15 recibe la cuota de ese mes (5 < 15): 05/11 → corte 15/11 y 05/12 → corte 15/12
        $this->hoy('2026-12-16');
        $this->servicio()->generarEstados($tarjeta);
        $this->assertSame(['2026-11-15' => [1], '2026-12-15' => [2]], $tarjeta->estadosCuenta()->with('items')->orderBy('fecha_corte')->get()
            ->mapWithKeys(fn ($e) => [$e->fecha_corte->toDateString() => $e->items->where('movimiento_tarjeta_id', $sala->id)->pluck('cuota_numero')->values()->all()])->all());

        // De contado, el día se descarta
        $this->compra($tarjeta, '10')->assertOk();
        $this->assertNull($tarjeta->movimientos()->latest('id')->value('dia_cuota'));
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

    public function test_lo_nuevo_va_en_el_periodo_abierto(): void
    {
        $tarjeta = $this->tarjeta();
        $this->compra($tarjeta, '50')->assertOk();
        $compra = $tarjeta->movimientos()->where('descripcion', 'Compra')->first();

        $this->actingAs($this->user)->putJson(route('tarjetas.movimientos.update', [$tarjeta, $compra]), ['tipo' => 'compra', 'descripcion' => 'Compra', 'monto' => '60', 'fecha' => '2026-10-17'])->assertOk();
        $this->actingAs($this->user)->postJson(route('tarjetas.movimientos.store', $tarjeta), ['tipo' => 'compra', 'descripcion' => 'Vieja', 'monto' => '5', 'fecha' => '2026-10-10'])
            ->assertUnprocessable()->assertJsonValidationErrors('fecha');

        $this->hoy('2026-11-16');
        $this->servicio()->generarEstados($tarjeta);
        $this->actingAs($this->user)->deleteJson(route('tarjetas.destroy', $tarjeta))->assertUnprocessable();

        // Pantallas con estados de cuenta y su detalle
        $this->actingAs($this->user)->get(route('tarjetas.show', $tarjeta))->assertOk()->assertSee('15/11/2026');
        $this->actingAs($this->user)->get(route('estados-cuenta.show', $tarjeta->estadosCuenta()->first()))->assertOk()->assertSee('Consumos sin facturar');
        $this->actingAs($this->user)->get(route('compromisos.index'))->assertOk()->assertSee('Tarjeta BAC Walmart');
    }

    /** Corte del 15/11 con $100 de consumos al registrar + una compra de $50 = $150. */
    public function test_intereses_bonificables_se_apartan_del_disponible_hasta_el_corte_siguiente_aunque_se_pague(): void
    {
        [$tarjeta, $estado] = $this->corteConCompra();
        $cuenta = $this->user->cuentas()->create(['nombre' => 'BAC Carlos', 'tipo_cuenta_id' => TipoCuenta::create(['nombre' => 'Ahorro', 'activo' => true])->id, 'saldo_inicial' => 500, 'fecha_inicial' => '2026-10-01']);
        $this->assertSame(650.0, $this->servicio()->disponible($tarjeta)); // $200 del estado inicial + $150 del corte

        $this->actingAs($this->user)->patchJson(route('estados-cuenta.intereses', $estado), ['intereses_bonificables' => '-1'])->assertUnprocessable();
        $this->actingAs($this->user)->patchJson(route('estados-cuenta.intereses', $estado), ['intereses_bonificables' => '14.54'])->assertOk();
        $this->assertSame([14.54, 635.46], [$this->servicio()->interesesRetenidos($tarjeta), $this->servicio()->disponible($tarjeta)]);

        // El banco no los libera al pagar: los abona (o los cobra) en el corte siguiente
        $pagar = fn (string $monto) => $this->actingAs($this->user)->postJson(route('compromisos.pagar.store', $estado->compromiso), [
            'origen' => 'cuenta:'.$cuenta->id, 'monto' => $monto, 'fecha' => '2026-11-16', 'descripcion' => 'Pago BAC Walmart',
        ])->assertOk();
        $pagar('100');
        $this->assertSame(735.46, $this->servicio()->disponible($tarjeta));
        $pagar('50');
        $this->assertSame([14.54, 785.46], [$this->servicio()->interesesRetenidos($tarjeta), $this->servicio()->disponible($tarjeta)]);
    }

    public function test_intereses_de_un_corte_anterior_ya_no_se_retienen(): void
    {
        [$tarjeta, $estado] = $this->corteConCompra();
        $estado->update(['intereses_bonificables' => 14.54]);
        $this->compra($tarjeta, '20')->assertOk();
        $this->hoy('2026-12-16');
        $this->servicio()->generarEstados($tarjeta);

        // En el corte nuevo el banco ya los abonó o los cobró (se anotan ahí como un cargo): no se retienen dos veces
        $this->assertSame([0.0, 630.0], [$this->servicio()->interesesRetenidos($tarjeta), $this->servicio()->disponible($tarjeta)]);
        Sanctum::actingAs($this->user);
        $this->getJson(route('api.tarjetas.show', $tarjeta))->assertOk()
            ->assertJsonPath('data.tarjeta.intereses_retenidos', '0.00')
            ->assertJsonPath('data.estados_cuenta.1.intereses_bonificables', '14.54');
    }

    public function test_por_api_se_anotan_los_intereses_bonificables(): void
    {
        [$tarjeta, $estado] = $this->corteConCompra();
        Sanctum::actingAs($this->user);

        $this->patchJson(route('api.tarjetas.estados.intereses', [$tarjeta, $estado]), ['intereses_bonificables' => '14.54'])->assertOk()
            ->assertJsonPath('data.disponible', '635.46')->assertJsonPath('data.intereses_retenidos', '14.54');
        $this->patchJson(route('api.tarjetas.estados.intereses', [$tarjeta, $estado]), ['intereses_bonificables' => 'x'])->assertUnprocessable();

        // El corte de otra tarjeta no se edita desde esta
        $this->actingAs($this->user)->postJson(route('tarjetas.store'), [
            'nombre' => 'Otra', 'banco_id' => Banco::first()->id, 'marca_red_id' => MarcaRed::first()->id, 'color' => '#2563EB',
            'limite_credito' => '500', 'dia_corte' => 10, 'dia_pago' => 1, 'disponible' => '500',
        ])->assertOk();
        $otra = $this->user->tarjetasCredito()->where('nombre', 'Otra')->first();
        $this->patchJson(route('api.tarjetas.estados.intereses', [$otra, $estado]), ['intereses_bonificables' => '1'])->assertNotFound();
    }

    public function test_tarjeta_que_solo_resta_el_capital_no_baja_el_disponible_con_cargos_hasta_pagarlos(): void
    {
        [$tarjeta, $estado] = $this->corteConCompra();
        $tarjeta->update(['cargos_restan_disponible' => false]);
        $cuenta = $this->user->cuentas()->create(['nombre' => 'BAC Carlos', 'tipo_cuenta_id' => TipoCuenta::create(['nombre' => 'Ahorro', 'activo' => true])->id, 'saldo_inicial' => 500, 'fecha_inicial' => '2026-10-01']);
        $this->assertSame(650.0, $this->servicio()->disponible($tarjeta));

        // Un cargo sin facturar (comisión) y otro dentro del corte (interés): la deuda sube, el disponible no
        $this->servicio()->registrarMovimiento($tarjeta, ['tipo' => 'cargo', 'descripcion' => 'Portafolio', 'monto' => 10, 'fecha' => '2026-11-16']);
        $this->servicio()->registrarMovimiento($tarjeta, ['tipo' => 'cargo', 'descripcion' => 'Interés', 'monto' => 5, 'fecha' => '2026-11-15'], $estado->fresh());
        $this->assertSame([365.0, 15.0, 650.0], [$this->servicio()->deuda($tarjeta), $this->servicio()->cargosSinRestar($tarjeta), $this->servicio()->disponible($tarjeta)]);

        // El pago cubre primero los cargos del corte: pagar el interés no sube el disponible; lo que pasa de ahí, sí
        $pagar = fn (string $monto) => $this->actingAs($this->user)->postJson(route('compromisos.pagar.store', $estado->compromiso), [
            'origen' => 'cuenta:'.$cuenta->id, 'monto' => $monto, 'fecha' => '2026-11-16', 'descripcion' => 'Pago',
        ])->assertOk();
        $pagar('3');
        $this->assertSame([12.0, 650.0], [$this->servicio()->cargosSinRestar($tarjeta), $this->servicio()->disponible($tarjeta)]);
        $pagar('10');
        $this->assertSame([10.0, 658.0], [$this->servicio()->cargosSinRestar($tarjeta), $this->servicio()->disponible($tarjeta)]);

        // Editar la tarjeta desde una app que no manda el campo no lo cambia
        $tarjeta->refresh();
        Sanctum::actingAs($this->user);
        $this->putJson(route('api.tarjetas.update', $tarjeta), [
            'nombre' => $tarjeta->nombre, 'color' => $tarjeta->color, 'limite_credito' => '1000', 'dia_corte' => 15, 'dia_pago' => 5, 'activo' => true, 'permite_avances' => true,
        ])->assertOk()->assertJsonPath('data.cargos_restan_disponible', false)->assertJsonPath('data.cargos_sin_restar', '10.00');
    }

    private function corteConCompra(): array
    {
        $tarjeta = $this->tarjeta();
        $this->compra($tarjeta, '50')->assertOk();
        $this->hoy('2026-11-16');
        $this->servicio()->generarEstados($tarjeta);
        $estado = $tarjeta->estadosCuenta()->first();
        $this->assertSame('150.00', $estado->total);

        return [$tarjeta, $estado, $tarjeta->movimientos()->where('descripcion', 'Compra')->first()];
    }

    private function editarMovimiento(TarjetaCredito $tarjeta, $movimiento, array $datos)
    {
        return $this->actingAs($this->user)->putJson(route('tarjetas.movimientos.update', [$tarjeta, $movimiento]), $datos + [
            'tipo' => 'compra', 'descripcion' => 'Compra', 'monto' => '50', 'fecha' => '2026-10-17',
        ]);
    }

    public function test_corregir_un_movimiento_ya_facturado_ajusta_el_estado_y_su_pago(): void
    {
        [$tarjeta, $estado, $compra] = $this->corteConCompra();
        $this->assertTrue($this->servicio()->esEditable($compra));
        $deuda = $this->servicio()->deuda($tarjeta);

        // Se anotó $50 pero eran $40: el corte, su pago pendiente y la deuda bajan $10
        $this->editarMovimiento($tarjeta, $compra, ['monto' => '40'])->assertOk();
        $this->assertSame('140.00', $estado->fresh()->total);
        $this->assertSame(['140.00', '140.00'], [$estado->compromiso->monto, $estado->compromiso->saldo_pendiente]);
        $this->assertSame('40.00', $compra->items()->value('monto'));
        $this->assertSame($deuda - 10, $this->servicio()->deuda($tarjeta));

        // Con un abono, lo pagado se respeta
        app(CompromisoService::class)->abonar($estado->compromiso, 120);
        $this->editarMovimiento($tarjeta, $compra, ['monto' => '45'])->assertOk();
        $this->assertSame(['145.00', '25.00'], [$estado->compromiso->fresh()->monto, $estado->compromiso->fresh()->saldo_pendiente]);
        $this->editarMovimiento($tarjeta, $compra, ['monto' => '20'])->assertOk();
        $this->assertSame(['120.00', '0.00', 'pagado'], [$estado->compromiso->fresh()->monto, $estado->compromiso->fresh()->saldo_pendiente, $estado->compromiso->fresh()->estado]);
        $this->editarMovimiento($tarjeta, $compra, ['monto' => '10'])->assertUnprocessable()->assertJsonValidationErrors('monto');

        // La fecha no sale del período de su corte ni se pasa a cuotas
        $this->editarMovimiento($tarjeta, $compra, ['monto' => '20', 'fecha' => '2026-11-16'])->assertUnprocessable()->assertJsonValidationErrors('fecha');
        $this->editarMovimiento($tarjeta, $compra, ['monto' => '20', 'cuotas' => 3])->assertUnprocessable()->assertJsonValidationErrors('cuotas');

        // El formulario avisa que ya se facturó
        $this->actingAs($this->user)->get(route('tarjetas.movimientos.edit', [$tarjeta, $compra]))->assertOk()->assertSee('Ya se facturó en el corte del 15/11/2026');
        $this->actingAs($this->user)->get(route('estados-cuenta.show', $estado))->assertOk()->assertSee(route('tarjetas.movimientos.edit', [$tarjeta, $compra]));
    }

    public function test_eliminar_un_movimiento_ya_facturado_lo_saca_del_estado(): void
    {
        [$tarjeta, $estado, $compra] = $this->corteConCompra();

        $this->actingAs($this->user)->deleteJson(route('tarjetas.movimientos.destroy', [$tarjeta, $compra]))->assertOk();
        $this->assertSame('100.00', $estado->fresh()->total);
        $this->assertSame('100.00', $estado->compromiso->saldo_pendiente);
        $this->assertSame(1, $estado->items()->count());
    }

    public function test_una_compra_en_cuotas_ya_cobradas_no_se_corrige(): void
    {
        $tarjeta = $this->tarjeta();
        $this->compra($tarjeta, '90', 3, 'Tele')->assertOk();
        $this->hoy('2026-11-16');
        $this->servicio()->generarEstados($tarjeta);
        $tele = $tarjeta->movimientos()->where('descripcion', 'Tele')->first();

        $this->assertFalse($this->servicio()->esEditable($tele));
        $this->editarMovimiento($tarjeta, $tele, ['monto' => '60', 'descripcion' => 'Tele', 'cuotas' => 3])->assertForbidden();
    }

    public function test_agregar_a_un_corte_pasado_lo_que_falto_anotar(): void
    {
        [$tarjeta, $estado] = $this->corteConCompra();
        $url = route('tarjetas.movimientos.store', [$tarjeta, 'estado' => $estado->id]);

        $this->actingAs($this->user)->postJson($url, ['tipo' => 'compra', 'descripcion' => 'Olvidada', 'monto' => '25', 'fecha' => '2026-11-10'])->assertOk();
        $this->assertSame('175.00', $estado->fresh()->total);
        $this->assertSame(['175.00', '175.00'], [$estado->compromiso->monto, $estado->compromiso->saldo_pendiente]);
        $olvidada = $tarjeta->movimientos()->where('descripcion', 'Olvidada')->first();
        $this->assertSame($estado->id, $olvidada->items()->value('estado_cuenta_tarjeta_id'));
        // No se vuelve a cobrar en el siguiente corte
        $this->assertFalse($this->servicio()->porFacturar($tarjeta)->contains(fn ($l) => $l['movimiento']->is($olvidada)));

        // Un crédito resta; fuera del período o en cuotas no
        $this->actingAs($this->user)->postJson($url, ['tipo' => 'credito', 'descripcion' => 'Devolución', 'monto' => '5', 'fecha' => '2026-11-12'])->assertOk();
        $this->assertSame('170.00', $estado->fresh()->total);
        $this->actingAs($this->user)->postJson($url, ['tipo' => 'compra', 'descripcion' => 'X', 'monto' => '5', 'fecha' => '2026-11-16'])->assertJsonValidationErrors('fecha');
        $this->actingAs($this->user)->postJson($url, ['tipo' => 'compra', 'descripcion' => 'X', 'monto' => '30', 'fecha' => '2026-11-10', 'cuotas' => 3])->assertJsonValidationErrors('cuotas');

        // El detalle del corte ofrece agregar y el formulario lo indica
        $this->actingAs($this->user)->get(route('estados-cuenta.show', $estado))->assertOk()->assertSee('Agregar compra')
            ->assertSee(route('tarjetas.movimientos.create', [$tarjeta, 'tipo' => 'compra', 'estado' => $estado->id]));
        $this->actingAs($this->user)->get(route('tarjetas.movimientos.create', [$tarjeta, 'tipo' => 'compra', 'estado' => $estado->id]))->assertOk()->assertSee('Se agrega al corte del 15/11/2026');

        // Por API también
        Sanctum::actingAs($this->user);
        $this->postJson("/api/tarjetas/{$tarjeta->id}/movimientos?estado={$estado->id}", ['tipo' => 'cargo', 'descripcion' => 'Seguro', 'monto' => '2.16', 'fecha' => '2026-11-15'])->assertCreated();
        $this->assertSame('172.16', $estado->fresh()->total);
    }
}
