<?php

namespace Tests\Feature;

use App\Models\CategoriaPersonal;
use App\Models\Compromiso;
use App\Models\MovimientoEfectivo;
use App\Models\MovimientoTarjeta;
use App\Models\PresupuestoTraspaso;
use App\Models\User;
use App\Services\CompromisoService;
use App\Services\PresupuestoService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Presupuestos del ciclo (23/10 al 22/11): Mascotas $50, Salud $100, Regalos $100 y Gasolina $40. Ya se gastaron $30
 * en Mascotas y $80 en Salud; un gasto nuevo de $60 en Mascotas se pasa por $40.
 */
class PresupuestoTraspasoTest extends TestCase
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
        foreach (['efectivo', 'compromisos', 'tarjetas'] as $modulo) {
            foreach (['view', 'create', 'edit', 'delete'] as $accion) {
                $this->user->givePermissionTo(Permission::firstOrCreate(['name' => "{$modulo}.{$accion}", 'guard_name' => 'web']));
            }
        }
        $this->actingAs($this->user);
        $sueldo = $this->user->categoriasPersonales()->create(['nombre' => 'Sueldo', 'tipo' => 'ingreso']);
        $this->user->movimientosEfectivo()->create(['categoria_personal_id' => $sueldo->id, 'descripcion' => 'Sueldo', 'monto' => 1000, 'fecha' => '2026-10-23', 'tipo' => 'ingreso']);

        $compromisos = app(CompromisoService::class);
        foreach (['Mascotas' => 50, 'Salud' => 100, 'Regalos' => 100, 'Gasolina' => 40] as $nombre => $monto) {
            $this->cat[$nombre] = $this->user->categoriasPersonales()->create(['nombre' => $nombre, 'tipo' => 'egreso', 'activo' => true, 'color' => '#F97316']);
            $compromisos->crearRecurrente($this->user, ['descripcion' => $nombre, 'monto' => $monto, 'medio_pago' => 'efectivo', 'categoria_personal_id' => $this->cat[$nombre]->id,
                'es_presupuesto' => true, 'frecuencia' => 'mensual', 'dia_pago' => 25, 'fecha_inicio' => '2026-10-23']);
        }
        $compromisos->sincronizar($this->user);
        $this->gasto('Mascotas', 30)->assertOk();
        $this->gasto('Salud', 80)->assertOk();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function gasto(string $categoria, float $monto, array $extra = [], ?MovimientoEfectivo $editar = null)
    {
        $datos = ['descripcion' => "Gasto de {$categoria}", 'monto' => $monto, 'fecha' => '30/10/2026', 'tipo' => 'egreso', 'categoria_personal_id' => $this->cat[$categoria]->id] + $extra;

        return $editar ? $this->putJson(route('efectivo.update', $editar), $datos) : $this->postJson(route('efectivo.store'), $datos);
    }

    private function presupuesto(string $categoria): Compromiso
    {
        return Compromiso::where('es_presupuesto', true)->where('categoria_personal_id', $this->cat[$categoria]->id)->firstOrFail();
    }

    /** @return array{0: float, 1: float} monto (con lo movido) y lo que queda */
    private function estado(string $categoria): array
    {
        $r = app(PresupuestoService::class)->resumen($this->presupuesto($categoria));

        return [$r['monto'], $r['queda']];
    }

    private function cubrir(array $montos): array
    {
        return ['presupuesto' => 'cubrir', 'traspasos' => collect($montos)->map(fn ($m, $c) => ['presupuesto_id' => $this->presupuesto($c)->id, 'monto' => $m])->values()->all()];
    }

    public function test_avisa_antes_de_guardar_y_dice_de_donde_se_puede_cubrir(): void
    {
        $this->gasto('Mascotas', 60, ['presupuesto' => 'revisar'])->assertUnprocessable()
            ->assertJsonPath('errors.presupuesto.0', 'Con este gasto te pasas del presupuesto de «Mascotas» por $ 40.00.')
            ->assertJsonPath('presupuesto.queda', '20.00')->assertJsonPath('presupuesto.excedente', '40.00')
            ->assertJsonPath('presupuesto.disponible', '160.00')->assertJsonPath('presupuesto.alcanza', true)
            // de la que más tiene a la que menos; Mascotas no se ofrece a sí misma
            ->assertJsonPath('presupuesto.opciones.*.categoria', ['Regalos', 'Gasolina', 'Salud'])
            ->assertJsonPath('presupuesto.opciones.*.disponible', ['100.00', '40.00', '20.00']);
        $this->assertSame(2, MovimientoEfectivo::where('tipo', 'egreso')->count()); // no se guardó

        // Si no se pasa, se guarda sin avisar
        $this->gasto('Mascotas', 20, ['presupuesto' => 'revisar'])->assertOk();
        $this->assertSame([50.0, 0.0], $this->estado('Mascotas'));
    }

    public function test_cubrir_con_montos_elegidos_y_el_total_no_cambia(): void
    {
        // $40 que se pasa: $30 de Regalos y $10 de Salud (no mitad y mitad)
        $this->gasto('Mascotas', 60, $this->cubrir(['Regalos' => '30.00', 'Salud' => '10.00']))->assertOk();

        $this->assertSame([90.0, 0.0], $this->estado('Mascotas'));
        $this->assertSame([70.0, 70.0], $this->estado('Regalos'));
        $this->assertSame([90.0, 10.0], $this->estado('Salud'));
        $this->assertSame(290.0, Compromiso::where('es_presupuesto', true)->get()->sum(fn ($c) => app(PresupuestoService::class)->resumen($c)['monto']));
        $this->assertSame('0.00', $this->presupuesto('Mascotas')->fresh()->saldo_pendiente);
        $this->assertSame('70.00', $this->presupuesto('Regalos')->fresh()->saldo_pendiente);
        $this->get(route('compromisos.show', $this->presupuesto('Mascotas')))->assertOk()->assertSee('Recibió de «Regalos»')->assertSee('Por el gasto «Gasto de Mascotas»', false);
    }

    public function test_lo_elegido_debe_cubrir_justo_lo_que_se_pasa(): void
    {
        $this->gasto('Mascotas', 60, $this->cubrir(['Gasolina' => '30.00']))->assertUnprocessable()
            ->assertJsonValidationErrors(['traspasos' => 'Te faltan $ 10.00']);
        $this->gasto('Mascotas', 60, $this->cubrir(['Regalos' => '45.00']))->assertUnprocessable()
            ->assertJsonValidationErrors(['traspasos' => 'Solo necesitas $ 40.00']);
        $this->gasto('Mascotas', 60, $this->cubrir(['Salud' => '25.00', 'Gasolina' => '15.00']))->assertUnprocessable()
            ->assertJsonValidationErrors(['traspasos.0.monto' => 'A «Salud» solo le quedan $ 20.00.']);
        $this->assertSame(0, PresupuestoTraspaso::count());
        $this->assertSame(2, MovimientoEfectivo::where('tipo', 'egreso')->count());
    }

    public function test_pasarme_guarda_como_antes_y_sin_revisar_tambien(): void
    {
        $this->gasto('Mascotas', 60, ['presupuesto' => 'pasarme'])->assertOk();
        $this->assertSame([50.0, -40.0], $this->estado('Mascotas'));

        // Apps anteriores (sin "presupuesto"): no se revisa
        Sanctum::actingAs($this->user);
        $this->postJson('/api/efectivo', ['descripcion' => 'Concentrado', 'monto' => 10, 'fecha' => '2026-10-30', 'tipo' => 'egreso', 'categoria_personal_id' => $this->cat['Mascotas']->id])->assertCreated();
        $this->assertSame([50.0, -50.0], $this->estado('Mascotas'));
        $this->assertSame(0, PresupuestoTraspaso::count());
    }

    public function test_si_el_gasto_baja_o_se_borra_el_presupuesto_regresa(): void
    {
        $this->gasto('Mascotas', 60, $this->cubrir(['Salud' => '20.00', 'Regalos' => '20.00']))->assertOk();
        $gasto = MovimientoEfectivo::latest('id')->firstOrFail();

        // Baja a $45: ya solo hacen falta $25; regresa lo último que se movió primero
        $this->gasto('Mascotas', 45, ['presupuesto' => 'revisar'], $gasto)->assertOk();
        $this->assertSame([75.0, 0.0], $this->estado('Mascotas'));
        $this->assertSame([95.0, 95.0], $this->estado('Regalos'));
        $this->assertSame([80.0, 0.0], $this->estado('Salud'));

        // Sube otra vez: avisa por lo que falta además de lo ya movido
        $this->gasto('Mascotas', 70, ['presupuesto' => 'revisar'], $gasto)->assertUnprocessable()->assertJsonPath('presupuesto.excedente', '50.00')
            ->assertJsonPath('presupuesto.opciones.*.disponible', ['100.00', '40.00', '20.00']); // lo que movió este gasto cuenta como disponible

        // Se borra: todo regresa
        $this->deleteJson(route('efectivo.destroy', $gasto))->assertOk();
        $this->assertSame(0, PresupuestoTraspaso::count());
        $this->assertSame([50.0, 20.0], $this->estado('Mascotas'));
        $this->assertSame([100.0, 20.0], $this->estado('Salud'));
    }

    public function test_cambiar_de_categoria_regresa_lo_movido(): void
    {
        $this->gasto('Mascotas', 60, $this->cubrir(['Regalos' => '40.00']))->assertOk();
        $gasto = MovimientoEfectivo::latest('id')->firstOrFail();
        $this->gasto('Gasolina', 30, ['presupuesto' => 'revisar'], $gasto)->assertOk();

        $this->assertSame(0, PresupuestoTraspaso::count());
        $this->assertSame([100.0, 100.0], $this->estado('Regalos'));
    }

    public function test_cubrir_a_mano_y_deshacer(): void
    {
        $this->gasto('Mascotas', 60, ['presupuesto' => 'pasarme'])->assertOk();
        $mascotas = $this->presupuesto('Mascotas');
        $this->get(route('compromisos.show', $mascotas))->assertOk()->assertSee('Este presupuesto se pasó por $ 40.00')->assertSee('Cubrir con otras categorías');

        $this->postJson(route('compromisos.cubrir', $mascotas), ['traspasos' => [['presupuesto_id' => $this->presupuesto('Regalos')->id, 'monto' => '25.00'],
            ['presupuesto_id' => $this->presupuesto('Gasolina')->id, 'monto' => '15.00']]])->assertOk();
        $this->assertSame([90.0, 0.0], $this->estado('Mascotas'));
        $this->assertSame([25.0, 25.0], $this->estado('Gasolina'));
        $this->postJson(route('compromisos.cubrir', $mascotas), ['traspasos' => [['presupuesto_id' => $this->presupuesto('Regalos')->id, 'monto' => '1.00']]])
            ->assertUnprocessable()->assertJsonValidationErrors('traspasos');

        $this->deleteJson(route('presupuesto-traspasos.destroy', PresupuestoTraspaso::where('origen_id', $this->presupuesto('Gasolina')->id)->firstOrFail()))->assertOk();
        $this->assertSame([75.0, -15.0], $this->estado('Mascotas'));
        $this->assertSame([40.0, 40.0], $this->estado('Gasolina'));
    }

    public function test_con_tarjeta_primero_el_presupuesto_y_luego_el_sobregiro(): void
    {
        $tarjeta = $this->user->tarjetasCredito()->create(['nombre' => 'Walmart', 'limite_credito' => 50, 'dia_corte' => 7, 'dia_pago' => 1, 'fecha_registro' => '2026-10-08', 'color' => '#DC2626']);
        $datos = ['tipo' => 'compra', 'descripcion' => 'Veterinario', 'monto' => '60.00', 'fecha' => '30/10/2026', 'categoria_personal_id' => $this->cat['Mascotas']->id, 'confirmar' => '0'];
        $url = route('tarjetas.movimientos.store', $tarjeta);

        $this->postJson($url, $datos + ['presupuesto' => 'revisar'])->assertUnprocessable()->assertJsonValidationErrors('presupuesto');
        $this->postJson($url, $datos + $this->cubrir(['Regalos' => '40.00']))->assertUnprocessable()->assertJsonValidationErrors('confirmar');
        $this->assertSame(0, PresupuestoTraspaso::count()); // el sobregiro no aceptado deshace todo
        $this->postJson($url, ['confirmar' => '1'] + $datos + $this->cubrir(['Regalos' => '40.00']))->assertOk();

        $this->assertSame([90.0, 0.0], $this->estado('Mascotas'));
        $this->assertSame(1, PresupuestoTraspaso::whereNotNull('movimiento_tarjeta_id')->count());
        $this->deleteJson(route('tarjetas.movimientos.destroy', [$tarjeta, MovimientoTarjeta::firstOrFail()]))->assertOk();
        $this->assertSame(0, PresupuestoTraspaso::count());
    }

    public function test_repartir_lo_que_le_queda_a_otras_categorias(): void
    {
        // A Regalos le quedan $100 que no se van a usar: $60 a Gasolina y $40 a Mascotas
        $regalos = $this->presupuesto('Regalos');
        $this->get(route('compromisos.show', $regalos))->assertOk()->assertSee('Pasar a otras categorías');
        $this->postJson(route('compromisos.repartir', $regalos), ['traspasos' => [['presupuesto_id' => $this->presupuesto('Gasolina')->id, 'monto' => '60.00'],
            ['presupuesto_id' => $this->presupuesto('Mascotas')->id, 'monto' => '40.00']]])->assertOk();
        $this->assertSame([0.0, 0.0], $this->estado('Regalos'));
        $this->assertSame([100.0, 100.0], $this->estado('Gasolina'));
        $this->assertSame([90.0, 60.0], $this->estado('Mascotas'));
        $this->assertSame(290.0, Compromiso::where('es_presupuesto', true)->get()->sum(fn ($c) => app(PresupuestoService::class)->resumen($c)['monto']));

        // No se puede pasar más de lo que le queda (ni desde una que ya no tiene)
        $this->postJson(route('compromisos.repartir', $this->presupuesto('Salud')), ['traspasos' => [['presupuesto_id' => $regalos->id, 'monto' => '25.00']]])
            ->assertUnprocessable()->assertJsonValidationErrors(['traspasos' => 'Solo le quedan $ 20.00']);

        // Se deshace: regresa a Regalos
        $this->deleteJson(route('presupuesto-traspasos.destroy', PresupuestoTraspaso::where('destino_id', $this->presupuesto('Gasolina')->id)->firstOrFail()))->assertOk();
        $this->assertSame([60.0, 60.0], $this->estado('Regalos'));
        $this->assertSame([40.0, 40.0], $this->estado('Gasolina'));

        // Solo una parte: de los $60 que le quedan, $10 a Salud
        $this->postJson(route('compromisos.repartir', $regalos), ['traspasos' => [['presupuesto_id' => $this->presupuesto('Salud')->id, 'monto' => '10.00']]])->assertOk();
        $this->assertSame([50.0, 50.0], $this->estado('Regalos'));
        $this->assertSame([110.0, 30.0], $this->estado('Salud'));

        // API
        Sanctum::actingAs($this->user);
        $this->getJson("/api/compromisos/{$regalos->id}/repartir")->assertOk()->assertJsonPath('data.disponible', '50.00')
            ->assertJsonPath('data.opciones.*.categoria', ['Salud', 'Gasolina', 'Mascotas']); // de la que menos le queda a la que más
        $this->postJson("/api/compromisos/{$regalos->id}/repartir", ['traspasos' => [['presupuesto_id' => $this->presupuesto('Salud')->id, 'monto' => '50.00']]])
            ->assertOk()->assertJsonPath('data.presupuesto.queda', '0.00')->assertJsonPath('data.presupuesto.cedido', '100.00');
        $this->getJson("/api/compromisos/{$regalos->id}/repartir")->assertOk()->assertJsonPath('data', null);
    }

    public function test_api(): void
    {
        Sanctum::actingAs($this->user);
        $datos = ['descripcion' => 'Veterinario', 'monto' => 60, 'fecha' => '2026-10-30', 'tipo' => 'egreso', 'categoria_personal_id' => $this->cat['Mascotas']->id];
        $this->postJson('/api/efectivo', $datos + ['presupuesto' => 'revisar'])->assertUnprocessable()->assertJsonPath('presupuesto.excedente', '40.00');
        $this->postJson('/api/efectivo', $datos + $this->cubrir(['Regalos' => '40.00']))->assertCreated();

        $mascotas = $this->presupuesto('Mascotas');
        $this->getJson("/api/compromisos/{$mascotas->id}")->assertOk()->assertJsonPath('data.presupuesto.monto', '90.00')
            ->assertJsonPath('data.presupuesto.monto_base', '50.00')->assertJsonPath('data.presupuesto.recibido', '40.00')
            ->assertJsonPath('data.presupuesto.traspasos.0.categoria', 'Regalos')->assertJsonPath('data.presupuesto.traspasos.0.recibe', true)
            ->assertJsonPath('data.presupuesto.traspasos.0.manual', false)->assertJsonPath('data.presupuesto.traspasos.0.gasto', 'Veterinario');
        $this->getJson("/api/compromisos/{$mascotas->id}/cubrir")->assertOk()->assertJsonPath('data', null);

        // Otro gasto que se pasa y se guarda así; luego se cubre a mano
        $this->postJson('/api/efectivo', ['monto' => 5, 'presupuesto' => 'pasarme'] + $datos)->assertCreated();
        $this->getJson("/api/compromisos/{$mascotas->id}/cubrir")->assertOk()->assertJsonPath('data.excedente', '5.00');
        $this->postJson("/api/compromisos/{$mascotas->id}/cubrir", ['traspasos' => [['presupuesto_id' => $this->presupuesto('Gasolina')->id, 'monto' => '5.00']]])
            ->assertOk()->assertJsonPath('data.presupuesto.queda', '0.00');
        $manual = PresupuestoTraspaso::whereNull('movimiento_efectivo_id')->firstOrFail();
        $this->deleteJson("/api/presupuesto-traspasos/{$manual->id}")->assertNoContent();
        $this->deleteJson('/api/presupuesto-traspasos/'.PresupuestoTraspaso::firstOrFail()->id)->assertUnprocessable(); // el de un gasto no se deshace a mano
    }
}
