<?php

namespace Tests\Feature;

use App\Models\Catalogos\CategoriaDespensa;
use App\Models\ListaCompra;
use App\Models\Precio;
use App\Models\Producto;
use App\Models\Tienda;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class TiendaPrecioTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Producto $atun;

    private Tienda $selectos;

    private Tienda $walmart;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-19 03:00:00', 'UTC')); // 18/11 en El Salvador
        $this->user = $this->usuario(['view', 'create', 'edit', 'delete']);
        $this->atun = $this->user->productos()->create(['nombre' => 'Atún', 'marca' => 'Sardimar', 'categoria_despensa_id' => CategoriaDespensa::where('nombre', 'Enlatados')->value('id'),
            'importancia' => 'basico', 'duracion' => 'duradero', 'cantidad_ideal' => 4, 'avisar_cuando' => 1, 'en_casa' => 0]);
        $this->selectos = $this->user->tiendas()->create(['nombre' => 'Súper Selectos', 'fuente' => 'selectos']);
        $this->walmart = $this->user->tiendas()->create(['nombre' => 'Walmart', 'fuente' => 'walmart']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function usuario(array $permisos): User
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'onboarding_completed' => true, 'zona_horaria' => 'America/El_Salvador']);
        foreach ($permisos as $accion) {
            $user->givePermissionTo(Permission::firstOrCreate(['name' => "despensa.{$accion}", 'guard_name' => 'web']));
        }

        return $user;
    }

    private function anotar(Tienda $tienda, string $precio, ?int $unidades = null, ?string $fecha = null)
    {
        return $this->actingAs($this->user)->postJson(route('productos.precios.store', $this->atun),
            array_filter(['tienda_id' => $tienda->id, 'precio' => $precio, 'unidades' => $unidades, 'fecha' => $fecha]));
    }

    // --- Tiendas

    public function test_agregar_las_tiendas_de_siempre_sin_repetir(): void
    {
        $this->actingAs($this->user)->postJson(route('tiendas.comunes'))->assertOk()->assertJsonPath('message', 'Se agregaron 5 tiendas.');
        $this->actingAs($this->user)->postJson(route('tiendas.comunes'))->assertOk()->assertJsonPath('message', 'Ya tienes todas las tiendas comunes.');

        $this->assertSame(7, $this->user->tiendas()->count());
        $this->assertNull($this->user->tiendas()->where('nombre', 'PriceSmart')->value('fuente'));
        $this->assertSame('donjuan', $this->user->tiendas()->where('nombre', 'La Despensa de Don Juan')->value('fuente'));
        $this->actingAs($this->user)->get(route('tiendas.index'))->assertOk()->assertSee('Precios en línea')->assertSee('Precio anotado');
    }

    public function test_crear_editar_y_no_eliminar_una_tienda_con_historial(): void
    {
        $this->actingAs($this->user)->postJson(route('tiendas.store'), ['nombre' => 'pricesmart'])->assertOk();
        $this->actingAs($this->user)->postJson(route('tiendas.store'), ['nombre' => 'PRICESMART '])->assertStatus(422)->assertJsonValidationErrors('nombre');
        $this->actingAs($this->user)->postJson(route('tiendas.store'), ['nombre' => 'X', 'fuente' => 'amazon'])->assertStatus(422)->assertJsonValidationErrors('fuente');

        $this->anotar($this->walmart, '2.15')->assertOk();
        $this->actingAs($this->user)->deleteJson(route('tiendas.destroy', $this->walmart))->assertStatus(422)->assertJsonValidationErrors('tienda');
        $this->actingAs($this->user)->putJson(route('tiendas.update', $this->walmart), ['nombre' => 'Walmart Escalón', 'fuente' => 'walmart', 'activo' => '0'])->assertOk();
        $this->assertFalse($this->walmart->fresh()->activo);

        $libre = $this->user->tiendas()->where('nombre', 'pricesmart')->first();
        $this->actingAs($this->user)->deleteJson(route('tiendas.destroy', $libre))->assertOk();
    }

    // --- Precios

    public function test_anotar_precios_y_el_mas_barato_por_unidad(): void
    {
        $this->anotar($this->selectos, '1,95')->assertOk()->assertJsonPath('message', 'Precio anotado: Súper Selectos, $ 1.95.');
        $this->anotar($this->walmart, '$5.25', 3)->assertOk(); // 3 latas: 1.75 c/u
        $this->anotar($this->selectos, '1.99', null, '2026-11-10')->assertOk(); // más viejo: no es el último

        $html = $this->actingAs($this->user)->get(route('productos.precios', $this->atun))->assertOk()
            ->assertSee('Más barato')->assertSee('$ 5.25 × 3')->assertSee('$ 1.75 c/u')->assertSee('Anotado hoy')->assertSee('Historial (3)')->getContent();
        $this->assertLessThan(strpos($html, 'Súper Selectos'), strpos($html, 'Walmart'));

        $this->anotar($this->selectos, '1.955')->assertStatus(422)->assertJsonValidationErrors('precio');
        $this->anotar($this->selectos, '0')->assertStatus(422)->assertJsonValidationErrors('precio');
    }

    public function test_no_se_anota_en_una_tienda_de_otro_hogar_ni_se_borra_un_precio_de_compra(): void
    {
        $otro = $this->usuario(['view', 'create', 'edit', 'delete']);
        $ajena = $otro->tiendas()->create(['nombre' => 'Ajena']);

        $this->anotar($ajena, '1.00')->assertStatus(422)->assertJsonValidationErrors('tienda_id');
        $this->actingAs($otro)->get(route('productos.precios', $this->atun))->assertNotFound();

        $precio = Precio::create(['user_id' => $this->user->id, 'producto_id' => $this->atun->id, 'tienda_id' => $this->walmart->id, 'precio' => 2, 'origen' => 'compra', 'fecha' => '2026-11-18']);
        $this->actingAs($this->user)->deleteJson(route('productos.precios.destroy', [$this->atun, $precio]))->assertNotFound();
    }

    // --- En la lista

    private function listaEn(Tienda $tienda): ListaCompra
    {
        $this->actingAs($this->user)->post(route('compras.store'));
        $lista = ListaCompra::latest('id')->firstOrFail();
        $this->actingAs($this->user)->patchJson(route('compras.tienda', $lista), ['tienda_id' => $tienda->id])->assertOk()->assertJsonPath('message', "Estás en {$tienda->nombre}.");

        return $lista;
    }

    public function test_al_carrito_queda_con_la_tienda_y_su_ultimo_precio_por_unidad(): void
    {
        $this->anotar($this->walmart, '5.25', 3)->assertOk();
        $lista = $this->listaEn($this->walmart);
        $item = $lista->items()->firstOrFail(); // atún, comprar 4

        $this->actingAs($this->user)->patchJson(route('compras.items.update', [$lista, $item]), ['comprado' => 1])->assertOk()
            ->assertJsonPath('item.precio', '1.75')->assertJsonPath('item.tienda', 'Walmart')
            ->assertJsonPath('resumen.total', 7)->assertJsonPath('resumen.sin_precio', 0);

        // Se corrige con el del estante: queda en el historial como precio de compra
        $this->actingAs($this->user)->patchJson(route('compras.items.update', [$lista, $item]), ['precio' => '1,80'])->assertOk()
            ->assertJsonPath('resumen.total', 7.2);
        $compra = Precio::where('lista_compra_item_id', $item->id)->sole();
        $this->assertSame(['1.80', 1, 'compra', 'Walmart'], [$compra->precio, $compra->unidades, $compra->origen, $compra->tienda->nombre]);

        // Sale del carrito: el precio de compra se borra
        $this->actingAs($this->user)->patchJson(route('compras.items.update', [$lista, $item]), ['comprado' => 0])->assertOk()
            ->assertJsonPath('item.precio', null)->assertJsonPath('resumen.en_carrito', 0);
        $this->assertFalse(Precio::where('lista_compra_item_id', $item->id)->exists());
    }

    public function test_sin_precio_conocido_se_avisa(): void
    {
        $lista = $this->listaEn($this->selectos);
        $item = $lista->items()->firstOrFail();

        $this->actingAs($this->user)->patchJson(route('compras.items.update', [$lista, $item]), ['comprado' => 1])->assertOk()
            ->assertJsonPath('item.precio', null)->assertJsonPath('resumen.sin_precio', 1)->assertJsonPath('resumen.total', 0);
    }

    public function test_no_habia_queda_pendiente_con_la_tienda_y_la_fecha(): void
    {
        $lista = $this->listaEn($this->walmart);
        $item = $lista->items()->firstOrFail();

        $this->actingAs($this->user)->patchJson(route('compras.items.update', [$lista, $item]), ['no_habia' => 1])->assertOk()
            ->assertJsonPath('item.no_habia', 'No había en Walmart (18/11)')->assertJsonPath('item.comprado', false);

        // En otra tienda sí había
        $this->actingAs($this->user)->patchJson(route('compras.tienda', $lista), ['tienda_id' => $this->selectos->id])->assertOk();
        $this->actingAs($this->user)->patchJson(route('compras.items.update', [$lista, $item]), ['comprado' => 1, 'precio' => '1.95'])->assertOk()
            ->assertJsonPath('item.tienda', 'Súper Selectos')->assertJsonPath('item.no_habia', 'No había en Walmart (18/11)');

        $this->actingAs($this->user)->postJson(route('compras.terminar', $lista))->assertOk();
        $this->assertSame(4, $this->atun->fresh()->en_casa);
        $this->actingAs($this->user)->get(route('compras.show', $lista))->assertOk()->assertSee('$ 7.80 · Súper Selectos');
    }

    public function test_no_habia_pide_la_tienda_y_la_tienda_debe_ser_del_hogar(): void
    {
        $this->actingAs($this->user)->post(route('compras.store'));
        $lista = ListaCompra::latest('id')->firstOrFail();
        $item = $lista->items()->firstOrFail();

        $this->actingAs($this->user)->patchJson(route('compras.items.update', [$lista, $item]), ['no_habia' => 1])
            ->assertStatus(422)->assertJsonValidationErrors(['no_habia' => 'Elige primero en qué tienda estás.']);
        $ajena = $this->usuario(['view'])->tiendas()->create(['nombre' => 'Ajena']);
        $this->actingAs($this->user)->patchJson(route('compras.tienda', $lista), ['tienda_id' => $ajena->id])->assertStatus(422)->assertJsonValidationErrors('tienda_id');
    }

    public function test_la_pantalla_de_la_lista_muestra_la_tienda_y_el_total(): void
    {
        $lista = $this->listaEn($this->walmart);

        $this->actingAs($this->user)->get(route('compras.show', $lista))->assertOk()
            ->assertSee('¿En qué tienda estás?')->assertSee('No había')->assertSee('Precio c/u $');
    }
}
