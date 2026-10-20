<?php

namespace Tests\Feature;

use App\Models\TipoCuenta;
use App\Models\User;
use App\Services\CompromisoService;
use App\Services\DashboardService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-19 12:00:00');
        $this->user = User::factory()->create(['email_verified_at' => now(), 'fecha_corte_dia' => 22, 'zona_horaria' => 'America/El_Salvador', 'onboarding_completed' => true]);
        foreach (['efectivo.view', 'cuentas.view', 'tarjetas.view', 'compromisos.view', 'ingresos.view'] as $p) {
            $this->user->givePermissionTo(Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']));
        }
        $saldo = $this->user->categoriasPersonales()->create(['nombre' => 'Saldo inicial', 'tipo' => 'ingreso']);
        $super = $this->user->categoriasPersonales()->create(['nombre' => 'Alimentación', 'tipo' => 'egreso', 'color' => '#16A34A']);
        $entre = $this->user->categoriasPersonales()->create(['nombre' => 'Movimientos entre cuentas', 'tipo' => 'egreso']);
        $sueldo = $this->user->categoriasPersonales()->create(['nombre' => 'Sueldo y trabajos', 'tipo' => 'ingreso']);
        $this->user->movimientosEfectivo()->create(['categoria_personal_id' => $saldo->id, 'descripcion' => 'Saldo inicial', 'monto' => 100, 'fecha' => '2026-09-23', 'tipo' => 'ingreso']);
        $this->user->movimientosEfectivo()->create(['categoria_personal_id' => $sueldo->id, 'descripcion' => 'Sueldo', 'monto' => 500, 'fecha' => '2026-09-30', 'tipo' => 'ingreso']);
        $this->user->movimientosEfectivo()->create(['categoria_personal_id' => $super->id, 'descripcion' => 'Super', 'monto' => 80, 'fecha' => '2026-10-01', 'tipo' => 'egreso']);
        $this->user->movimientosEfectivo()->create(['categoria_personal_id' => $entre->id, 'descripcion' => 'Metí al banco (sin pareja)', 'monto' => 20, 'fecha' => '2026-10-02', 'tipo' => 'egreso']);
        $tipo = TipoCuenta::create(['nombre' => 'Cuenta de Ahorro', 'activo' => true]);
        $this->user->cuentas()->create(['nombre' => 'Agrícola', 'tipo_cuenta_id' => $tipo->id, 'saldo_inicial' => 300, 'fecha_inicial' => '2026-09-23', 'color' => '#0369A1']);
        $tarjeta = $this->user->tarjetasCredito()->create(['nombre' => 'Walmart', 'limite_credito' => 900, 'dia_corte' => 24, 'dia_pago' => 18, 'fecha_registro' => '2026-09-25', 'color' => '#CFE2F3']);
        $tarjeta->movimientos()->create(['tipo' => 'compra', 'descripcion' => 'Walmart', 'monto' => 950, 'fecha' => '2026-10-04', 'categoria_personal_id' => $super->id]);
        $tienda = $this->user->tarjetasCredito()->create(['nombre' => 'Credisiman', 'limite_credito' => 400, 'dia_corte' => 28, 'dia_pago' => 25, 'fecha_registro' => '2026-09-29', 'color' => '#1155CC', 'permite_avances' => false]);
        app(CompromisoService::class)->crearUnico($this->user, ['descripcion' => 'Tarjeta Walmart · corte', 'monto' => 200, 'fecha_esperada' => '2026-10-18', 'medio_pago' => 'por_definir', 'tarjeta_credito_id' => $tarjeta->id]);
        app(CompromisoService::class)->crearUnico($this->user, ['descripcion' => 'Internet', 'monto' => 46, 'fecha_esperada' => '2026-10-20', 'medio_pago' => 'efectivo']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_resumen_de_inicio(): void
    {
        $d = app(DashboardService::class)->resumen($this->user);

        $this->assertSame(['inicio' => '2026-09-23', 'fin' => '2026-10-22', 'dias_restantes' => 3], $d['ciclo']);
        $this->assertSame(500.0, $d['efectivo']);
        $this->assertSame(300.0, $d['total_cuentas']);
        $this->assertSame(246.0, $d['por_pagar_ciclo']);
        $this->assertSame(554.0, $d['liquidez']); // 500 + 300 − 246
        // Disponible: Walmart −50 (sobregirada) + Credisiman 400; "de las que sí puedo sacar" excluye la de tienda y no resta sobregiros
        $this->assertSame(350.0, $d['tarjetas_totales']['disponible']);
        $this->assertSame(0.0, $d['tarjetas_totales']['disponible_avances']);
        // Gastos: super en efectivo + compra en tarjeta; "Movimientos entre cuentas" no es gasto
        $this->assertSame([['nombre' => 'Alimentación', 'color' => '#16A34A', 'monto' => 1030.0]], $d['gastos_por_categoria']);
        $this->assertSame(500.0, end($d['historial'])['ingresos']); // el saldo inicial no es ingreso
        // Alertas: vencido primero, luego lo de mañana y la tarjeta sobre su límite
        $this->assertSame('critico', $d['alertas'][0]['nivel']);
        $this->assertStringContainsString('venció hace 1 día', $d['alertas'][0]['texto']);
        $this->assertTrue(collect($d['alertas'])->contains(fn ($a) => str_contains($a['texto'], 'sobre su límite')));
        $this->assertTrue(collect($d['alertas'])->contains(fn ($a) => str_contains($a['texto'], 'vence mañana')));
    }

    public function test_pagina_y_api(): void
    {
        $this->actingAs($this->user)->get(route('dashboard'))->assertOk()
            ->assertSee('Al pagar mis compromisos tendré')->assertSee('554.00')->assertSee('Gastos del ciclo por categoría')->assertDontSee('Todas las sesiones');

        Sanctum::actingAs($this->user);
        $this->getJson('/api/dashboard')->assertOk()->assertJsonPath('data.liquidez', 554)->assertJsonPath('data.tarjetas.0.nombre', 'Walmart');
    }
}
