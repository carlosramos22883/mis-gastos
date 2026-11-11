<?php

namespace Tests\Feature;

use App\Models\CategoriaPersonal;
use App\Models\Deuda;
use App\Models\Evento;
use App\Models\MovimientoEfectivo;
use App\Models\TarjetaCredito;
use App\Models\User;
use App\Services\EventoService;
use App\Services\MovimientoEfectivoService;
use App\Services\TarjetaService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Cena del cumpleaños de María (como una tabla): yo, Ana, Beto y María (festejada). Consumo $24.00, propina 10 % ($2.40).
 * Lo de María ($7.50 + $0.75) se reparte entre los otros tres: yo $11.00, Ana $7.70, Beto $7.70.
 */
class EventoTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private TarjetaCredito $tarjeta;

    private CategoriaPersonal $comida;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-30 20:00:00');
        $this->user = User::factory()->create(['email_verified_at' => now(), 'fecha_corte_dia' => 22, 'zona_horaria' => 'America/El_Salvador', 'onboarding_completed' => true]);
        foreach (['eventos', 'efectivo', 'cuentas', 'tarjetas', 'deudores', 'contactos'] as $modulo) {
            foreach (['view', 'create', 'edit', 'delete'] as $accion) {
                $this->user->givePermissionTo(Permission::firstOrCreate(['name' => "{$modulo}.{$accion}", 'guard_name' => 'web']));
            }
        }
        $this->actingAs($this->user);
        $this->tarjeta = $this->user->tarjetasCredito()->create(['nombre' => 'Davivienda Gold', 'limite_credito' => 1000, 'dia_corte' => 7, 'dia_pago' => 1, 'fecha_registro' => '2026-10-08', 'color' => '#DC2626']);
        $this->comida = $this->user->categoriasPersonales()->create(['nombre' => 'Comida fuera', 'tipo' => 'egreso', 'activo' => true, 'color' => '#F97316']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function datos(array $cambios = []): array
    {
        return array_replace([
            'nombre' => 'Cumpleaños de María', 'fecha' => '2026-10-30', 'lugar' => 'Pupusería La Ceiba',
            'propina_tipo' => 'porcentaje', 'propina_valor' => '10',
            'participantes' => [
                ['quien' => 'miembro:'.$this->user->id], ['quien' => 'nuevo:Ana'], ['quien' => 'nuevo:Beto'], ['quien' => 'nuevo:María', 'es_festejado' => true],
            ],
            'pagador' => 0,
            // Cuántos pidió cada uno, en el orden de los participantes (yo, Ana, Beto, María)
            'consumos' => [
                ['descripcion' => 'Pupusas', 'precio' => '2.00', 'cantidades' => [1, 1, 1, 0]],
                ['descripcion' => 'Pastel', 'precio' => '2.50', 'cantidades' => [1, 1, 1, 1]],
                ['descripcion' => 'Café', 'precio' => '3.00', 'cantidades' => [1, 0, 0, 0]],
                ['descripcion' => 'Ensalada', 'precio' => '5.00', 'cantidades' => [0, 0, 0, 1]],
            ],
            'origen' => 'tarjeta:'.$this->tarjeta->id, 'categoria_personal_id' => $this->comida->id,
        ], $cambios);
    }

    private function crear(array $cambios = []): Evento
    {
        $this->postJson(route('eventos.store'), $this->datos($cambios))->assertOk();

        return Evento::latest('id')->firstOrFail();
    }

    /** @return array<string, float> nombre => total */
    private function totales(Evento $evento): array
    {
        $resumen = app(EventoService::class)->resumen($evento->fresh());

        return $evento->fresh()->participantes->mapWithKeys(fn ($p) => [$p->nombre() => $resumen['participantes'][$p->id]['total']])->all();
    }

    public function test_reparte_consumos_propina_y_festejados_y_pago_el_hogar_con_tarjeta(): void
    {
        $evento = $this->crear();
        $resumen = app(EventoService::class)->resumen($evento);

        $this->assertSame([24.0, 2.4, 26.4], [$resumen['subtotal'], $resumen['propina'], $resumen['total']]);
        $this->assertSame([$this->user->name => 11.0, 'Ana' => 7.7, 'Beto' => 7.7, 'María' => 0.0], $this->totales($evento));
        $this->assertSame(15.4, $resumen['por_cobrar']);

        // Un solo movimiento por el total en la tarjeta, ligado al evento
        $compra = $evento->movimientoTarjeta;
        $this->assertSame(['compra', '26.40', 'Evento: Cumpleaños de María', $this->comida->id], [$compra->tipo, $compra->monto, $compra->descripcion, $compra->categoria_personal_id]);
        $this->assertSame(973.6, app(TarjetaService::class)->disponible($this->tarjeta));
        $this->assertFalse(app(TarjetaService::class)->esEditable($compra)); // se modifica desde el evento

        // Ana y Beto nos deben su parte; María (festejada) y yo no
        $this->assertSame(['Ana' => '7.70', 'Beto' => '7.70'], Deuda::with('contacto')->get()->mapWithKeys(fn ($d) => [$d->contacto->nombre => $d->monto])->sortKeys()->all());
    }

    public function test_cobrar_registra_el_ingreso_y_editar_ajusta_deudas_y_pago(): void
    {
        $evento = $this->crear();
        $ana = $evento->participantes->firstWhere(fn ($p) => $p->contacto?->nombre === 'Ana');

        $this->postJson(route('eventos.cobro.store', [$evento, $ana]), ['monto' => '7.70', 'fecha' => '30/10/2026', 'destino' => 'efectivo'])->assertOk();
        $ingreso = MovimientoEfectivo::where('tipo', 'ingreso')->firstOrFail();
        $this->assertSame(['7.70', 'Pago de Ana'], [$ingreso->monto, $ingreso->descripcion]);
        $this->assertSame(7.7, app(EventoService::class)->resumen($evento->fresh())['por_cobrar']);

        // Si su parte quedara por debajo de lo cobrado, no se permite
        $sinPastel = $this->datos();
        $sinPastel['consumos'][1]['cantidades'] = [1, 0, 1, 1];
        $this->putJson(route('eventos.update', $evento), $sinPastel)->assertUnprocessable()->assertJsonValidationErrors('consumos');

        // Subir un precio actualiza el pago (la compra de la tarjeta) y las deudas
        $masCaro = $this->datos();
        $masCaro['consumos'][0]['precio'] = '3.00'; // pupusas a $3: +1.00 a cada uno de los tres
        $this->putJson(route('eventos.update', $evento), $masCaro)->assertOk();
        $this->assertSame('29.70', $evento->fresh()->movimientoTarjeta->monto);
        $this->assertSame([$this->user->name => 12.1, 'Ana' => 8.8, 'Beto' => 8.8, 'María' => 0.0], $this->totales($evento));
        $this->assertSame(['8.80', '8.80'], Deuda::orderBy('id')->pluck('monto')->all());
        $this->assertSame(4, $evento->participantes()->count()); // no se duplicaron participantes ni contactos
        $this->assertSame(3, $this->user->contactos()->count());

        // La deuda de un evento no se borra desde el contacto
        $this->deleteJson(route('deudas.destroy', $ana->deuda_id))->assertUnprocessable();
    }

    public function test_si_pago_otra_persona_solo_se_marca_quien_ya_le_pago(): void
    {
        app(MovimientoEfectivoService::class)->registrar($this->user, ['tipo' => 'ingreso', 'descripcion' => 'Sueldo', 'monto' => 100, 'fecha' => '2026-10-30',
            'categoria_personal_id' => $this->user->categoriasPersonales()->create(['nombre' => 'Sueldo', 'tipo' => 'ingreso', 'activo' => true, 'color' => '#10B981'])->id]);
        $evento = $this->crear(['pagador' => 1, 'origen' => null]); // pagó Ana
        $this->assertNull($evento->movimiento());
        $this->assertSame(0, Deuda::count());
        $yo = $evento->participantes->firstWhere('user_id', $this->user->id);

        // Le pagué mi parte a Ana en efectivo: se registra el egreso
        $this->postJson(route('eventos.pagado.store', [$evento, $yo]), ['fecha' => '30/10/2026', 'origen' => 'efectivo', 'categoria_personal_id' => $this->comida->id])->assertOk();
        $egreso = $yo->fresh()->movimientoEfectivo;
        $this->assertSame(['egreso', '11.00', 'Cumpleaños de María: mi parte a Ana'], [$egreso->tipo, $egreso->monto, $egreso->descripcion]);
        $this->assertSame('pagado', app(EventoService::class)->resumen($evento->fresh())['participantes'][$yo->id]['estado']);

        // Beto le pagó a Ana: solo se marca
        $beto = $evento->participantes->firstWhere(fn ($p) => $p->contacto?->nombre === 'Beto');
        $this->postJson(route('eventos.pagado.store', [$evento, $beto]), ['fecha' => '30/10/2026'])->assertOk();
        $this->assertNotNull($beto->fresh()->fecha_pago);

        // No se puede cambiar quién pagó con pagos marcados; al quitar el mío se borra su egreso
        $this->putJson(route('eventos.update', $evento), $this->datos())->assertUnprocessable()->assertJsonValidationErrors('pagador');
        $this->deleteJson(route('eventos.pagado.destroy', [$evento, $yo]))->assertOk();
        $this->assertNull($yo->fresh()->fecha_pago);
        $this->assertSame(0, MovimientoEfectivo::where('tipo', 'egreso')->count());
    }

    public function test_los_centavos_no_se_pierden(): void
    {
        $servicio = app(EventoService::class);
        $this->assertSame([1 => 334, 2 => 333, 3 => 333], $servicio->repartir(1000, [1 => 1, 2 => 1, 3 => 1]));
        $this->assertSame([1 => 67, 2 => 33], $servicio->repartir(100, [1 => 2, 2 => 1]));

        // Una propina fija se divide entre todos
        $evento = $this->crear(['propina_tipo' => 'monto', 'propina_valor' => '1.00', 'participantes' => [['quien' => 'miembro:'.$this->user->id], ['quien' => 'nuevo:Ana'], ['quien' => 'nuevo:Beto']],
            'consumos' => [['descripcion' => 'Refresco', 'precio' => '1.00', 'cantidades' => [1, 1, 1]], ['descripcion' => 'Nachos', 'precio' => '6.00', 'cantidades' => [1, 0, 0]]]]);
        $this->assertSame([7.34, 1.33, 1.33], array_values($this->totales($evento)));
    }

    /** La cuenta de la hoja de cálculo del autor: 10 personas, Rocío cumpleañera, 10 % de propina; cuadra en $102.91. */
    public function test_la_cuenta_de_la_hoja_de_calculo(): void
    {
        $personas = ['Rocio', 'Juan Carlos', 'Sergio', 'Ever', 'Eduardo', 'Samuel', 'Charlie', 'Joel', 'Alvarenga', 'Mayra'];
        $participantes = array_map(fn ($n) => ['quien' => $n === 'Charlie' ? 'miembro:'.$this->user->id : "nuevo:{$n}", 'es_festejado' => $n === 'Rocio'], $personas);
        $pidio = fn (array $quienes) => array_map(fn ($n) => in_array($n, $quienes, true) ? 1 : 0, $personas);
        $evento = $this->crear(['nombre' => 'Cumpleaños de Rocío', 'participantes' => $participantes, 'pagador' => 6, 'consumos' => [
            ['descripcion' => 'Tacos Chicharrón', 'precio' => '6.95', 'cantidades' => $pidio(['Juan Carlos', 'Samuel', 'Charlie', 'Mayra'])],
            ['descripcion' => 'Tacos de Asada', 'precio' => '6.95', 'cantidades' => $pidio(['Alvarenga'])],
            ['descripcion' => 'Torta de Asada', 'precio' => '7.95', 'cantidades' => $pidio(['Sergio', 'Ever'])],
            ['descripcion' => 'Tacos de Cochinilla Pibil', 'precio' => '6.95', 'cantidades' => $pidio(['Eduardo'])],
            ['descripcion' => 'Gringa al Pastor', 'precio' => '7.50', 'cantidades' => $pidio(['Rocio'])],
            ['descripcion' => 'Tacos al Pastor', 'precio' => '6.95', 'cantidades' => $pidio(['Joel'])],
            ['descripcion' => 'Suprema', 'precio' => '2.50', 'cantidades' => $pidio(['Rocio', 'Mayra'])],
            ['descripcion' => 'Pilsener', 'precio' => '2.00', 'cantidades' => $pidio(['Juan Carlos', 'Sergio', 'Samuel', 'Charlie'])],
            ['descripcion' => 'Refresco', 'precio' => '2.00', 'cantidades' => $pidio(['Ever', 'Joel', 'Alvarenga'])],
            ['descripcion' => 'Corona', 'precio' => '2.50', 'cantidades' => $pidio(['Eduardo'])],
        ]]);

        $resumen = app(EventoService::class)->resumen($evento);
        $this->assertSame([93.55, 9.36, 102.91], [$resumen['subtotal'], $resumen['propina'], $resumen['total']]);
        $totales = $this->totales($evento);
        $this->assertSame(102.91, round(array_sum($totales), 2));
        $this->assertSame(0.0, $totales['Rocio']);
        // Como en la hoja (que redondea cada celda): 11.07, 12.17, 11.62… con diferencias de un centavo para que cuadre
        foreach (['Juan Carlos' => 11.07, 'Sergio' => 12.17, 'Ever' => 12.17, 'Eduardo' => 11.62, 'Samuel' => 11.07, 'Joel' => 11.07, 'Alvarenga' => 11.07, 'Mayra' => 11.62] as $n => $hoja) {
            $this->assertEqualsWithDelta($hoja, $totales[$n], 0.011, $n);
        }
        $this->assertEqualsWithDelta(11.07, $totales[$this->user->name], 0.011);
        // Lo de Rocío ($11.00 con su propina) se reparte entre los otros nueve
        $this->assertSame(11.0, round(collect($resumen['participantes'])->sum('festejo'), 2));
    }

    public function test_foto_de_la_cuenta_en_la_imagen_y_por_api(): void
    {
        Storage::fake('local');
        $evento = $this->crear(['foto' => UploadedFile::fake()->image('cuenta.jpg', 600, 900)]);
        $this->assertNotNull($evento->foto);
        Storage::disk('local')->assertExists($evento->foto);
        $this->get(route('eventos.foto', $evento))->assertOk();
        $this->get(route('eventos.show', $evento))->assertSee('Foto de la cuenta');
        $png = $this->get(route('eventos.imagen', $evento))->assertOk()->getContent();
        $this->assertGreaterThan(900, getimagesizefromstring($png)[1]); // la foto va debajo de la tabla

        // Quitarla al editar; subirla y quitarla por la API
        $this->putJson(route('eventos.update', $evento), $this->datos(['quitar_foto' => '1']))->assertOk();
        $this->assertNull($evento->fresh()->foto);
        Sanctum::actingAs($this->user);
        $this->post("/api/eventos/{$evento->id}/foto", ['foto' => UploadedFile::fake()->image('cuenta.png', 300, 300)], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.foto_url', route('api.eventos.foto', [$evento->id, 'v' => $evento->fresh()->versionFoto()]));
        $this->deleteJson("/api/eventos/{$evento->id}/foto")->assertOk()->assertJsonPath('data.foto_url', null);
        $this->assertSame([], Storage::disk('local')->allFiles('eventos'));
    }

    public function test_eliminar_quita_su_pago_y_sus_deudas_salvo_que_haya_cobros(): void
    {
        $evento = $this->crear();
        $ana = $evento->participantes->firstWhere(fn ($p) => $p->contacto?->nombre === 'Ana');
        $this->postJson(route('eventos.cobro.store', [$evento, $ana]), ['monto' => '5', 'fecha' => '30/10/2026', 'destino' => 'ninguno'])->assertOk();

        $this->deleteJson(route('eventos.destroy', $evento))->assertUnprocessable();
        $ana->deuda->pagos()->delete();
        $this->deleteJson(route('eventos.destroy', $evento))->assertOk();

        $this->assertSame([0, 0, 0], [Evento::count(), Deuda::count(), $this->tarjeta->movimientos()->count()]);
        $this->assertSame(1000.0, app(TarjetaService::class)->disponible($this->tarjeta));
    }

    public function test_validaciones(): void
    {
        $todosFestejados = $this->datos(['participantes' => [['quien' => 'miembro:'.$this->user->id, 'es_festejado' => true]], 'consumos' => [['descripcion' => 'Café', 'precio' => '3', 'cantidades' => [1]]]]);
        $this->postJson(route('eventos.store'), $todosFestejados)->assertUnprocessable()->assertJsonValidationErrors('participantes');
        $this->postJson(route('eventos.store'), $this->datos(['categoria_personal_id' => null]))->assertUnprocessable()->assertJsonValidationErrors('categoria_personal_id');
        $repetido = $this->datos(['participantes' => [['quien' => 'miembro:'.$this->user->id], ['quien' => 'miembro:'.$this->user->id]], 'consumos' => [['descripcion' => 'Café', 'precio' => '3', 'cantidades' => [1, 1]]]]);
        $this->postJson(route('eventos.store'), $repetido)->assertUnprocessable()->assertJsonValidationErrors('participantes.1.quien');
        $this->postJson(route('eventos.store'), $this->datos(['consumos' => [['descripcion' => 'Café', 'precio' => '3', 'cantidades' => [9 => 1]]]]))->assertUnprocessable()->assertJsonValidationErrors('consumos.0.cantidades');
        $this->postJson(route('eventos.store'), $this->datos(['consumos' => [['descripcion' => 'Café', 'precio' => '3', 'cantidades' => [0, 0, 0, 0]]]]))->assertUnprocessable()->assertJsonValidationErrors('consumos.0.cantidades');
        $this->assertSame(0, Evento::count());
    }

    public function test_pantallas_imagen_y_otro_hogar(): void
    {
        $evento = $this->crear();
        $this->get(route('eventos.index'))->assertOk()->assertSee('Cumpleaños de María')->assertSee('Falta cobrar $ 15.40');
        $this->get(route('eventos.create'))->assertOk();
        $this->get(route('eventos.edit', $evento))->assertOk();
        $this->get(route('eventos.show', $evento))->assertOk()->assertSee('Festejado')->assertSee('$ 26.40');
        $ana = $evento->participantes->firstWhere(fn ($p) => $p->contacto?->nombre === 'Ana');
        $this->get(route('eventos.cobro.create', [$evento, $ana]))->assertOk()->assertSee('Cobrar a Ana');

        $imagen = $this->get(route('eventos.imagen', $evento))->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertSame("\x89PNG", substr($imagen->getContent(), 0, 4));

        $otro = User::factory()->create(['email_verified_at' => now(), 'onboarding_completed' => true]);
        $otro->givePermissionTo('eventos.view');
        $this->actingAs($otro)->get(route('eventos.show', $evento))->assertNotFound();
    }

    public function test_api(): void
    {
        Sanctum::actingAs($this->user);
        $this->getJson('/api/eventos/opciones')->assertOk()->assertJsonPath('data.participantes.0.valor', 'miembro:'.$this->user->id);

        $id = $this->postJson('/api/eventos', $this->datos(['fecha' => '2026-10-30']))->assertCreated()
            ->assertJsonPath('data.total', '26.40')->assertJsonPath('data.por_cobrar', '15.40')
            ->assertJsonPath('data.participantes.3.estado', 'festejado')->assertJsonPath('data.consumos.0.cantidades', [1, 1, 1, 0])
            ->assertJsonPath('data.origen_valor', 'tarjeta:'.$this->tarjeta->id)->json('data.id');
        $ana = $this->getJson("/api/eventos/{$id}")->assertOk()->json('data.participantes.1');

        $this->postJson("/api/eventos/{$id}/participantes/{$ana['id']}/cobro", ['monto' => '7.70', 'fecha' => '2026-10-30', 'destino' => 'efectivo'])->assertOk()
            ->assertJsonPath('data.participantes.1.estado', 'pagado')->assertJsonPath('data.por_cobrar', '7.70');
        $this->get("/api/eventos/{$id}/imagen")->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->getJson('/api/eventos')->assertOk()->assertJsonCount(1, 'data');
    }
}
