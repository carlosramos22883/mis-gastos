<?php

namespace Tests\Feature;

use App\Models\CategoriaPersonal;
use App\Models\User;
use App\Services\CompromisoService;
use App\Services\DashboardService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * El tope del ciclo y los presupuestos siempre cuadran:
 * tope − gastado − por pagar = sin asignar + lo que queda en los presupuestos − lo gastado o por pagar fuera de ellos.
 *
 * Ciclo del 23/10 al 22/11, tope $900. Presupuestos: Súper $500, Gaby $250 y Suscripciones $50 (suman $800: $100 sin
 * asignar). Gaby tiene una cuota de $50 por pagar. Fuera de presupuesto: $60 en Entretenimiento y una compra de $20 por
 * pagar en Compras. La cuota de un préstamo no cuenta para el tope.
 */
class TopeCuadreTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    /** @var array<string, CategoriaPersonal> */
    private array $cat = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-30 12:00:00');
        $this->user = User::factory()->create(['email_verified_at' => now(), 'fecha_corte_dia' => 22, 'zona_horaria' => 'America/El_Salvador', 'onboarding_completed' => true]);
        foreach (['efectivo', 'compromisos'] as $modulo) {
            foreach (['view', 'create', 'edit', 'delete'] as $accion) {
                $this->user->givePermissionTo(Permission::firstOrCreate(['name' => "{$modulo}.{$accion}", 'guard_name' => 'web']));
            }
        }
        $this->actingAs($this->user);
        $sueldo = $this->user->categoriasPersonales()->create(['nombre' => 'Sueldo', 'tipo' => 'ingreso']);
        $this->user->movimientosEfectivo()->create(['categoria_personal_id' => $sueldo->id, 'descripcion' => 'Sueldo', 'monto' => 3000, 'fecha' => '2026-10-23', 'tipo' => 'ingreso']);

        foreach (['Súper', 'Gaby', 'Suscripciones', 'Entretenimiento', 'Compras', 'Préstamos y deudas'] as $nombre) {
            $this->cat[$nombre] = $this->user->categoriasPersonales()->create(['nombre' => $nombre, 'tipo' => 'egreso', 'activo' => true, 'color' => '#F97316']);
        }
        $compromisos = app(CompromisoService::class);
        foreach (['Súper' => 500, 'Gaby' => 250, 'Suscripciones' => 50] as $nombre => $monto) {
            $compromisos->crearRecurrente($this->user, ['descripcion' => $nombre, 'monto' => $monto, 'medio_pago' => 'efectivo', 'categoria_personal_id' => $this->cat[$nombre]->id,
                'es_presupuesto' => true, 'frecuencia' => 'mensual', 'dia_pago' => 25, 'fecha_inicio' => '2026-10-23']);
        }
        foreach (['Gaby' => 50, 'Compras' => 20] as $nombre => $monto) {
            $compromisos->crearRecurrente($this->user, ['descripcion' => "Cuota de {$nombre}", 'monto' => $monto, 'medio_pago' => 'efectivo',
                'categoria_personal_id' => $this->cat[$nombre]->id, 'frecuencia' => 'mensual', 'dia_pago' => 15, 'fecha_inicio' => '2026-10-23']);
        }
        $compromisos->sincronizar($this->user);
        $this->patchJson(route('hogar.tope'), ['tope_gasto_ciclo' => '900'])->assertOk();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function gasto(string $categoria, float $monto): void
    {
        $this->postJson(route('efectivo.store'), ['descripcion' => "Gasto de {$categoria}", 'monto' => $monto, 'fecha' => '30/10/2026',
            'tipo' => 'egreso', 'categoria_personal_id' => $this->cat[$categoria]->id])->assertOk();
    }

    /** Las categorías fuera de presupuesto, sin sus listas de movimientos. */
    private function fuera(array $tope): array
    {
        return array_map(fn ($f) => collect($f)->only(['nombre', 'gastado', 'comprometido'])->all(), $tope['fuera']);
    }

    /** queda del tope = sin asignar + lo que queda en los presupuestos − lo de fuera. */
    private function assertCuadra(array $d): void
    {
        $t = $d['tope'];
        $quedan = round(collect($d['presupuestos'])->sum('queda'), 2);
        $this->assertSame($t['queda'], round($t['sin_asignar'] + $quedan - $t['fuera_total'], 2), 'el tope no cuadra con los presupuestos');
        $this->assertSame($t['presupuestado'], round(collect($d['presupuestos'])->sum('monto'), 2));
    }

    public function test_el_tope_cuadra_con_los_presupuestos_y_lo_que_esta_fuera_de_ellos(): void
    {
        $this->assertCuadra(app(DashboardService::class)->resumen($this->user));

        $this->gasto('Súper', 483);
        $this->gasto('Gaby', 150);
        $this->gasto('Suscripciones', 46);
        $this->gasto('Entretenimiento', 60);
        $this->gasto('Préstamos y deudas', 300);

        $d = app(DashboardService::class)->resumen($this->user);
        $t = $d['tope'];

        // Lo gastado sin el préstamo; lo por pagar: la cuota de Gaby y la compra sin presupuesto.
        $this->assertSame(739.0, $t['gastado']);
        $this->assertSame(70.0, $t['comprometido']);
        $this->assertSame(91.0, $t['queda']);
        $this->assertSame(800.0, $t['presupuestado']);
        $this->assertSame(100.0, $t['sin_asignar']);
        $this->assertSame([
            ['nombre' => 'Entretenimiento', 'gastado' => 60.0, 'comprometido' => 0.0],
            ['nombre' => 'Compras', 'gastado' => 0.0, 'comprometido' => 20.0],
        ], $this->fuera($t));
        $this->assertSame(80.0, $t['fuera_total']);
        // Súper $17, Gaby $50 (ya aparta la cuota), Suscripciones $4: $71. 100 + 71 − 80 = 91.
        $this->assertSame(71.0, round(collect($d['presupuestos'])->sum('queda'), 2));
        $this->assertCuadra($d);
    }

    public function test_tambien_cuadra_al_pasarse_de_un_presupuesto(): void
    {
        $this->gasto('Súper', 530);
        $this->gasto('Entretenimiento', 15);

        $d = app(DashboardService::class)->resumen($this->user);

        $this->assertSame(-30.0, collect($d['presupuestos'])->firstWhere('descripcion', 'Súper')['queda']);
        $this->assertCuadra($d);
    }

    private function gastoRevisado(string $categoria, float $monto, array $extra = [])
    {
        return $this->postJson(route('efectivo.store'), ['descripcion' => "Gasto de {$categoria}", 'monto' => $monto, 'fecha' => '30/10/2026',
            'tipo' => 'egreso', 'categoria_personal_id' => $this->cat[$categoria]->id, ...array_merge(['presupuesto' => 'revisar'], $extra)]);
    }

    public function test_un_gasto_en_una_categoria_sin_presupuesto_avisa_antes_de_guardarse(): void
    {
        $respuesta = $this->gastoRevisado('Entretenimiento', 60)->assertStatus(422);

        $this->assertTrue($respuesta->json('presupuesto.sin_presupuesto'));
        $this->assertSame('60.00', $respuesta->json('presupuesto.excedente'));
        $this->assertStringContainsString('«Entretenimiento» no tenía presupuesto', $respuesta->json('message'));
        $this->assertEqualsCanonicalizing(['Súper', 'Gaby', 'Suscripciones'], collect($respuesta->json('presupuesto.opciones'))->pluck('categoria')->all());
        $this->assertSame(0, $this->user->movimientosEfectivo()->where('descripcion', 'Gasto de Entretenimiento')->count(), 'no se guardó');
    }

    public function test_cubrirlo_le_da_presupuesto_a_la_categoria_con_lo_que_quita_a_otra(): void
    {
        $super = collect(app(DashboardService::class)->resumen($this->user)['presupuestos'])->firstWhere('descripcion', 'Súper');
        $this->gastoRevisado('Entretenimiento', 60, ['presupuesto' => 'cubrir', 'traspasos' => [['presupuesto_id' => $super['id'], 'monto' => '60']]])->assertOk();

        $d = app(DashboardService::class)->resumen($this->user);
        $presupuestos = collect($d['presupuestos'])->keyBy('descripcion');
        $this->assertSame(['monto' => 60.0, 'monto_base' => 0.0, 'recibido' => 60.0, 'gastado' => 60.0, 'queda' => 0.0],
            collect($presupuestos['Entretenimiento'])->only(['monto', 'monto_base', 'recibido', 'gastado', 'queda'])->all());
        $this->assertSame(440.0, $presupuestos['Súper']['monto']);
        $this->assertSame([['nombre' => 'Compras', 'gastado' => 0.0, 'comprometido' => 20.0]], $this->fuera($d['tope']), 'Entretenimiento ya no está fuera');
        $this->assertSame(800.0, $d['tope']['presupuestado'], 'lo movido no cambia el total');
        $this->assertCuadra($d);

        // Un segundo gasto en Entretenimiento ya revisa su presupuesto como cualquier otro
        $this->gastoRevisado('Entretenimiento', 10)->assertStatus(422)->assertJsonPath('presupuesto.sin_presupuesto', false);
    }

    public function test_guardarlo_sin_presupuesto_lo_deja_fuera_y_el_tope_lo_cuenta(): void
    {
        $this->gastoRevisado('Entretenimiento', 60, ['presupuesto' => 'pasarme'])->assertOk();

        $d = app(DashboardService::class)->resumen($this->user);
        $this->assertNull(collect($d['presupuestos'])->firstWhere('descripcion', 'Entretenimiento'));
        $this->assertSame('Entretenimiento', $d['tope']['fuera'][0]['nombre']);
        $this->assertCuadra($d);
    }

    public function test_los_prestamos_no_preguntan_por_presupuesto(): void
    {
        $this->gastoRevisado('Préstamos y deudas', 300)->assertOk();
    }

    public function test_cada_categoria_fuera_de_presupuesto_trae_sus_gastos_y_lo_por_pagar(): void
    {
        $this->gasto('Entretenimiento', 25);
        $this->gasto('Entretenimiento', 35);

        $fuera = collect(app(DashboardService::class)->resumen($this->user)['tope']['fuera'])->keyBy('nombre');

        $entretenimiento = $fuera['Entretenimiento'];
        $this->assertSame($this->cat['Entretenimiento']->id, $entretenimiento['categoria_id']);
        $this->assertSame([25.0, 35.0], collect($entretenimiento['movimientos'])->pluck('monto')->sort()->values()->all());
        $this->assertSame(60.0, round(collect($entretenimiento['movimientos'])->sum('monto'), 2), 'la lista suma lo gastado');
        $this->assertSame(['efectivo', 'Efectivo'], [$entretenimiento['movimientos'][0]['clase'], $entretenimiento['movimientos'][0]['origen']]);
        $this->assertSame([], $entretenimiento['pendientes']);
        $this->assertSame(['Cuota de Compras'], collect($fuera['Compras']['pendientes'])->pluck('descripcion')->all());
        $this->assertSame(20.0, $fuera['Compras']['pendientes'][0]['monto']);

        // En la pantalla, cada gasto lleva a su movimiento y lo por pagar a su compromiso
        $movimiento = $this->user->movimientosEfectivo()->where('descripcion', 'Gasto de Entretenimiento')->first();
        $this->get(route('dashboard'))->assertOk()
            ->assertSee(route('movimientos.ver', ['efectivo', $movimiento->id, 'desde' => 'inicio']), false)
            ->assertSee(route('compromisos.show', $fuera['Compras']['pendientes'][0]['id']), false);
        $this->followingRedirects()->get(route('movimientos.ver', ['efectivo', $movimiento->id, 'desde' => 'inicio']))->assertOk()->assertSee('Volver al inicio');
    }

    public function test_la_pantalla_muestra_lo_por_pagar_y_lo_que_esta_fuera_de_presupuesto(): void
    {
        $this->gasto('Entretenimiento', 60);

        $this->get(route('dashboard'))->assertOk()
            ->assertSee('por pagar de')
            ->assertSee('Fuera de presupuesto:')
            ->assertSee('Entretenimiento')
            ->assertSee('Sin asignar a un presupuesto:')
            ->assertSee('(sin presupuesto)');
    }
}
