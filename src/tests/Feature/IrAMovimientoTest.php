<?php

namespace Tests\Feature;

use App\Models\Catalogos\TipoContacto;
use App\Models\CategoriaPersonal;
use App\Models\TipoCuenta;
use App\Models\User;
use App\Services\CicloEfectivoService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/** Desde la ficha de un contacto se llega a cada movimiento (en su ciclo y página) y se puede volver. */
class IrAMovimientoTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-19 12:00:00');
        $this->user = User::factory()->create(['email_verified_at' => now(), 'fecha_corte_dia' => 22, 'zona_horaria' => 'America/El_Salvador', 'onboarding_completed' => true]);
        foreach (['efectivo', 'cuentas', 'tarjetas', 'contactos', 'compromisos', 'prestamos'] as $modulo) {
            $this->user->givePermissionTo(Permission::firstOrCreate(['name' => "{$modulo}.view", 'guard_name' => 'web']));
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_un_movimiento_de_cuenta_lleva_a_su_ciclo_pagina_y_lo_resalta(): void
    {
        TipoCuenta::create(['nombre' => 'Ahorro', 'activo' => true]);
        $cuenta = $this->user->cuentas()->create(['nombre' => 'Agrícola', 'tipo_cuenta_id' => TipoCuenta::first()->id, 'saldo_inicial' => 1000, 'fecha_inicial' => '2026-08-01', 'color' => '#0369A1']);
        $cat = CategoriaPersonal::create(['hogar_id' => $this->user->hogar_id, 'user_id' => $this->user->id, 'nombre' => 'Comida', 'tipo' => 'egreso', 'color' => '#16A34A', 'activo' => true]);
        $buscado = $cuenta->movimientos()->create(['user_id' => $this->user->id, 'categoria_personal_id' => $cat->id, 'descripcion' => 'Buscado', 'monto' => 5, 'fecha' => '2026-09-01', 'tipo' => 'egreso']);
        // 12 movimientos más recientes en el mismo ciclo: el buscado queda en la página 2
        foreach (range(2, 13) as $dia) {
            $cuenta->movimientos()->create(['user_id' => $this->user->id, 'categoria_personal_id' => $cat->id, 'descripcion' => "Otro {$dia}", 'monto' => 1, 'fecha' => sprintf('2026-09-%02d', $dia), 'tipo' => 'egreso']);
        }
        $contacto = $this->user->contactos()->create(['nombre' => 'Karen', 'tipo_contacto_id' => TipoContacto::first()->id, 'activo' => true]);

        $this->actingAs($this->user)->get(route('movimientos.ver', ['cuenta', $buscado->id, 'desde' => 'contacto:'.$contacto->id]))
            ->assertRedirect(route('cuentas.show', [$cuenta, 'cycle' => '2026-09-01', 'page' => 2, 'resaltar' => $buscado->id, 'desde' => 'contacto:'.$contacto->id]));

        $this->actingAs($this->user)->get(route('cuentas.show', [$cuenta, 'cycle' => '2026-09-01', 'desde' => 'contacto:'.$contacto->id]))
            ->assertOk()->assertSee('Volver a Karen');
    }

    public function test_un_movimiento_de_efectivo_lleva_a_su_ciclo(): void
    {
        app(CicloEfectivoService::class)->closeIfNeeded($this->user);
        $cat = CategoriaPersonal::create(['hogar_id' => $this->user->hogar_id, 'user_id' => $this->user->id, 'nombre' => 'Sueldo', 'tipo' => 'ingreso', 'color' => '#16A34A', 'activo' => true]);
        $m = $this->user->movimientosEfectivo()->create(['user_id' => $this->user->id, 'categoria_personal_id' => $cat->id, 'descripcion' => 'Pago', 'monto' => 10, 'fecha' => '2026-10-19', 'tipo' => 'ingreso']);

        $this->actingAs($this->user)->get(route('movimientos.ver', ['efectivo', $m->id]))
            ->assertRedirect(route('efectivo.index', ['cycle' => '2026-10-19', 'page' => 1, 'resaltar' => $m->id]));
    }

    public function test_no_se_llega_a_movimientos_ni_contactos_de_otro_hogar(): void
    {
        $otro = User::factory()->create();
        $ajeno = $otro->contactos()->create(['nombre' => 'Ajeno', 'tipo_contacto_id' => TipoContacto::first()->id, 'activo' => true]);
        TipoCuenta::create(['nombre' => 'Ahorro', 'activo' => true]);
        $cuenta = $otro->cuentas()->create(['nombre' => 'X', 'tipo_cuenta_id' => TipoCuenta::first()->id, 'saldo_inicial' => 10, 'fecha_inicial' => '2026-10-01', 'color' => '#000000']);
        $mov = $cuenta->movimientos()->create(['user_id' => $otro->id, 'descripcion' => 'X', 'monto' => 1, 'fecha' => '2026-10-02', 'tipo' => 'egreso']);

        $this->actingAs($this->user)->get(route('movimientos.ver', ['cuenta', $mov->id]))->assertNotFound();
        // Un "desde" de otro hogar (o inventado) no muestra el botón de regreso
        $this->actingAs($this->user)->get(route('efectivo.index', ['desde' => 'contacto:'.$ajeno->id]))->assertOk()->assertDontSee('Volver a Ajeno');
        $this->actingAs($this->user)->get(route('efectivo.index', ['desde' => 'https://malo.com']))->assertOk()->assertDontSee('← Volver', false);
    }
}
