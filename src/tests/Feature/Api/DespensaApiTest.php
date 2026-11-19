<?php

namespace Tests\Feature\Api;

use App\Models\Catalogos\CategoriaDespensa;
use App\Models\MovimientoEfectivo;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/** API de la despensa y las compras (las apps): mismo flujo que la web, con las mismas reglas. Hoy: 18/11 en El Salvador. */
class DespensaApiTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function usuario(array $modulos = ['despensa', 'efectivo']): User
    {
        Carbon::setTestNow(Carbon::parse('2026-11-18 16:00:00', 'UTC'));
        $user = User::factory()->create(['email_verified_at' => now(), 'fecha_corte_dia' => 22, 'zona_horaria' => 'America/El_Salvador', 'onboarding_completed' => true]);
        foreach ($modulos as $modulo) {
            foreach (['view', 'create', 'edit', 'delete'] as $accion) {
                $user->givePermissionTo(Permission::firstOrCreate(['name' => "{$modulo}.{$accion}", 'guard_name' => 'web']));
            }
        }
        $saldo = $user->categoriasPersonales()->create(['nombre' => 'Sueldo', 'tipo' => 'ingreso']);
        $user->movimientosEfectivo()->create(['categoria_personal_id' => $saldo->id, 'descripcion' => 'Sueldo', 'monto' => 100, 'fecha' => '2026-10-23', 'tipo' => 'ingreso']);
        Sanctum::actingAs($user);

        return $user;
    }

    private function enlatados(): int
    {
        return CategoriaDespensa::where('nombre', 'Enlatados')->value('id');
    }

    public function test_productos_despensa_y_codigo_de_barras(): void
    {
        Storage::fake('local');
        $this->usuario();
        $this->getJson('/api/catalogos')->assertOk()->assertJsonPath('despensa.fuentes.walmart', 'Walmart (walmart.com.sv)')
            ->assertJsonPath('despensa.unidades.l', 'L')->assertJsonFragment(['nombre' => 'Enlatados']);

        $id = $this->post('/api/productos', ['nombre' => 'Atún', 'marca' => 'Sardimar', 'cantidad' => '140', 'unidad' => 'g', 'codigo_barras' => '7441 0026 4927 0',
            'categoria_despensa_id' => $this->enlatados(), 'importancia' => 'basico', 'duracion' => 'duradero', 'cantidad_ideal' => 4, 'avisar_cuando' => 1, 'en_casa' => 0,
            'foto' => UploadedFile::fake()->image('atun.jpg', 600, 600)], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.nombre_completo', 'Atún Sardimar 140 g')->assertJsonPath('data.codigo_barras', '7441002649270')
            ->assertJsonPath('data.estado.falta', true)->assertJsonPath('data.estado.por_comprar', 4)->assertJsonPath('data.precios', [])->json('data.id');
        $this->get("/api/productos/{$id}/foto")->assertOk();
        $this->assertStringContainsString('/api/productos/'.$id.'/foto', $this->getJson("/api/productos/{$id}")->json('data.foto_url'));

        // Al escanear: si está en la despensa lo devuelve; si no, 404 para crearlo
        $this->getJson('/api/productos/codigo/7441002649270')->assertOk()->assertJsonPath('data.id', $id);
        $this->getJson('/api/productos/codigo/0000000000000')->assertNotFound();

        $this->patchJson("/api/productos/{$id}/despensa", ['accion' => 'cantidad', 'valor' => 3])->assertOk()->assertJsonPath('estado.en_casa', 3)->assertJsonPath('estado.falta', false);
        $this->getJson('/api/productos?faltan=1')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.resumen.faltan', 0);
        $this->getJson('/api/productos?buscar=sardi')->assertOk()->assertJsonPath('data.0.estado.en_casa', 3)->assertJsonMissingPath('data.0.historial');

        // Tiendas y precios
        $this->postJson('/api/tiendas/comunes')->assertOk()->assertJsonPath('agregadas', 7)->assertJsonCount(7, 'data');
        $walmart = $this->getJson('/api/tiendas')->collect('data')->firstWhere('nombre', 'Walmart');
        $this->assertSame('walmart', $walmart['fuente']);
        $this->postJson("/api/productos/{$id}/precios", ['tienda_id' => $walmart['id'], 'precio' => '5.25', 'unidades' => 3])->assertCreated()
            ->assertJsonPath('data.precios.0.por_unidad', '1.75')->assertJsonPath('data.precios.0.origen', 'anotado');
        $precio = $this->getJson("/api/productos/{$id}")->json('data.historial.0.id');
        $this->deleteJson("/api/productos/{$id}/precios/{$precio}")->assertNoContent();
    }

    public function test_lista_pagar_y_ruta(): void
    {
        $user = $this->usuario();
        $super = $user->categoriasPersonales()->create(['nombre' => 'Supermercado', 'tipo' => 'egreso', 'color' => '#2563EB']);
        $atun = $this->postJson('/api/productos', ['nombre' => 'Atún', 'categoria_despensa_id' => $this->enlatados(), 'importancia' => 'basico',
            'duracion' => 'duradero', 'cantidad_ideal' => 4, 'avisar_cuando' => 1, 'en_casa' => 0])->json('data.id');
        $walmart = $this->postJson('/api/tiendas', ['nombre' => 'Walmart'])->assertCreated()->json('data.id');
        $selectos = $this->postJson('/api/tiendas', ['nombre' => 'Súper Selectos'])->json('data.id');
        $this->postJson("/api/productos/{$atun}/precios", ['tienda_id' => $walmart, 'precio' => '1.90']);
        $this->postJson("/api/productos/{$atun}/precios", ['tienda_id' => $selectos, 'precio' => '1.80']);

        // La lista nace con lo que falta, con la comparación de precios
        $lista = $this->postJson('/api/compras')->assertCreated()->assertJsonPath('data.pendientes.0.descripcion', 'Atún')->assertJsonPath('data.pendientes.0.cantidad', 4)
            ->assertJsonPath('data.pendientes.0.comparacion.0.tienda', 'Súper Selectos')->assertJsonPath('data.consultas_restantes', 3)->json('data.id');
        $this->getJson('/api/compras')->assertOk()->assertJsonPath('abierta.id', $lista)->assertJsonPath('abierta.items_count', 1);
        $this->getJson("/api/compras/{$lista}/ruta?costo=1")->assertOk()->assertJsonPath('paradas.0.tienda', 'Súper Selectos')->assertJsonPath('total', '7.20');

        $pan = $this->postJson("/api/compras/{$lista}/items", ['descripcion' => 'Pan', 'cantidad' => 1])->assertCreated()->json('data.pendientes.1.id');
        $item = $this->getJson("/api/compras/{$lista}")->json('data.pendientes.0.id');
        $this->patchJson("/api/compras/{$lista}/tienda", ['tienda_id' => $walmart])->assertOk()->assertJsonPath('data.tienda_actual', 'Walmart');
        $this->patchJson("/api/compras/{$lista}/items/{$item}", ['comprado' => true])->assertOk()
            ->assertJsonPath('data.comprados.0.precio', '1.90')->assertJsonPath('data.resumen.total', '7.60')->assertJsonPath('data.resumen.por_pagar', 1);
        $this->patchJson("/api/compras/{$lista}/items/{$pan}", ['no_habia' => true])->assertOk()->assertJsonPath('data.pendientes.0.no_habia', 'No había en Walmart (18/11)');

        // Pagar aquí
        $this->getJson("/api/compras/{$lista}/pagar")->assertOk()->assertJsonPath('total', '7.60')->assertJsonPath('categoria_sugerida', $super->id)
            ->assertJsonPath('origenes.0.clave', 'efectivo');
        $this->postJson("/api/compras/{$lista}/pagar", ['origen' => 'efectivo', 'fecha' => '2026-11-18', 'categoria' => (string) $super->id, 'monto_lista' => '7.60',
            'extras_modo' => 'detalle', 'extras' => [['descripcion' => 'Camisa', 'precio' => '12.00', 'categoria' => 'nueva:Ropa']]])
            ->assertCreated()->assertJsonPath('data.pagos.0.total', '19.60')->assertJsonPath('data.pagos.0.gastos.1.categoria', 'Ropa')
            ->assertJsonPath('data.comprados.0.pagado', true);
        $this->assertSame(2, MovimientoEfectivo::where('tipo', 'egreso')->count());
        $this->patchJson("/api/compras/{$lista}/items/{$item}", ['comprado' => false])->assertUnprocessable()->assertJsonValidationErrors('item');

        $pago = $this->getJson("/api/compras/{$lista}")->json('data.pagos.0.id');
        $this->deleteJson("/api/compras/{$lista}/pagos/{$pago}")->assertOk()->assertJsonPath('data.pagos', [])->assertJsonPath('data.resumen.por_pagar', 1);
        $this->assertSame(0, MovimientoEfectivo::where('tipo', 'egreso')->count());

        $this->postJson("/api/compras/{$lista}/terminar")->assertOk()->assertJsonPath('subieron', 1)->assertJsonPath('data.abierta', false);
        $this->getJson("/api/productos/{$atun}")->assertJsonPath('data.estado.en_casa', 4);
    }

    public function test_precios_en_linea_por_api(): void
    {
        $user = $this->usuario(['despensa']);
        Http::preventStrayRequests();
        Http::fake(['www.walmart.com.sv/*' => Http::response([['productId' => '4082301', 'productName' => 'Atún Sardimar - 140 g', 'brand' => 'SARDIMAR', 'link' => 'https://www.walmart.com.sv/a/p',
            'items' => [['ean' => '7441002649270', 'images' => [], 'sellers' => [['commertialOffer' => ['Price' => 2.15]]]]]]], 206),
            'www.ladespensadedonjuan.com.sv/*' => Http::response([], 206)]);
        $atun = $this->postJson('/api/productos', ['nombre' => 'Atún', 'codigo_barras' => '7441002649270', 'categoria_despensa_id' => $this->enlatados(),
            'importancia' => 'basico', 'duracion' => 'duradero', 'cantidad_ideal' => 2, 'en_casa' => 0])->json('data.id');
        $this->postJson('/api/tiendas', ['nombre' => 'Walmart', 'fuente' => 'walmart']);
        $this->postJson('/api/tiendas', ['nombre' => 'Don Juan', 'fuente' => 'donjuan']);

        $r = $this->getJson("/api/productos/{$atun}/en-linea/walmart")->assertOk()->assertJsonPath('resultados.0.mismo_codigo', true)->json('resultados.0');
        $this->postJson("/api/productos/{$atun}/en-linea/walmart", $r)->assertCreated()
            ->assertJsonPath('data.en_linea.*.fuente', ['donjuan', 'walmart'])->assertJsonPath('data.precios.0.precio', '2.15');

        $lista = $this->postJson('/api/compras')->json('data.id');
        $c = $this->postJson("/api/compras/{$lista}/consultas")->assertOk()->assertJsonPath('al_dia', false)->assertJsonPath('estado.total', 1)->json();
        $this->postJson("/api/compras/{$lista}/consultas/{$c['consulta_id']}/siguiente")->assertOk()->assertJsonPath('terminada', true)
            ->assertJsonPath('reusados', 1)->assertJsonPath('fallas', 1); // Walmart ya era de hoy; Don Juan ya no lo tiene
        $this->deleteJson("/api/productos/{$atun}/en-linea/donjuan")->assertNoContent();
    }

    public function test_permisos_y_aislamiento(): void
    {
        $dueno = $this->usuario();
        $id = $this->postJson('/api/productos', ['nombre' => 'Atún', 'categoria_despensa_id' => $this->enlatados(), 'importancia' => 'basico', 'duracion' => 'duradero'])->json('data.id');
        $lista = $this->postJson('/api/compras')->json('data.id');

        $otro = User::factory()->create(['email_verified_at' => now(), 'onboarding_completed' => true]);
        $otro->givePermissionTo(['despensa.view', 'despensa.edit', 'despensa.delete']);
        Sanctum::actingAs($otro);
        $this->getJson("/api/productos/{$id}")->assertNotFound();
        $this->getJson("/api/compras/{$lista}")->assertNotFound();
        $this->deleteJson("/api/productos/{$id}")->assertNotFound();

        $soloVer = User::factory()->create(['email_verified_at' => now(), 'onboarding_completed' => true, 'hogar_id' => $dueno->hogar_id]);
        $soloVer->givePermissionTo('despensa.view');
        Sanctum::actingAs($soloVer);
        $this->getJson("/api/compras/{$lista}")->assertOk();
        $this->patchJson("/api/productos/{$id}/despensa", ['accion' => 'sumar'])->assertForbidden();
        $this->postJson('/api/compras')->assertForbidden();
        $this->getJson("/api/compras/{$lista}/pagar")->assertForbidden();
    }
}
