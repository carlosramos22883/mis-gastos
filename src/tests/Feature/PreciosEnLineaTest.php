<?php

namespace Tests\Feature;

use App\Models\Catalogos\CategoriaDespensa;
use App\Models\ConsultaPrecios;
use App\Models\ListaCompra;
use App\Models\Precio;
use App\Models\Producto;
use App\Models\ProductoEnLinea;
use App\Models\Tienda;
use App\Models\User;
use App\Services\PreciosEnLineaService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/** Precios en línea con respuestas simuladas de Walmart/Don Juan (VTEX) y Súper Selectos (HTML). Hoy: 18/11, 10:00 en El Salvador. */
class PreciosEnLineaTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Producto $atun;

    private Producto $leche;

    private Tienda $walmart;

    private Tienda $donjuan;

    private Tienda $selectos;

    private ListaCompra $lista;

    /** Precio que "tiene hoy" Walmart (para cambiarlo entre consultas). */
    private float $precioWalmart = 1.90;

    private int $pedidos = 0;

    private bool $walmartLimita = false;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-18 16:00:00', 'UTC'));
        Cache::flush();
        $this->user = User::factory()->create(['email_verified_at' => now(), 'onboarding_completed' => true, 'zona_horaria' => 'America/El_Salvador']);
        foreach (['view', 'create', 'edit', 'delete'] as $accion) {
            $this->user->givePermissionTo(Permission::firstOrCreate(['name' => "despensa.{$accion}", 'guard_name' => 'web']));
        }
        $this->actingAs($this->user);
        $categoria = CategoriaDespensa::where('nombre', 'Enlatados')->value('id');
        $this->atun = $this->user->productos()->create(['nombre' => 'Atún', 'marca' => 'Sardimar', 'cantidad' => 140, 'unidad' => 'g', 'codigo_barras' => '7441002649270',
            'categoria_despensa_id' => $categoria, 'importancia' => 'basico', 'duracion' => 'duradero', 'cantidad_ideal' => 4, 'avisar_cuando' => 1, 'en_casa' => 0]);
        $this->leche = $this->user->productos()->create(['nombre' => 'Leche', 'marca' => 'Salud', 'categoria_despensa_id' => $categoria, 'importancia' => 'basico', 'duracion' => 'duradero']);
        $this->walmart = $this->user->tiendas()->create(['nombre' => 'Walmart', 'fuente' => 'walmart']);
        $this->donjuan = $this->user->tiendas()->create(['nombre' => 'La Despensa de Don Juan', 'fuente' => 'donjuan']);
        $this->selectos = $this->user->tiendas()->create(['nombre' => 'Súper Selectos', 'fuente' => 'selectos']);
        $this->lista = ListaCompra::create(['user_id' => $this->user->id, 'nombre' => 'Compras']);
        $this->lista->items()->create(['producto_id' => $this->atun->id, 'descripcion' => 'Atún Sardimar 140 g', 'cantidad' => 4]);
        $this->lista->items()->create(['producto_id' => $this->leche->id, 'descripcion' => 'Leche Salud', 'cantidad' => 2]);

        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            $this->pedidos++;
            if ($this->walmartLimita && str_contains($request->url(), 'walmart.com.sv')) {
                return Http::response('', 429);
            }
            if (str_contains($request->url(), 'superselectos.com')) {
                return Http::response($this->paginaSelectos());
            }
            parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $q);
            $base = str_contains($request->url(), 'walmart') ? 'https://www.walmart.com.sv' : 'https://www.ladespensadedonjuan.com.sv';
            $atun = $this->vtex('4082301', 'Atún Sardimar Trocitos Ahumado - 140 g', '7441002649270', $this->precioWalmart, $base);
            $otro = $this->vtex('4050326', 'Atún Sardimar Light Trozos En Agua - 105 g', '7441002639189', 1.75, $base);

            return Http::response(match (true) {
                ($q['fq'] ?? '') === 'alternateIds_Ean:7441002649270', ($q['fq'] ?? '') === 'productId:4082301' => [$atun],
                str_starts_with($q['fq'] ?? '', 'alternateIds_Ean:'), str_starts_with($q['fq'] ?? '', 'productId:') => [],
                default => [$otro, $atun],
            }, 206);
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function vtex(string $id, string $nombre, string $ean, float $precio, string $base): array
    {
        return ['productId' => $id, 'productName' => $nombre, 'brand' => 'SARDIMAR', 'link' => $base.'/'.$id.'/p',
            'items' => [['ean' => $ean, 'images' => [['imageUrl' => 'https://walmartsv.vteximg.com.br/arquivos/ids/'.$id.'.jpg']],
                'sellers' => [['commertialOffer' => ['Price' => $precio, 'ListPrice' => $precio, 'AvailableQuantity' => 100]]]]]];
    }

    private function paginaSelectos(): string
    {
        $tarjeta = fn ($id, $nombre, $precio, $antes = '') => '<div class="info-prod"><div class="precios"><div class="label-precios"><div class="lside">'
            .'<strong class="precio" b-x>$'.$precio.'</strong>'.$antes.'</div></div></div><h5 class="prod-nombre"><a class="clickeable" href="https://www.superselectos.com/products?keyword=x&amp;productId='.$id.'">'.$nombre.'</a></h5></div>';

        return '<html><body>'.$tarjeta('14785', 'Platanos Con Chile Limon Y Sal Zambos Yummies 140 g', '1.68').$tarjeta('82892', 'Atun Sardimar Trozos Agua Light 140 g Lata', '1.95')
            .$tarjeta('110646', 'Atun Sardimar Trocitos Ahumado 140 g Lata', '1.80', '<span class="antes">$2.10</span>').'</body></html>';
    }

    private function enlazar(Producto $producto, string $fuente, array $datos)
    {
        return $this->postJson(route('productos.en-linea.store', [$producto, $fuente]), $datos);
    }

    private function consultarTodo(): array
    {
        $r = $this->postJson(route('compras.consultas.store', $this->lista))->assertOk()->json();
        $estado = $r['estado'] ?? null;
        $vueltas = 0;
        while ($estado && ! $estado['terminada'] && $vueltas++ < 20) {
            Carbon::setTestNow(now()->addSeconds(3)); // la pausa entre productos
            $estado = $this->postJson($r['siguiente'])->assertOk()->json();
        }

        return $r + ['final' => $estado];
    }

    public function test_buscar_por_codigo_de_barras_y_por_nombre(): void
    {
        $this->getJson(route('productos.en-linea.buscar', [$this->atun, 'walmart']))->assertOk()
            ->assertJsonPath('resultados.0.codigo', '4082301')->assertJsonPath('resultados.0.mismo_codigo', true)->assertJsonPath('resultados.0.precio', 1.9)
            ->assertJsonCount(1, 'resultados');
        // Sin código de barras que coincida: por nombre, y el que coincide con el código sale primero
        $this->getJson(route('productos.en-linea.buscar', [$this->atun, 'walmart']).'?q=atun sardimar')->assertOk()
            ->assertJsonPath('resultados.*.codigo', ['4082301', '4050326']);
        // Selectos se lee de su página: precio actual (no el de antes de la oferta) y su productId
        $this->getJson(route('productos.en-linea.buscar', [$this->atun, 'selectos']))->assertOk()
            ->assertJsonPath('resultados.*.codigo', ['82892', '110646'])->assertJsonPath('resultados.1.precio', 1.8)
            ->assertJsonPath('resultados.1.nombre', 'Atun Sardimar Trocitos Ahumado 140 g Lata');

        // Una fuente sin tienda del hogar no se busca
        $this->selectos->update(['activo' => false]);
        $this->getJson(route('productos.en-linea.buscar', [$this->atun, 'selectos']))->assertUnprocessable()->assertJsonValidationErrors('fuente');
    }

    public function test_ligar_walmart_liga_tambien_don_juan_y_guarda_el_precio_visto(): void
    {
        $this->enlazar($this->atun, 'walmart', ['codigo' => '4082301', 'nombre' => 'Atún Sardimar Trocitos Ahumado - 140 g', 'url' => 'https://www.walmart.com.sv/4082301/p', 'precio' => '1.90'])
            ->assertOk()->assertJsonPath('message', 'Listo: Atún quedó ligado en Walmart.');

        $this->assertSame(['donjuan' => 'https://www.ladespensadedonjuan.com.sv/4082301/p', 'walmart' => 'https://www.walmart.com.sv/4082301/p'],
            ProductoEnLinea::orderBy('fuente')->pluck('url', 'fuente')->all());
        $this->assertSame([[$this->walmart->id, '1.90', 'en_linea', '2026-11-18']], Precio::all()->map(fn ($p) => [$p->tienda_id, $p->precio, $p->origen, $p->fecha->toDateString()])->all());

        $this->get(route('productos.precios', $this->atun))->assertOk()->assertSee('Atún Sardimar Trocitos Ahumado - 140 g')->assertSee('Buscar en Súper Selectos');
        $this->deleteJson(route('productos.en-linea.destroy', [$this->atun, 'donjuan']))->assertOk();
        $this->assertSame(['walmart'], ProductoEnLinea::pluck('fuente')->all());
        $this->assertSame(1, Precio::count()); // el historial se queda
    }

    public function test_consultar_la_lista_de_uno_en_uno_y_reusar_lo_de_hoy(): void
    {
        $this->postJson(route('compras.consultas.store', $this->lista))->assertUnprocessable()->assertJsonValidationErrors('consulta'); // nada ligado
        $this->enlazar($this->atun, 'walmart', ['codigo' => '4082301', 'nombre' => 'Atún Sardimar Trocitos Ahumado - 140 g']);
        $this->enlazar($this->atun, 'selectos', ['codigo' => '110646', 'nombre' => 'Atun Sardimar Trocitos Ahumado 140 g Lata', 'unidades' => 1]);
        $this->assertSame(0, $this->pedidos);

        $r = $this->postJson(route('compras.consultas.store', $this->lista))->assertOk()->assertJsonPath('estado.total', 1)->json();
        $this->postJson($r['siguiente'])->assertOk()->assertJsonPath('hechos', 1)->assertJsonPath('nuevos', 3)->assertJsonPath('terminada', true)
            ->assertJsonPath('producto', 'Atún Sardimar 140 g');
        $this->assertSame(3, $this->pedidos); // Walmart, Don Juan y Selectos: una vez cada una
        $this->assertSame(['La Despensa de Don Juan' => '1.90', 'Súper Selectos' => '1.80', 'Walmart' => '1.90'],
            Precio::with('tienda')->where('origen', 'en_linea')->get()->mapWithKeys(fn ($p) => [$p->tienda->nombre => $p->precio])->sortKeys()->all());

        // En la lista: lo más barato primero, con la antigüedad
        $this->get(route('compras.show', $this->lista))->assertOk()->assertSeeInOrder(['Súper Selectos', '$1.80', 'hoy', 'La Despensa de Don Juan', '$1.90'])->assertSee('Te quedan 2 consultas hoy.');

        // Otra consulta hoy, antes de 12 horas: todo está al día y no gasta consulta ni pide nada a las tiendas
        Carbon::setTestNow(now()->addHours(3));
        $this->postJson(route('compras.consultas.store', $this->lista))->assertOk()->assertJsonPath('al_dia', true);
        $this->assertSame([3, 1], [$this->pedidos, ConsultaPrecios::count()]);

        // Al día siguiente (aunque hayan pasado menos de 12 horas) se vuelve a consultar
        Carbon::setTestNow(Carbon::parse('2026-11-19 07:00:00', 'UTC')); // 19/11 01:00 en El Salvador
        $this->precioWalmart = 1.85;
        $this->assertSame(3, $this->consultarTodo()['final']['nuevos']);
        $this->assertSame('1.85', Precio::where('tienda_id', $this->walmart->id)->latest('id')->value('precio'));
    }

    public function test_pausa_entre_productos_y_limite_por_dia(): void
    {
        $this->enlazar($this->atun, 'selectos', ['codigo' => '110646', 'nombre' => 'Atun Sardimar Trocitos Ahumado 140 g Lata']);
        $this->enlazar($this->leche, 'selectos', ['codigo' => '82892', 'nombre' => 'Atun Sardimar Trozos Agua Light 140 g Lata']);

        $r = $this->postJson(route('compras.consultas.store', $this->lista))->assertOk()->assertJsonPath('estado.total', 2)->json();
        $this->postJson($r['siguiente'])->assertOk()->assertJsonPath('hechos', 1);
        $this->postJson($r['siguiente'])->assertOk()->assertJsonPath('hechos', 1)->assertJsonPath('espera', 2000); // muy pronto: no consulta
        // Volver a tocar «Consultar precios» continúa la misma (no gasta otra)
        $this->postJson(route('compras.consultas.store', $this->lista))->assertOk()->assertJsonPath('estado.hechos', 1);
        Carbon::setTestNow(now()->addSeconds(3));
        $this->postJson($r['siguiente'])->assertOk()->assertJsonPath('hechos', 2)->assertJsonPath('terminada', true);

        // 3 consultas al día por hogar
        for ($i = 0; $i < 2; $i++) {
            Precio::query()->delete(); // que haya algo que consultar
            $this->consultarTodo();
        }
        Precio::query()->delete();
        $this->postJson(route('compras.consultas.store', $this->lista))->assertUnprocessable()
            ->assertJsonPath('errors.consulta.0', 'Ya consultaste precios 3 veces hoy. Mañana puedes volver a consultar; mientras tanto se muestran los últimos precios.');
        $this->assertSame(0, app(PreciosEnLineaService::class)->restantesHoy($this->user));
    }

    public function test_si_una_tienda_falla_se_pausa_y_las_demas_siguen(): void
    {
        $this->enlazar($this->atun, 'walmart', ['codigo' => '4082301', 'nombre' => 'Atún']);
        $this->enlazar($this->atun, 'selectos', ['codigo' => '999', 'nombre' => 'Ya no existe']); // la tienda ya no lo tiene
        $this->walmartLimita = true;

        $this->assertSame(['nuevos' => 1, 'fallas' => 2], array_intersect_key($this->consultarTodo()['final'], ['nuevos' => 1, 'fallas' => 1]));
        $this->assertSame('no_encontrado', ProductoEnLinea::where('fuente', 'selectos')->value('error'));
        $this->assertNotNull(app(PreciosEnLineaService::class)->pausadaHasta('walmart'));
        $this->getJson(route('productos.en-linea.buscar', [$this->atun, 'walmart']))->assertUnprocessable()->assertJsonValidationErrors('buscar');
        $this->get(route('productos.precios', $this->atun))->assertSee('la tienda ya no lo tenía');
    }

    public function test_hogar_y_permisos(): void
    {
        $otro = User::factory()->create(['email_verified_at' => now(), 'onboarding_completed' => true]);
        $otro->givePermissionTo(['despensa.view', 'despensa.edit']);
        $this->actingAs($otro)->getJson(route('productos.en-linea.buscar', [$this->atun, 'walmart']))->assertNotFound();
        $this->actingAs($otro)->postJson(route('compras.consultas.store', $this->lista))->assertNotFound();

        $soloVer = User::factory()->create(['email_verified_at' => now(), 'onboarding_completed' => true, 'hogar_id' => $this->user->hogar_id]);
        $soloVer->givePermissionTo('despensa.view');
        $this->actingAs($soloVer)->getJson(route('productos.en-linea.buscar', [$this->atun, 'walmart']))->assertForbidden();
        $this->actingAs($this->user)->getJson(route('productos.en-linea.buscar', [$this->atun, 'amazon']))->assertNotFound();
        $this->actingAs($this->user)->enlazar($this->atun, 'walmart', ['codigo' => '1', 'nombre' => 'X', 'url' => 'javascript:alert(1)'])->assertUnprocessable()->assertJsonValidationErrors('url');
    }
}
