<?php

namespace Tests\Feature;

use App\Models\Catalogos\CategoriaDespensa;
use App\Models\ListaCompra;
use App\Models\Producto;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ListaCompraTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Producto $atun;

    private Producto $leche;

    private Producto $cafe;

    private Producto $cereal;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-19 03:00:00', 'UTC')); // 18/11 en El Salvador
        $this->user = $this->usuario(['view', 'create', 'edit', 'delete']);
        $enlatados = CategoriaDespensa::where('nombre', 'Enlatados')->value('id');
        $lacteos = CategoriaDespensa::where('nombre', 'Lácteos y huevos')->value('id');
        $base = ['importancia' => 'basico', 'duracion' => 'duradero', 'categoria_despensa_id' => $enlatados];
        // Falta: queda 1 y el aviso es 1 → comprar 3 para llegar a 4
        $this->atun = $this->user->productos()->create($base + ['nombre' => 'Atún', 'marca' => 'Sardimar', 'cantidad_ideal' => 4, 'avisar_cuando' => 1, 'en_casa' => 1]);
        // Falta: se acabó → comprar 2
        $this->leche = $this->user->productos()->create(['nombre' => 'Leche', 'categoria_despensa_id' => $lacteos, 'importancia' => 'basico',
            'duracion' => 'perecedero', 'cantidad_ideal' => 2, 'en_casa' => 0]);
        // Falta: se está acabando, sin contar → comprar 1
        $this->cafe = $this->user->productos()->create($base + ['nombre' => 'Café', 'importancia' => 'ocasional', 'por_acabarse' => true]);
        // No falta
        $this->cereal = $this->user->productos()->create($base + ['nombre' => 'Cereal', 'cantidad_ideal' => 2, 'avisar_cuando' => 0, 'en_casa' => 2]);
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

    private function nuevaLista(?User $user = null): ListaCompra
    {
        $this->actingAs($user ?? $this->user)->post(route('compras.store'))->assertRedirect();

        return ListaCompra::latest('id')->firstOrFail();
    }

    private function item(ListaCompra $lista, Producto $producto)
    {
        return $lista->items()->where('producto_id', $producto->id)->firstOrFail();
    }

    public function test_la_lista_nueva_trae_lo_que_falta_con_cuanto_comprar(): void
    {
        $lista = $this->nuevaLista();

        $this->assertSame('Compras del 18/11/2026', $lista->nombre);
        $this->assertEqualsCanonicalizing(['Atún Sardimar' => 3, 'Leche' => 2, 'Café' => 1],
            $lista->items->mapWithKeys(fn ($i) => [$i->descripcion => $i->cantidad])->all());
        $this->assertTrue($lista->items->every->automatico);

        $html = $this->actingAs($this->user)->get(route('compras.show', $lista))->assertOk()
            ->assertSee('en el carrito')->assertSee('Por comprar')->getContent();
        // Básicos primero (atún y leche), luego el café
        $this->assertLessThan(strpos($html, 'Café'), strpos($html, 'Leche'));
    }

    public function test_solo_hay_una_lista_abierta(): void
    {
        $primera = $this->nuevaLista();
        $this->actingAs($this->user)->post(route('compras.store'))->assertRedirect(route('compras.show', $primera));

        $this->assertSame(1, ListaCompra::count());
        $this->actingAs($this->user)->get(route('compras.index'))->assertOk()->assertSee('Lista abierta')->assertDontSee('Nueva lista de compras');
    }

    public function test_agregar_quitar_anotar_y_cambiar_cantidad(): void
    {
        $lista = $this->nuevaLista();

        // Un producto que no falta, con la cantidad que diga el usuario
        $this->actingAs($this->user)->postJson(route('compras.items.store', $lista), ['producto_id' => $this->cereal->id, 'cantidad' => 2])
            ->assertOk()->assertJsonPath('message', 'Agregado: Cereal.');
        $this->actingAs($this->user)->postJson(route('compras.items.store', $lista), ['producto_id' => $this->cereal->id])
            ->assertStatus(422)->assertJsonValidationErrors(['producto_id' => 'ya está en la lista']);
        // Algo fuera del catálogo
        $this->actingAs($this->user)->postJson(route('compras.items.store', $lista), ['descripcion' => 'Piñata'])->assertOk();
        $this->actingAs($this->user)->postJson(route('compras.items.store', $lista), [])
            ->assertStatus(422)->assertJsonValidationErrors(['producto_id', 'descripcion']);

        $cafe = $this->item($lista, $this->cafe);
        $this->actingAs($this->user)->deleteJson(route('compras.items.destroy', [$lista, $cafe]))->assertOk();
        $atun = $this->item($lista, $this->atun);
        $this->actingAs($this->user)->patchJson(route('compras.items.update', [$lista, $atun]), ['cantidad' => 6])->assertOk()->assertJsonPath('item.cantidad', 6);
        $this->actingAs($this->user)->patchJson(route('compras.items.update', [$lista, $atun]), ['cantidad' => 0])->assertStatus(422);

        $this->assertEqualsCanonicalizing(['Atún Sardimar', 'Leche', 'Cereal', 'Piñata'], $lista->items()->pluck('descripcion')->all());
        $this->assertNull($lista->items()->where('descripcion', 'Piñata')->value('producto_id'));
    }

    public function test_agregar_lo_que_falta_despues(): void
    {
        $lista = $this->nuevaLista();
        $this->cereal->update(['en_casa' => 0]);

        $this->actingAs($this->user)->postJson(route('compras.faltantes', $lista))->assertOk()->assertJsonPath('message', 'Se agregó 1 producto que falta.');
        $this->actingAs($this->user)->postJson(route('compras.faltantes', $lista))->assertOk()->assertJsonPath('message', 'No falta nada más en la despensa.');
        $this->assertSame(2, $this->item($lista, $this->cereal)->cantidad);
    }

    public function test_terminar_sube_lo_comprado_en_la_despensa(): void
    {
        $lista = $this->nuevaLista();
        $this->actingAs($this->user)->postJson(route('compras.items.store', $lista), ['descripcion' => 'Piñata'])->assertOk();
        foreach ([$this->item($lista, $this->atun), $this->item($lista, $this->cafe), $lista->items()->where('descripcion', 'Piñata')->first()] as $item) {
            $this->actingAs($this->user)->patchJson(route('compras.items.update', [$lista, $item]), ['comprado' => 1])->assertOk()->assertJsonPath('item.comprado', true);
        }

        $this->actingAs($this->user)->postJson(route('compras.terminar', $lista))->assertOk()
            ->assertJsonPath('message', 'Compra terminada. 2 productos subieron en tu despensa.')->assertJsonPath('redirect', route('compras.index'));

        $this->assertSame(4, $this->atun->fresh()->en_casa);           // 1 + 3
        $this->assertFalse($this->cafe->fresh()->por_acabarse);        // sin contar: deja de faltar
        $this->assertSame(0, $this->leche->fresh()->en_casa);          // no se compró
        $this->assertNotNull($lista->fresh()->terminada_at);

        // Terminada: no se cambia y aparece en el historial
        $this->actingAs($this->user)->patchJson(route('compras.items.update', [$lista, $this->item($lista, $this->leche)]), ['comprado' => 1])
            ->assertStatus(422)->assertJsonValidationErrors('lista');
        $this->actingAs($this->user)->postJson(route('compras.terminar', $lista))->assertStatus(422);
        $this->actingAs($this->user)->get(route('compras.index'))->assertSee('Compras del 18/11/2026')->assertSee('3 de 4 comprados')->assertSee('Nueva lista de compras');
        $this->actingAs($this->user)->get(route('compras.show', $lista))->assertOk()->assertSee('No se compró')->assertDontSee('Terminar compra');
    }

    public function test_un_producto_eliminado_queda_en_la_lista_con_su_nombre(): void
    {
        $lista = $this->nuevaLista();
        $this->atun->delete();

        $this->actingAs($this->user)->get(route('compras.show', $lista))->assertOk()->assertSee('Atún Sardimar')->assertSee('Anotado');
    }

    public function test_otro_hogar_no_ve_ni_toca_la_lista(): void
    {
        $lista = $this->nuevaLista();
        $otro = $this->usuario(['view', 'create', 'edit', 'delete']);
        $item = $this->item($lista, $this->atun);

        $this->actingAs($otro)->get(route('compras.show', $lista))->assertNotFound();
        $this->actingAs($otro)->patchJson(route('compras.items.update', [$lista, $item]), ['comprado' => 1])->assertNotFound();
        $this->actingAs($otro)->postJson(route('compras.terminar', $lista))->assertNotFound();
        $this->actingAs($otro)->deleteJson(route('compras.destroy', $lista))->assertNotFound();
        // Ni agregar un producto de otro hogar a su propia lista
        $suya = $this->nuevaLista($otro);
        $this->actingAs($otro)->postJson(route('compras.items.store', $suya), ['producto_id' => $this->cereal->id])->assertStatus(422);
        // Un item de otra lista por la URL de esta
        $this->actingAs($otro)->patchJson(route('compras.items.update', [$suya, $item]), ['comprado' => 1])->assertNotFound();
    }

    public function test_permisos(): void
    {
        $lista = $this->nuevaLista();
        $soloVer = $this->usuario(['view']);
        $soloVer->forceFill(['hogar_id' => $this->user->hogar_id])->save();

        $this->actingAs($soloVer)->get(route('compras.show', $lista))->assertOk()->assertDontSee('Terminar compra')->assertDontSee('Agregar de tu despensa');
        $this->actingAs($soloVer)->post(route('compras.store'))->assertForbidden();
        $this->actingAs($soloVer)->postJson(route('compras.terminar', $lista))->assertForbidden();
        $this->actingAs($this->usuario([]))->get(route('compras.index'))->assertForbidden();
    }

    public function test_eliminar_la_lista(): void
    {
        $lista = $this->nuevaLista();

        $this->actingAs($this->user)->deleteJson(route('compras.destroy', $lista))->assertOk()->assertJsonPath('redirect', route('compras.index'));
        $this->assertSame(0, ListaCompra::count());
        $this->assertSame(1, $this->atun->fresh()->en_casa, 'la despensa no cambia');
    }
}
