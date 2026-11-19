<?php

namespace Tests\Feature;

use App\Models\Catalogos\CategoriaDespensa;
use App\Models\Producto;
use App\Models\User;
use App\Services\HogarService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ProductoTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private int $enlatados;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->usuario();
        $this->enlatados = CategoriaDespensa::where('nombre', 'Enlatados')->value('id');
    }

    private function usuario(array $permisos = ['view', 'create', 'edit', 'delete']): User
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'onboarding_completed' => true]);
        foreach ($permisos as $accion) {
            $user->givePermissionTo(Permission::firstOrCreate(['name' => "despensa.{$accion}", 'guard_name' => 'web']));
        }

        return $user;
    }

    private function datos(array $cambios = []): array
    {
        return $cambios + [
            'nombre' => 'Atún en trocitos', 'marca' => 'Sardimar', 'cantidad' => '140', 'unidad' => 'g',
            'codigo_barras' => '7441002637789', 'categoria_despensa_id' => $this->enlatados,
            'importancia' => 'basico', 'duracion' => 'duradero', 'acepta_otra_marca' => '0',
            'cantidad_ideal' => '4', 'avisar_cuando' => '1', 'notas' => 'En aceite',
        ];
    }

    private function crear(array $cambios = [], ?User $user = null)
    {
        return $this->actingAs($user ?? $this->user)->post(route('productos.store'), $this->datos($cambios), ['Accept' => 'application/json']);
    }

    public function test_registrar_un_producto_con_foto_y_verlo_en_la_despensa(): void
    {
        Storage::fake('local');

        $this->crear(['foto' => UploadedFile::fake()->image('atun.jpg', 800, 800)])
            ->assertOk()->assertJson(['success' => true, 'message' => 'Producto agregado: Atún en trocitos Sardimar 140 g.']);

        $producto = Producto::sole();
        $this->assertSame($this->user->hogar_id, $producto->hogar_id);
        $this->assertSame('140 g', $producto->presentacion());
        $this->assertSame(4, $producto->cantidad_ideal);
        Storage::disk('local')->assertExists($producto->foto);
        $this->assertStringStartsWith("productos/{$this->user->hogar_id}/", $producto->foto);

        $this->actingAs($this->user)->get(route('productos.index'))->assertOk()
            ->assertSee('Atún en trocitos')->assertSee('Sardimar · 140 g')->assertSee('Básico')->assertSee('Enlatados');
        $this->actingAs($this->user)->get($producto->urlFoto())->assertOk();
    }

    public function test_presentacion_en_palabras(): void
    {
        $p = new Producto(['nombre' => 'Leche', 'cantidad' => 1, 'unidad' => 'l']);
        $this->assertSame('1 L', $p->presentacion());
        $p->fill(['cantidad' => 12, 'unidad' => 'unidad']);
        $this->assertSame('12 unidades', $p->presentacion());
        $p->fill(['cantidad' => 1, 'unidad' => 'unidad']);
        $this->assertSame('1 unidad', $p->presentacion());
        $p->fill(['cantidad' => 0.5, 'unidad' => 'kg']);
        $this->assertSame('0.5 kg', $p->presentacion());
        $p->fill(['cantidad' => null, 'unidad' => null]);
        $this->assertSame('', $p->presentacion());
        $this->assertSame('Leche', $p->nombreCompleto());
    }

    public function test_la_coma_decimal_y_los_espacios_del_codigo_se_aceptan(): void
    {
        $this->crear(['cantidad' => '1,5', 'unidad' => 'l', 'codigo_barras' => '7441 0026 3778 9'])->assertOk();

        $producto = Producto::sole();
        $this->assertSame('1.5 L', $producto->presentacion());
        $this->assertSame('7441002637789', $producto->codigo_barras);
    }

    public function test_validaciones(): void
    {
        $this->crear(['nombre' => '', 'codigo_barras' => '12AB', 'cantidad' => '140', 'unidad' => '', 'importancia' => 'urgente'])
            ->assertStatus(422)->assertJsonValidationErrors(['nombre', 'codigo_barras', 'unidad', 'importancia']);
        $this->crear(['cantidad_ideal' => '2', 'avisar_cuando' => '2'])
            ->assertStatus(422)->assertJsonValidationErrors(['avisar_cuando' => 'menor que la cantidad ideal']);
        $this->crear(['cantidad' => '', 'unidad' => '', 'marca' => '', 'codigo_barras' => '', 'cantidad_ideal' => '', 'avisar_cuando' => ''])
            ->assertOk(); // solo nombre, categoría, importancia y duración son obligatorios
    }

    public function test_no_se_repite_el_mismo_producto_ni_el_codigo_de_barras(): void
    {
        $this->crear()->assertOk();

        $this->crear(['nombre' => 'ATÚN EN TROCITOS ', 'marca' => 'sardimar', 'codigo_barras' => ''])
            ->assertStatus(422)->assertJsonValidationErrors(['nombre' => 'misma marca y presentación']);
        $this->crear(['nombre' => 'Otro atún'])
            ->assertStatus(422)->assertJsonValidationErrors(['codigo_barras' => 'Ya tienes un producto con ese código']);
        // Otra presentación del mismo producto sí se puede (el paquete de 3, por ejemplo)
        $this->crear(['cantidad' => '420', 'codigo_barras' => ''])->assertOk();
        // Otro hogar puede tener el mismo producto
        $this->crear([], $this->usuario())->assertOk();
        $this->assertSame(3, Producto::count());
    }

    public function test_editar_quitar_la_foto_y_archivar(): void
    {
        Storage::fake('local');
        $this->crear(['foto' => UploadedFile::fake()->image('atun.jpg')])->assertOk();
        $producto = Producto::sole();
        $foto = $producto->foto;

        $this->actingAs($this->user)->put(route('productos.update', $producto), $this->datos(['quitar_foto' => '1', 'activo' => '0', 'importancia' => 'ocasional']), ['Accept' => 'application/json'])
            ->assertOk();

        $producto->refresh();
        $this->assertNull($producto->foto);
        Storage::disk('local')->assertMissing($foto);
        $this->assertFalse($producto->activo);
        $this->assertSame('ocasional', $producto->importancia);
        // Al editar el mismo producto no choca consigo mismo
        $this->actingAs($this->user)->get(route('productos.index'))->assertOk()->assertDontSee('Atún en trocitos');
        $this->actingAs($this->user)->get(route('productos.index', ['archivados' => 1]))->assertOk()->assertSee('Atún en trocitos');
    }

    public function test_eliminar_borra_la_foto(): void
    {
        Storage::fake('local');
        $this->crear(['foto' => UploadedFile::fake()->image('atun.jpg')])->assertOk();
        $producto = Producto::sole();

        $this->actingAs($this->user)->deleteJson(route('productos.destroy', $producto))->assertOk();

        $this->assertSame(0, Producto::count());
        Storage::disk('local')->assertMissing($producto->foto);
    }

    public function test_lista_basicos_primero_y_filtros(): void
    {
        $lacteos = CategoriaDespensa::where('nombre', 'Lácteos y huevos')->value('id');
        $this->crear(['nombre' => 'Sardina', 'marca' => 'Calvo', 'codigo_barras' => '', 'importancia' => 'ocasional'])->assertOk();
        $this->crear(['nombre' => 'Leche', 'marca' => 'Salud', 'cantidad' => '1', 'unidad' => 'l', 'codigo_barras' => '', 'categoria_despensa_id' => $lacteos, 'duracion' => 'perecedero'])->assertOk();
        $this->crear()->assertOk();

        $html = $this->actingAs($this->user)->get(route('productos.index'))->assertOk()->assertSee('3 productos · 2 básicos')->assertSee('Anotar vencimiento')->getContent();
        $this->assertLessThan(strpos($html, 'Sardina'), strpos($html, 'Leche'));
        $this->assertLessThan(strpos($html, 'Sardina'), strpos($html, 'Atún en trocitos'));

        $this->actingAs($this->user)->get(route('productos.index', ['buscar' => 'salud']))->assertSee('Leche')->assertDontSee('Sardina');
        $this->actingAs($this->user)->get(route('productos.index', ['buscar' => '7441002637789']))->assertSee('Atún en trocitos')->assertDontSee('Leche');
        $this->actingAs($this->user)->get(route('productos.index', ['categoria' => $lacteos]))->assertSee('Leche')->assertDontSee('Atún en trocitos');
        $this->actingAs($this->user)->get(route('productos.index', ['importancia' => 'ocasional']))->assertSee('Sardina')->assertDontSee('Leche');
    }

    public function test_otro_hogar_no_ve_ni_toca_el_producto_ni_su_foto(): void
    {
        Storage::fake('local');
        $this->crear(['foto' => UploadedFile::fake()->image('atun.jpg')])->assertOk();
        $producto = Producto::sole();
        $otro = $this->usuario();

        $this->actingAs($otro)->get(route('productos.index'))->assertOk()->assertDontSee('Atún en trocitos');
        $this->actingAs($otro)->get(route('productos.edit', $producto))->assertNotFound();
        $this->actingAs($otro)->get($producto->urlFoto())->assertNotFound();
        $this->actingAs($otro)->putJson(route('productos.update', $producto), $this->datos())->assertNotFound();
        $this->actingAs($otro)->deleteJson(route('productos.destroy', $producto))->assertNotFound();
        $this->assertSame(1, Producto::count());
    }

    public function test_los_miembros_del_hogar_comparten_la_despensa(): void
    {
        $this->crear()->assertOk();
        $pareja = $this->usuario();
        Notification::fake();
        $invitacion = app(HogarService::class)->invitar($this->user, $pareja->email);
        app(HogarService::class)->aceptar($pareja, $invitacion);

        $this->actingAs($pareja->fresh())->get(route('productos.index'))->assertOk()->assertSee('Atún en trocitos');
    }

    public function test_cada_accion_exige_su_permiso(): void
    {
        $soloVer = $this->usuario(['view']);
        $sinPermiso = $this->usuario([]);

        $this->actingAs($sinPermiso)->get(route('productos.index'))->assertForbidden();
        $this->actingAs($soloVer)->get(route('productos.index'))->assertOk()->assertDontSee('Nuevo producto');
        $this->actingAs($soloVer)->post(route('productos.store'), $this->datos(), ['Accept' => 'application/json'])->assertForbidden();
        $this->actingAs($this->user)->get(route('productos.index'))->assertSee('Nuevo producto');
        $this->actingAs($this->user)->get(route('productos.create'))->assertOk()->assertSee('Nuevo producto')->assertSee('Enlatados');
    }

    public function test_la_categoria_es_un_catalogo_del_administrador_y_no_se_elimina_si_esta_en_uso(): void
    {
        $this->crear()->assertOk();
        $admin = User::factory()->create(['email_verified_at' => now(), 'onboarding_completed' => true]);
        foreach (['view', 'delete'] as $accion) {
            $admin->givePermissionTo(Permission::firstOrCreate(['name' => "categorias_despensa.{$accion}", 'guard_name' => 'web']));
        }

        $this->actingAs($admin)->get(route('admin.catalogos.categorias-despensa.index'))->assertOk();
        $this->actingAs($admin)->deleteJson(route('admin.catalogos.categorias-despensa.destroy', $this->enlatados))->assertJsonValidationErrors('catalogo');
        $this->assertTrue(CategoriaDespensa::whereKey($this->enlatados)->exists());
    }
}
