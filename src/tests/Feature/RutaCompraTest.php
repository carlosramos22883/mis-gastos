<?php

namespace Tests\Feature;

use App\Models\Catalogos\CategoriaDespensa;
use App\Models\ListaCompra;
use App\Models\Precio;
use App\Models\Producto;
use App\Models\Tienda;
use App\Models\User;
use App\Services\RutaCompraService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Lista: 4 atunes y 2 leches. Atún: Walmart $1.90, Selectos $1.80, PriceSmart $4.50 el paquete de 3 ($1.50 c/u).
 * Leche: Walmart $1.00, Selectos $1.30 (PriceSmart no tiene precio).
 */
class RutaCompraTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private ListaCompra $lista;

    /** @var array<string, Tienda> */
    private array $t = [];

    /** @var array<string, Producto> */
    private array $p = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-18 16:00:00', 'UTC'));
        $this->user = User::factory()->create(['email_verified_at' => now(), 'onboarding_completed' => true, 'zona_horaria' => 'America/El_Salvador']);
        foreach (['view', 'edit'] as $accion) {
            $this->user->givePermissionTo(Permission::firstOrCreate(['name' => "despensa.{$accion}", 'guard_name' => 'web']));
        }
        $this->actingAs($this->user);
        foreach (['Walmart', 'Súper Selectos', 'PriceSmart'] as $nombre) {
            $this->t[$nombre] = $this->user->tiendas()->create(['nombre' => $nombre]);
        }
        $categoria = CategoriaDespensa::where('nombre', 'Enlatados')->value('id');
        $this->lista = ListaCompra::create(['user_id' => $this->user->id, 'nombre' => 'Compras']);
        foreach (['Atún' => 4, 'Leche' => 2] as $nombre => $cantidad) {
            $this->p[$nombre] = $this->user->productos()->create(['nombre' => $nombre, 'categoria_despensa_id' => $categoria, 'importancia' => 'basico', 'duracion' => 'duradero']);
            $this->lista->items()->create(['producto_id' => $this->p[$nombre]->id, 'descripcion' => $nombre, 'cantidad' => $cantidad]);
        }
        $this->precio('Atún', 'Walmart', 1.90);
        $this->precio('Atún', 'Súper Selectos', 1.80);
        $this->precio('Atún', 'PriceSmart', 4.50, 3);
        $this->precio('Leche', 'Walmart', 1.00);
        $this->precio('Leche', 'Súper Selectos', 1.30);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function precio(string $producto, string $tienda, float $precio, int $unidades = 1, string $fecha = '2026-11-18'): void
    {
        Precio::create(['user_id' => $this->user->id, 'producto_id' => $this->p[$producto]->id, 'tienda_id' => $this->t[$tienda]->id,
            'precio' => $precio, 'unidades' => $unidades, 'origen' => 'anotado', 'fecha' => $fecha]);
    }

    /** @return array<string, list<string>> tienda => cosas */
    private function paradas(float $costo): array
    {
        $r = app(RutaCompraService::class)->calcular($this->lista, $this->user, $costo);

        return collect($r['plan']['tiendas'])->mapWithKeys(fn ($p) => [$p['tienda']->nombre => collect($p['items'])->map(fn ($l) => $l['item']->descripcion)->all()])->all();
    }

    public function test_solo_conviene_otra_tienda_si_el_ahorro_pasa_lo_que_cuesta_ir(): void
    {
        // Todo en Walmart: $7.60 + $2.00 = $9.60. Atún en PriceSmart y leche en Walmart: $6.00 + $2.00 = $8.00, ahorra $1.60
        $this->assertSame(['Walmart' => ['Atún', 'Leche']], $this->paradas(2.00));
        $this->assertSame(['PriceSmart' => ['Atún'], 'Walmart' => ['Leche']], $this->paradas(1.00));

        $r = app(RutaCompraService::class)->calcular($this->lista, $this->user, 1.00);
        $this->assertSame([8.0, 1.0, 1.6], [$r['plan']['total'], $r['plan']['costo_ir'], $r['ahorro']]);
        $this->assertSame('Walmart', $r['una_tienda']['tienda']->nombre);
        $this->assertSame(1.5, $r['plan']['tiendas'][0]['items'][0]['por_unidad']); // por unidad: paquete de 3

        $this->get(route('compras.ruta', [$this->lista, 'costo' => '1']))->assertOk()
            ->assertSee('Ve a 2 tiendas: $ 8.00')->assertSee('Todo en Walmart saldría en $ 9.60')->assertSee('$ 1.60', false)
            ->assertSee('Estoy aquí');
        $this->get(route('compras.ruta', $this->lista))->assertOk()->assertSee('Compra todo en Walmart: $ 9.60');
    }

    public function test_lo_que_no_habia_y_lo_que_no_tiene_precio(): void
    {
        $this->lista->items()->where('descripcion', 'Atún')->update(['no_habia_tienda_id' => $this->t['PriceSmart']->id, 'no_habia_el' => '2026-11-18']);
        $this->lista->items()->create(['descripcion' => 'Piñata', 'cantidad' => 1]);
        $this->lista->items()->where('descripcion', 'Leche')->update(['comprado' => true]); // ya en el carrito: no entra

        $this->assertSame(['Súper Selectos' => ['Atún']], $this->paradas(0));
        $r = app(RutaCompraService::class)->calcular($this->lista, $this->user, 0);
        $this->assertSame(['Piñata'], collect($r['sin_precio'])->pluck('descripcion')->all());
        $this->get(route('compras.ruta', $this->lista))->assertSee('Sin precio')->assertSee('Piñata');
    }

    public function test_cubrir_mas_cosas_va_antes_que_el_precio_y_avisa_lo_viejo(): void
    {
        $arroz = $this->user->productos()->create(['nombre' => 'Arroz', 'categoria_despensa_id' => $this->p['Atún']->categoria_despensa_id, 'importancia' => 'basico', 'duracion' => 'duradero']);
        $this->p['Arroz'] = $arroz;
        $this->lista->items()->create(['producto_id' => $arroz->id, 'descripcion' => 'Arroz', 'cantidad' => 1]);
        $this->precio('Arroz', 'PriceSmart', 3.00, 1, '2026-10-30'); // solo PriceSmart lo tiene, y hace 19 días

        // Aunque ir a 2 tiendas cueste $5, es la única forma de tener todo
        $r = app(RutaCompraService::class)->calcular($this->lista, $this->user, 5.00);
        $this->assertSame(['PriceSmart' => ['Atún', 'Arroz'], 'Walmart' => ['Leche']], $this->paradas(5.00));
        $this->assertSame([[], 1, null], [$r['plan']['sin_cubrir'], $r['viejos'], $r['ahorro']]); // una sola tienda no lo cubre todo: no se compara
        $this->get(route('compras.ruta', [$this->lista, 'costo' => '5']))->assertSee('más de 7 días')->assertSee('Ninguna tienda tiene precio de todo');
    }

    public function test_sin_precios_y_hogar(): void
    {
        Precio::query()->delete();
        $this->get(route('compras.ruta', $this->lista))->assertOk()->assertSee('Aún no hay precios de lo que falta comprar.');
        $this->get(route('compras.ruta', [$this->lista, 'costo' => '-1']))->assertSessionHasErrors('costo');

        $otro = User::factory()->create(['email_verified_at' => now(), 'onboarding_completed' => true]);
        $otro->givePermissionTo('despensa.view');
        $this->actingAs($otro)->get(route('compras.ruta', $this->lista))->assertNotFound();
    }
}
