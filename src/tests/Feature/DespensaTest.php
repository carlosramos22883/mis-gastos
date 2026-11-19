<?php

namespace Tests\Feature;

use App\Models\Catalogos\CategoriaDespensa;
use App\Models\Producto;
use App\Models\User;
use App\Services\DespensaService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class DespensaTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Producto $atun;

    private Producto $leche;

    protected function setUp(): void
    {
        parent::setUp();
        // 22:30 del 18/11 en El Salvador = 04:30 del 19/11 en UTC: "hoy" es el 18 para el usuario
        Carbon::setTestNow(Carbon::parse('2026-11-19 04:30:00', 'UTC'));
        $this->user = $this->usuario(['view', 'create', 'edit', 'delete']);
        $categoria = CategoriaDespensa::where('nombre', 'Enlatados')->value('id');
        $this->atun = $this->user->productos()->create(['nombre' => 'Atún', 'marca' => 'Sardimar', 'categoria_despensa_id' => $categoria,
            'importancia' => 'basico', 'duracion' => 'duradero', 'cantidad_ideal' => 4, 'avisar_cuando' => 1, 'en_casa' => 3]);
        $this->leche = $this->user->productos()->create(['nombre' => 'Leche', 'marca' => 'Salud', 'categoria_despensa_id' => $categoria,
            'importancia' => 'basico', 'duracion' => 'perecedero', 'cantidad_ideal' => 2]);
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

    private function cambiar(Producto $producto, string $accion, $valor = null, ?User $user = null)
    {
        return $this->actingAs($user ?? $this->user)->patchJson(route('productos.despensa', $producto), array_filter(['accion' => $accion, 'valor' => $valor], fn ($v) => $v !== null));
    }

    public function test_restar_hasta_el_aviso_lo_pone_en_faltan_y_cuanto_comprar(): void
    {
        $this->cambiar($this->atun, 'restar')->assertOk()->assertJsonPath('estado.en_casa', 2)->assertJsonPath('estado.falta', false);
        $this->cambiar($this->atun, 'restar')->assertOk()->assertJsonPath('estado.en_casa', 1)
            ->assertJsonPath('estado.falta', true)->assertJsonPath('estado.por_comprar', 3);
        $this->cambiar($this->atun, 'restar');
        $this->cambiar($this->atun, 'restar')->assertOk()->assertJsonPath('estado.en_casa', 0); // no baja de 0

        $this->actingAs($this->user)->get(route('productos.index', ['faltan' => 1]))->assertOk()->assertSee('Atún')->assertDontSee('Leche');
    }

    public function test_se_esta_acabando_y_al_comprar_se_quita(): void
    {
        // La leche no lleva la cuenta: solo se marca
        $this->cambiar($this->leche, 'por_acabarse', 1)->assertOk()->assertJsonPath('estado.falta', true)
            ->assertJsonPath('estado.en_casa', null)->assertJsonPath('estado.por_comprar', 2);

        $this->cambiar($this->leche, 'cantidad', 2)->assertOk()->assertJsonPath('estado.por_acabarse', false)->assertJsonPath('estado.falta', false);
        $this->assertNotNull($this->leche->fresh()->despensa_actualizada_at);
    }

    public function test_se_acabo_deja_cero_y_borra_el_vencimiento(): void
    {
        $this->cambiar($this->leche, 'cantidad', 1);
        $this->cambiar($this->leche, 'vence', '2026-11-20')->assertOk()->assertJsonPath('estado.vencimiento.texto', 'Vence en 2 días');

        $this->cambiar($this->leche, 'se_acabo')->assertOk()->assertJsonPath('estado.en_casa', 0)
            ->assertJsonPath('estado.vence_el', null)->assertJsonPath('estado.falta', true);
    }

    public function test_vencimiento_por_dia_en_la_zona_del_usuario(): void
    {
        $servicio = app(DespensaService::class);
        $hoy = $servicio->hoy($this->user);
        $this->assertSame('2026-11-18', $hoy->toDateString());

        $textos = [];
        foreach (['2026-11-17' => null, '2026-11-18' => null, '2026-11-19' => null, '2026-11-21' => null, '2026-11-25' => null] as $fecha => $_) {
            $this->leche->vence_el = $fecha;
            $textos[$fecha] = [$servicio->vencimiento($this->leche, $hoy)['texto'], $servicio->vencimiento($this->leche, $hoy)['aviso']];
        }
        $this->assertSame([
            '2026-11-17' => ['Venció el 17/11', true],
            '2026-11-18' => ['Vence hoy', true],
            '2026-11-19' => ['Vence mañana', true],
            '2026-11-21' => ['Vence en 3 días', true],
            '2026-11-25' => ['Vence en 7 días', false],
        ], $textos);

        $this->leche->vence_el = '2026-11-17';
        $this->leche->en_casa = 0;
        $this->assertNull($servicio->vencimiento($this->leche, $hoy), 'si no hay, no avisa que venció');
    }

    public function test_un_duradero_no_lleva_vencimiento(): void
    {
        $this->cambiar($this->atun, 'vence', '2027-01-01')->assertStatus(422)->assertJsonValidationErrors('valor');

        $this->actingAs($this->user)->putJson(route('productos.update', $this->atun), [
            'nombre' => 'Atún', 'marca' => 'Sardimar', 'categoria_despensa_id' => $this->atun->categoria_despensa_id,
            'importancia' => 'basico', 'duracion' => 'duradero', 'en_casa' => '2', 'vence_el' => '2027-01-01',
        ])->assertOk();
        $this->assertNull($this->atun->fresh()->vence_el);
        $this->assertSame(2, $this->atun->fresh()->en_casa);
    }

    public function test_dejar_de_llevar_la_cuenta(): void
    {
        $this->cambiar($this->atun, 'no_llevar_cuenta')->assertOk()->assertJsonPath('estado.en_casa', null)->assertJsonPath('estado.falta', false);
    }

    public function test_validaciones_de_la_accion(): void
    {
        $this->cambiar($this->atun, 'tirar')->assertStatus(422)->assertJsonValidationErrors('accion');
        $this->cambiar($this->atun, 'cantidad', -1)->assertStatus(422)->assertJsonValidationErrors('valor');
        $this->cambiar($this->atun, 'cantidad', 1000)->assertStatus(422)->assertJsonValidationErrors('valor');
        $this->cambiar($this->atun, 'sumar', 5)->assertStatus(422)->assertJsonValidationErrors('valor');
        $this->cambiar($this->leche, 'vence', '20/11/2026')->assertStatus(422)->assertJsonValidationErrors('valor');
    }

    public function test_un_archivado_no_falta(): void
    {
        $this->cambiar($this->atun, 'se_acabo');
        $this->atun->update(['activo' => false]);

        $this->assertFalse(app(DespensaService::class)->falta($this->atun->fresh()));
        $this->actingAs($this->user)->get(route('productos.index', ['faltan' => 1]))->assertSee('No falta nada');
    }

    public function test_la_pantalla_muestra_el_resumen_y_los_controles(): void
    {
        $this->cambiar($this->leche, 'cantidad', 1);
        $this->cambiar($this->leche, 'vence', '2026-11-19');
        $this->cambiar($this->atun, 'se_acabo');

        $this->actingAs($this->user)->get(route('productos.index'))->assertOk()
            ->assertSee('Un producto vence pronto o ya venció.')
            ->assertSee('Se está acabando')->assertSee('Se acabó')
            ->assertSee(route('productos.despensa', $this->atun));

        $soloVer = $this->usuario(['view']);
        $soloVer->forceFill(['hogar_id' => $this->user->hogar_id])->save();
        $this->actingAs($soloVer)->get(route('productos.index'))->assertOk()->assertDontSee('Se está acabando');
        $this->cambiar($this->atun, 'sumar', null, $soloVer)->assertForbidden();
    }

    public function test_otro_hogar_no_puede_cambiar_la_despensa(): void
    {
        $this->cambiar($this->atun, 'sumar', null, $this->usuario(['view', 'edit']))->assertNotFound();
        $this->assertSame(3, $this->atun->fresh()->en_casa);
    }
}
