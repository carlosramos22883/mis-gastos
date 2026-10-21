<?php

namespace Tests\Feature;

use App\Models\Catalogos\TipoContacto;
use App\Models\Contacto;
use App\Models\Deuda;
use App\Models\TipoCuenta;
use App\Models\User;
use App\Services\CicloEfectivoService;
use App\Services\CuentaService;
use App\Services\DeudorService;
use App\Services\TarjetaService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ContactoTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Contacto $aquino;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-17 15:00:00');
        $this->user = User::factory()->create(['email_verified_at' => now(), 'fecha_corte_dia' => 22, 'zona_horaria' => 'America/El_Salvador', 'onboarding_completed' => true]);
        foreach (['deudores', 'contactos', 'efectivo', 'cuentas', 'tarjetas', 'compromisos'] as $modulo) {
            foreach (['view', 'create', 'edit', 'delete'] as $accion) {
                $this->user->givePermissionTo(Permission::firstOrCreate(['name' => "{$modulo}.{$accion}", 'guard_name' => 'web']));
            }
        }
        $saldo = $this->user->categoriasPersonales()->create(['nombre' => 'Saldo inicial', 'tipo' => 'ingreso']);
        $this->user->movimientosEfectivo()->create(['categoria_personal_id' => $saldo->id, 'descripcion' => 'Saldo inicial', 'monto' => 100, 'fecha' => '2026-09-23', 'tipo' => 'ingreso']);
        $this->aquino = $this->user->contactos()->create(['nombre' => 'Aquino', 'tipo_contacto_id' => TipoContacto::where('nombre', 'Persona')->value('id')]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function prestar(array $datos)
    {
        return $this->actingAs($this->user)->postJson(route('contactos.deudas.store', $this->aquino), $datos + [
            'descripcion' => 'Camiseta', 'monto' => '15.00', 'fecha' => '17/10/2026', 'origen' => 'efectivo',
        ]);
    }

    private function pagar(array $datos)
    {
        return $this->actingAs($this->user)->postJson(route('contactos.pagos.store', $this->aquino), $datos + [
            'monto' => '10.00', 'fecha' => '2026-10-17', 'destino' => 'efectivo',
        ]);
    }

    private function efectivo(): float
    {
        return app(CicloEfectivoService::class)->balance($this->user);
    }

    public function test_crear_contacto_y_ver_pantallas(): void
    {
        $this->actingAs($this->user)->postJson(route('contactos.store'), ['nombre' => 'Karen', 'tipo_contacto_id' => TipoContacto::where('nombre', 'Persona')->value('id')])->assertOk();
        $this->actingAs($this->user)->postJson(route('contactos.store'), ['nombre' => 'Karen', 'tipo_contacto_id' => TipoContacto::where('nombre', 'Persona')->value('id')])
            ->assertUnprocessable()->assertJsonValidationErrors('nombre');

        $this->prestar([]);
        $this->actingAs($this->user)->get(route('contactos.index', ['filtro' => 'deben']))->assertOk()->assertSee('Aquino')->assertSee('15.00');
        $this->actingAs($this->user)->get(route('contactos.show', $this->aquino))->assertOk()->assertSee('Camiseta');
        $this->actingAs($this->user)->get(route('contactos.deudas.create', $this->aquino))->assertOk()->assertSee('Solo anotar');
        $this->actingAs($this->user)->get(route('contactos.pagos.create', $this->aquino))->assertOk()->assertSee('Te debe');
    }

    public function test_prestar_desde_efectivo_crea_el_egreso(): void
    {
        $this->prestar([])->assertOk();

        $deuda = Deuda::firstOrFail();
        $this->assertSame('efectivo', $deuda->origen);
        $this->assertSame(85.0, $this->efectivo());
        $this->assertSame('Préstamos a terceros', $deuda->movimientoEfectivo->categoria->nombre);
        $this->assertSame(15.0, $deuda->pendiente());
    }

    public function test_prestar_desde_cuenta_o_tarjeta(): void
    {
        $tipo = TipoCuenta::create(['nombre' => 'Cuenta de Ahorro', 'activo' => true]);
        $cuenta = $this->user->cuentas()->create(['nombre' => 'Agrícola', 'tipo_cuenta_id' => $tipo->id, 'saldo_inicial' => 50, 'fecha_inicial' => '2026-10-01', 'color' => '#0284C7']);
        $tarjeta = $this->user->tarjetasCredito()->create(['nombre' => 'Promerica', 'limite_credito' => 500, 'dia_corte' => 9, 'dia_pago' => 6, 'fecha_registro' => '2026-10-10', 'color' => '#00B050']);

        $this->prestar(['origen' => 'cuenta:'.$cuenta->id, 'monto' => '20.00'])->assertOk();
        $this->prestar(['origen' => 'tarjeta:'.$tarjeta->id, 'monto' => '99.83', 'descripcion' => 'Macbook'])->assertOk();

        $this->assertSame(30.0, app(CuentaService::class)->saldo($cuenta));
        $this->assertSame(400.17, app(TarjetaService::class)->disponible($tarjeta));
        $this->assertSame(119.83, app(DeudorService::class)->resumir($this->aquino->fresh())->pendiente);
    }

    public function test_solo_anotar_permite_fechas_anteriores_sin_tocar_el_efectivo(): void
    {
        $this->prestar(['origen' => 'efectivo', 'fecha' => '2026-08-01'])->assertUnprocessable()->assertJsonValidationErrors('fecha');
        $this->prestar(['origen' => 'ninguno', 'fecha' => '2026-08-01'])->assertOk();

        $this->assertSame(100.0, $this->efectivo());
    }

    public function test_un_pago_se_reparte_de_la_deuda_mas_antigua_a_la_mas_reciente(): void
    {
        $this->prestar(['origen' => 'ninguno', 'fecha' => '2026-08-01', 'descripcion' => 'Refri', 'monto' => '8.86'])->assertOk();
        $this->prestar(['descripcion' => 'Camiseta', 'monto' => '5.00'])->assertOk();

        $this->pagar(['monto' => '10.00'])->assertOk();

        [$refri, $camiseta] = Deuda::orderBy('fecha')->withSum('pagos', 'monto')->get()->all();
        $this->assertSame('pagada', $refri->estado());
        $this->assertSame('parcial', $camiseta->estado());
        $this->assertSame(3.86, $camiseta->pendiente());
        $this->assertSame(105.0, $this->efectivo()); // 100 − 5 (camiseta) + 10 (pago)
        $this->assertSame(1, $this->user->movimientosEfectivo()->where('descripcion', 'Pago de Aquino')->count()); // un solo ingreso

        $this->pagar(['monto' => '5.00'])->assertUnprocessable()->assertJsonValidationErrors('monto'); // supera lo pendiente
    }

    public function test_pagar_una_deuda_especifica(): void
    {
        $this->prestar(['origen' => 'ninguno', 'fecha' => '2026-08-01', 'descripcion' => 'Refri', 'monto' => '8.86']);
        $this->prestar(['descripcion' => 'Camiseta', 'monto' => '5.00']);
        $camiseta = Deuda::where('descripcion', 'Camiseta')->first();

        $this->pagar(['monto' => '5.00', 'deuda_id' => $camiseta->id])->assertOk();

        $this->assertSame('pagada', $camiseta->fresh()->estado());
        $this->assertSame('activa', Deuda::where('descripcion', 'Refri')->first()->estado());
    }

    public function test_eliminar_pago_y_deuda_revierte_los_movimientos(): void
    {
        $this->prestar([]);
        $this->pagar(['monto' => '15.00']);
        $deuda = Deuda::firstOrFail();

        $this->actingAs($this->user)->deleteJson(route('deudas.destroy', $deuda))->assertUnprocessable(); // tiene pagos
        $this->actingAs($this->user)->deleteJson(route('deuda-pagos.destroy', $deuda->pagos()->first()))->assertOk();
        $this->assertSame(85.0, $this->efectivo());
        $this->actingAs($this->user)->deleteJson(route('deudas.destroy', $deuda))->assertOk();
        $this->assertSame(100.0, $this->efectivo());
        $this->assertSame(0, $this->user->movimientosEfectivo()->where('descripcion', 'like', '%Aquino%')->count());
    }

    public function test_el_movimiento_de_una_deuda_no_se_edita_desde_efectivo(): void
    {
        $this->prestar([]);
        $movimiento = Deuda::firstOrFail()->movimientoEfectivo;

        $this->actingAs($this->user)->deleteJson(route('efectivo.destroy', $movimiento))->assertForbidden();
        $this->assertSame(85.0, $this->efectivo());
    }

    public function test_contacto_con_historial_no_se_elimina_y_otro_hogar_no_lo_ve(): void
    {
        $this->prestar([]);
        $this->actingAs($this->user)->deleteJson(route('contactos.destroy', $this->aquino))->assertUnprocessable();

        $otro = User::factory()->create(['email_verified_at' => now(), 'onboarding_completed' => true]);
        $otro->givePermissionTo(['deudores.view', 'contactos.view']);
        $this->actingAs($otro)->get(route('contactos.show', $this->aquino))->assertNotFound();
        $this->actingAs($otro)->get(route('contactos.index', ['filtro' => 'deben']))->assertOk()->assertDontSee('Aquino');
    }

    public function test_foto_privada_solo_para_el_hogar(): void
    {
        Storage::fake('local');
        $foto = UploadedFile::fake()->image('karen.jpg', 200, 200);
        $this->actingAs($this->user)->post(route('contactos.store'), ['nombre' => 'Karen', 'apellido' => 'López', 'tipo_contacto_id' => TipoContacto::where('nombre', 'Persona')->value('id'), 'foto' => $foto],
            ['Accept' => 'application/json'])->assertOk();
        $karen = Contacto::where('nombre', 'Karen')->firstOrFail();
        Storage::disk('local')->assertExists($karen->foto);
        $this->assertStringNotContainsString('/storage/', $karen->urlFoto()); // no es pública

        $this->actingAs($this->user)->get($karen->urlFoto())->assertOk();
        $otro = User::factory()->create();
        $otro->givePermissionTo('contactos.view');
        $this->actingAs($otro)->get($karen->urlFoto())->assertNotFound();

        // Editar y cambiar la foto (recortada en el navegador: llega como JPG)
        $anterior = $karen->foto;
        $nueva = UploadedFile::fake()->image('logo.jpg', 512, 512);
        $this->actingAs($this->user)->post(route('contactos.update', $karen), ['_method' => 'PUT', 'nombre' => 'Karen', 'apellido' => 'López', 'tipo_contacto_id' => TipoContacto::where('nombre', 'Persona')->value('id'),
            'telefono' => '7777-0000', 'email' => '', 'notas' => '', 'activo' => '1', 'foto' => $nueva], ['Accept' => 'application/json'])->assertOk();
        $karen->refresh();
        $this->assertNotSame($anterior, $karen->foto);
        $this->assertSame('7777-0000', $karen->telefono);
        Storage::disk('local')->assertExists($karen->foto);
        Storage::disk('local')->assertMissing($anterior);

        $this->actingAs($this->user)->post(route('contactos.update', $karen), ['_method' => 'PUT', 'nombre' => 'Karen', 'apellido' => 'López', 'tipo_contacto_id' => TipoContacto::where('nombre', 'Persona')->value('id'), 'quitar_foto' => '1', 'activo' => '1'],
            ['Accept' => 'application/json'])->assertOk();
        $this->assertNull($karen->fresh()->foto);
    }

    public function test_con_quien_crea_el_contacto_y_aparece_en_su_ficha(): void
    {
        $categoria = $this->user->categoriasPersonales()->create(['nombre' => 'Gaby', 'tipo' => 'egreso']);
        $this->actingAs($this->user)->post(route('efectivo.store'), [
            'descripcion' => 'Cuota del colegio', 'monto' => '50.00', 'fecha' => '2026-10-17', 'tipo' => 'egreso',
            'categoria_personal_id' => $categoria->id, 'contacto_id' => 'nuevo:Colegio de Gaby',
        ])->assertSessionHasNoErrors();

        $colegio = Contacto::where('nombre', 'Colegio')->firstOrFail();
        $this->assertSame('de Gaby', $colegio->apellido);
        $this->assertSame($colegio->id, $this->user->movimientosEfectivo()->where('descripcion', 'Cuota del colegio')->value('contacto_id'));

        // Escribir el mismo nombre no lo duplica
        $this->actingAs($this->user)->post(route('efectivo.store'), [
            'descripcion' => 'Uniforme', 'monto' => '20.00', 'fecha' => '2026-10-17', 'tipo' => 'egreso',
            'categoria_personal_id' => $categoria->id, 'contacto_id' => 'nuevo:colegio de gaby',
        ])->assertSessionHasNoErrors();
        $this->assertSame(1, Contacto::where('nombre', 'Colegio')->count());

        $this->actingAs($this->user)->get(route('contactos.show', $colegio))->assertOk()->assertSee('Cuota del colegio')->assertSee('70.00');
        $this->actingAs($this->user)->get(route('efectivo.index'))->assertOk()->assertSee('Colegio de Gaby');

        // Un contacto de otro hogar no se puede usar
        $ajeno = User::factory()->create()->contactos()->create(['nombre' => 'Ajeno', 'tipo_contacto_id' => TipoContacto::where('nombre', 'Persona')->value('id')]);
        $this->actingAs($this->user)->post(route('efectivo.store'), [
            'descripcion' => 'X', 'monto' => '1.00', 'fecha' => '2026-10-17', 'tipo' => 'egreso', 'categoria_personal_id' => $categoria->id, 'contacto_id' => (string) $ajeno->id,
        ])->assertSessionHasErrors('contacto_id');
    }

    public function test_el_contacto_del_compromiso_pasa_a_sus_pagos(): void
    {
        $vigilancia = $this->user->contactos()->create(['nombre' => 'Vigilancia', 'tipo_contacto_id' => TipoContacto::where('nombre', 'Empresa')->value('id')]);
        $this->actingAs($this->user)->postJson(route('compromisos.store'), [
            'descripcion' => 'Vigilancia', 'monto' => '20.00', 'medio_pago' => 'efectivo', 'recurrencia_tipo' => 'recurrente', 'frecuencia' => 'mensual',
            'dia_pago' => 21, 'fecha_inicio' => '2026-10-01', 'finalizacion' => 'indefinido', 'contacto_id' => (string) $vigilancia->id,
        ])->assertSuccessful();

        $compromiso = $this->user->compromisos()->firstOrFail();
        $this->assertSame($vigilancia->id, $compromiso->contacto_id);
        $categoria = $this->user->categoriasPersonales()->create(['nombre' => 'Servicios', 'tipo' => 'egreso']);
        $this->actingAs($this->user)->postJson(route('compromisos.pagar.store', $compromiso), [
            'origen' => 'efectivo', 'monto' => '20.00', 'fecha' => '2026-10-17', 'descripcion' => 'Vigilancia', 'categoria_personal_id' => $categoria->id,
        ])->assertOk();
        $this->assertSame($vigilancia->id, $this->user->movimientosEfectivo()->where('descripcion', 'Vigilancia')->value('contacto_id'));
        $this->actingAs($this->user)->deleteJson(route('contactos.destroy', $vigilancia))->assertUnprocessable(); // tiene historial
    }
}
