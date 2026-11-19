<?php

namespace Tests\Feature;

use App\Models\Catalogos\CategoriaDespensa;
use App\Models\CategoriaPersonal;
use App\Models\CompraPago;
use App\Models\Compromiso;
use App\Models\ListaCompra;
use App\Models\ListaCompraItem;
use App\Models\MovimientoEfectivo;
use App\Models\Precio;
use App\Models\Tienda;
use App\Models\User;
use App\Services\CompraPagoService;
use App\Services\CompromisoService;
use App\Services\ListaCompraService;
use App\Services\PresupuestoService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * «Pagar aquí» en Walmart (30/10, ciclo 23/10 al 22/11, $1,000 en efectivo). Presupuestos: Súper $100 y Mascotas $50.
 * En el carrito: 4 atunes a $1.80 ($7.20) y pan sin precio.
 */
class CompraPagoTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Tienda $walmart;

    private ListaCompra $lista;

    /** @var array<string, CategoriaPersonal> */
    private array $cat = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-30 18:00:00');
        $this->user = User::factory()->create(['email_verified_at' => now(), 'fecha_corte_dia' => 22, 'zona_horaria' => 'America/El_Salvador', 'onboarding_completed' => true]);
        foreach (['despensa', 'efectivo', 'compromisos'] as $modulo) {
            foreach (['view', 'create', 'edit', 'delete'] as $accion) {
                $this->user->givePermissionTo(Permission::firstOrCreate(['name' => "{$modulo}.{$accion}", 'guard_name' => 'web']));
            }
        }
        $this->actingAs($this->user);
        $sueldo = $this->user->categoriasPersonales()->create(['nombre' => 'Sueldo', 'tipo' => 'ingreso']);
        $this->user->movimientosEfectivo()->create(['categoria_personal_id' => $sueldo->id, 'descripcion' => 'Sueldo', 'monto' => 1000, 'fecha' => '2026-10-23', 'tipo' => 'ingreso']);
        $compromisos = app(CompromisoService::class);
        foreach (['Súper' => 100, 'Mascotas' => 50] as $nombre => $monto) {
            $this->cat[$nombre] = $this->user->categoriasPersonales()->create(['nombre' => $nombre, 'tipo' => 'egreso', 'activo' => true, 'color' => '#F97316']);
            $compromisos->crearRecurrente($this->user, ['descripcion' => $nombre, 'monto' => $monto, 'medio_pago' => 'efectivo', 'categoria_personal_id' => $this->cat[$nombre]->id,
                'es_presupuesto' => true, 'frecuencia' => 'mensual', 'dia_pago' => 25, 'fecha_inicio' => '2026-10-23']);
        }
        $compromisos->sincronizar($this->user);

        $atun = $this->user->productos()->create(['nombre' => 'Atún', 'marca' => 'Sardimar', 'categoria_despensa_id' => CategoriaDespensa::where('nombre', 'Enlatados')->value('id'),
            'importancia' => 'basico', 'duracion' => 'duradero', 'cantidad_ideal' => 4, 'avisar_cuando' => 1, 'en_casa' => 0]);
        $this->walmart = $this->user->tiendas()->create(['nombre' => 'Walmart', 'fuente' => 'walmart']);
        $this->lista = ListaCompra::create(['user_id' => $this->user->id, 'nombre' => 'Compras del 30/10/2026', 'tienda_actual_id' => $this->walmart->id]);
        $this->lista->items()->create(['producto_id' => $atun->id, 'descripcion' => 'Atún Sardimar', 'cantidad' => 4, 'comprado' => true, 'precio' => 1.80, 'tienda_id' => $this->walmart->id]);
        $this->lista->items()->create(['descripcion' => 'Pan', 'cantidad' => 1, 'comprado' => true]); // sin tienda ni precio
        $this->lista->items()->create(['descripcion' => 'Sardina', 'cantidad' => 2]); // no se echó al carrito
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function pagar(array $datos = [])
    {
        return $this->postJson(route('compras.pagar.store', $this->lista), $datos + ['origen' => 'efectivo', 'fecha' => '30/10/2026',
            'categoria' => (string) $this->cat['Súper']->id, 'monto_lista' => '9.50', 'extras_modo' => 'ninguno']);
    }

    private function queda(string $categoria): float
    {
        return app(PresupuestoService::class)->resumen(Compromiso::where('es_presupuesto', true)->where('categoria_personal_id', $this->cat[$categoria]->id)->firstOrFail())['queda'];
    }

    public function test_la_ventana_sugiere_el_total_y_la_categoria(): void
    {
        $this->get(route('compras.pagar', $this->lista))->assertOk()
            ->assertSee('Pagar en Walmart')->assertSee('Atún Sardimar')->assertSee('$ 7.20')->assertSee('1 cosa no tiene precio');
        $this->assertSame($this->cat['Súper']->id, app(CompraPagoService::class)->categoriaSugerida($this->user)); // se llama como el súper
        $this->get(route('compras.show', $this->lista))->assertOk()->assertSee('Pagar aquí');
    }

    public function test_pagar_lo_de_la_lista_y_extras_detallados_con_una_categoria_nueva(): void
    {
        $datos = ['extras_modo' => 'detalle', 'extras' => [
            ['descripcion' => 'Jalea', 'precio' => '2.25', 'categoria' => (string) $this->cat['Súper']->id],
            ['descripcion' => 'Camisa', 'precio' => '12.00', 'categoria' => 'nuevo:ropa'],
            ['descripcion' => '', 'precio' => '', 'categoria' => ''], // fila vacía: se ignora
        ]];

        // «Ropa» no tiene presupuesto en un ciclo que sí tiene: avisa (con la clave de esa categoría) y no guarda nada
        $this->pagar($datos)->assertUnprocessable()->assertJsonPath('presupuesto.clave', 'nueva:ropa')->assertJsonPath('presupuesto.sin_presupuesto', true);
        $this->assertSame(0, CompraPago::count());
        $this->assertFalse(CategoriaPersonal::where('nombre', 'Ropa')->exists());

        $this->pagar($datos + ['presupuestos' => ['nueva:ropa' => ['modo' => 'pasarme']]])->assertOk()
            ->assertJsonPath('message', 'Pagaste $ 23.75 en Walmart. Se registraron 2 gastos, uno por categoría.');

        $ropa = CategoriaPersonal::where('nombre', 'Ropa')->firstOrFail();
        $this->assertSame('egreso', $ropa->tipo);
        $gastos = MovimientoEfectivo::where('tipo', 'egreso')->orderBy('id')->get();
        $this->assertSame([['Compras en Walmart (y Jalea)', '11.75', $this->cat['Súper']->id], ['Walmart: Camisa', '12.00', $ropa->id]],
            $gastos->map(fn ($m) => [$m->descripcion, $m->monto, $m->categoria_personal_id])->all());
        $this->assertSame(88.25, $this->queda('Súper'));

        $pago = CompraPago::firstOrFail();
        $this->assertSame('23.75', $pago->total);
        $this->assertSame(['Atún Sardimar', 'Camisa', 'Jalea', 'Pan'], $pago->items()->orderBy('descripcion')->pluck('descripcion')->all());
        $this->assertSame($this->walmart->id, ListaCompraItem::where('descripcion', 'Pan')->value('tienda_id')); // quedó con la tienda donde se pagó
        $this->assertSame(2, ListaCompraItem::where('extra', true)->where('comprado', true)->count());
        $this->assertNull(ListaCompraItem::where('descripcion', 'Sardina')->value('compra_pago_id'));
        $this->assertSame(0, Precio::count()); // lo anotado (pan, extras) no es del catálogo: no pasa al historial de precios

        $this->getJson(route('compras.show', $this->lista))->assertOk();
        $this->get(route('compras.show', $this->lista))->assertSee('$ 23.75 en Walmart')->assertSee('Súper $ 11.75 · Ropa $ 12.00')->assertSee('Pagado')->assertSee('Extra');
        $resumen = app(ListaCompraService::class)->resumen($this->lista->fresh());
        $this->assertSame([0, 23.75], [$resumen['por_pagar'], $resumen['pagado']]);
    }

    public function test_lo_pagado_se_gestiona_desde_la_lista_y_se_puede_deshacer(): void
    {
        $this->pagar(['extras_modo' => 'total', 'extras_total' => '5.00', 'extras_categoria' => (string) $this->cat['Mascotas']->id])->assertOk();
        $gasto = MovimientoEfectivo::where('tipo', 'egreso')->firstOrFail();
        $atun = ListaCompraItem::where('descripcion', 'Atún Sardimar')->firstOrFail();

        $this->deleteJson(route('efectivo.destroy', $gasto))->assertForbidden(); // se gestiona desde la lista de compras
        $this->patchJson(route('compras.items.update', [$this->lista, $atun]), ['comprado' => false])->assertUnprocessable()->assertJsonValidationErrors('item');
        $this->deleteJson(route('compras.items.destroy', [$this->lista, $atun]))->assertUnprocessable();
        $this->assertSame('Otras cosas', ListaCompraItem::where('extra', true)->value('descripcion'));
        $this->assertSame(45.0, $this->queda('Mascotas'));

        $pago = CompraPago::firstOrFail();
        $this->deleteJson(route('compras.pagos.destroy', [$this->lista, $pago]))->assertOk();
        $this->assertSame(0, MovimientoEfectivo::where('tipo', 'egreso')->count());
        $this->assertSame(0, CompraPago::count());
        $this->assertFalse(ListaCompraItem::where('extra', true)->exists());
        $this->assertNull($atun->fresh()->compra_pago_id);
        $this->assertTrue($atun->fresh()->comprado); // sigue en el carrito, sin pagar
        $this->assertSame([100.0, 50.0], [$this->queda('Súper'), $this->queda('Mascotas')]);
        $this->patchJson(route('compras.items.update', [$this->lista, $atun]), ['precio' => '1.75'])->assertOk();
    }

    public function test_si_se_pasa_del_presupuesto_se_cubre_con_otra_categoria(): void
    {
        $this->pagar(['monto_lista' => '120.00'])->assertUnprocessable()
            ->assertJsonPath('presupuesto.clave', (string) $this->cat['Súper']->id)->assertJsonPath('presupuesto.excedente', '20.00');

        $mascotas = Compromiso::where('es_presupuesto', true)->where('categoria_personal_id', $this->cat['Mascotas']->id)->value('id');
        $this->pagar(['monto_lista' => '120.00', 'presupuestos' => [(string) $this->cat['Súper']->id => ['modo' => 'cubrir', 'traspasos' => [['presupuesto_id' => $mascotas, 'monto' => '20.00']]]]])->assertOk();
        $this->assertSame([0.0, 30.0], [$this->queda('Súper'), $this->queda('Mascotas')]);
    }

    public function test_reglas_del_pago(): void
    {
        // Lo de la lista necesita su total y su categoría; con nada en el carrito ni extras no hay nada que pagar
        $this->pagar(['monto_lista' => '0'])->assertUnprocessable()->assertJsonValidationErrors('monto_lista');
        $this->pagar(['categoria' => ''])->assertUnprocessable()->assertJsonValidationErrors('categoria');
        $this->pagar(['extras_modo' => 'detalle', 'extras' => [['descripcion' => 'Jalea', 'precio' => '2', 'categoria' => '']]])->assertUnprocessable()->assertJsonValidationErrors('extras.0.categoria');
        // el efectivo no alcanza
        $this->pagar(['monto_lista' => '1500.00', 'presupuestos' => [(string) $this->cat['Súper']->id => ['modo' => 'pasarme']]])->assertUnprocessable()->assertJsonValidationErrors('monto');
        $this->pagar(['fecha' => '31/10/2026'])->assertUnprocessable()->assertJsonValidationErrors('fecha'); // futura

        $this->lista->update(['tienda_actual_id' => null]);
        $this->pagar()->assertUnprocessable()->assertJsonValidationErrors('lista');
        $this->get(route('compras.pagar', $this->lista))->assertNotFound();

        // Sin tienda elegida no se paga; con otra tienda, lo del carrito de Walmart no entra (solo lo que no tenía tienda)
        $otra = $this->user->tiendas()->create(['nombre' => 'PriceSmart']);
        $this->lista->update(['tienda_actual_id' => $otra->id]);
        $this->pagar(['monto_lista' => '1.25'])->assertOk();
        $this->assertSame(['Pan'], CompraPago::firstOrFail()->items()->pluck('descripcion')->all());
        $this->pagar()->assertUnprocessable()->assertJsonValidationErrors('monto_lista'); // ya no queda nada
        $this->pagar(['extras_modo' => 'total', 'extras_total' => '3.00', 'extras_categoria' => (string) $this->cat['Súper']->id])->assertOk(); // solo extras
    }

    public function test_permisos_y_hogar(): void
    {
        $sinEfectivo = User::factory()->create(['email_verified_at' => now(), 'onboarding_completed' => true, 'hogar_id' => $this->user->hogar_id]);
        $sinEfectivo->givePermissionTo(['despensa.view', 'despensa.edit']);
        $this->actingAs($sinEfectivo)->get(route('compras.pagar', $this->lista))->assertForbidden(); // no puede registrar gastos
        $this->actingAs($sinEfectivo)->postJson(route('compras.pagar.store', $this->lista), ['origen' => 'efectivo', 'fecha' => '30/10/2026',
            'categoria' => (string) $this->cat['Súper']->id, 'monto_lista' => '9.50', 'extras_modo' => 'ninguno'])->assertForbidden();

        $otro = User::factory()->create(['email_verified_at' => now(), 'onboarding_completed' => true]);
        $otro->givePermissionTo(['despensa.view', 'despensa.edit', 'efectivo.create']);
        $this->actingAs($otro)->get(route('compras.pagar', $this->lista))->assertNotFound();
        $this->actingAs($this->user)->pagar(['categoria' => (string) $otro->categoriasPersonales()->create(['nombre' => 'Ajena', 'tipo' => 'egreso', 'color' => '#000000'])->id])
            ->assertUnprocessable()->assertJsonValidationErrors('categoria');
    }
}
