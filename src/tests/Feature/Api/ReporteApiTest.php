<?php

namespace Tests\Feature\Api;

use App\Models\CategoriaPersonal;
use App\Models\User;
use App\Services\TarjetaService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ReporteApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 12:00:00');
        $this->user = User::factory()->create(['email_verified_at' => now(), 'fecha_corte_dia' => 22, 'zona_horaria' => 'America/El_Salvador', 'onboarding_completed' => true]);
        $this->user->givePermissionTo(Permission::firstOrCreate(['name' => 'reportes.view', 'guard_name' => 'web']));
        $cat = fn ($nombre, $tipo) => CategoriaPersonal::create(['hogar_id' => $this->user->hogar_id, 'user_id' => $this->user->id, 'nombre' => $nombre, 'tipo' => $tipo, 'color' => '#16A34A', 'activo' => true]);
        $this->user->movimientosEfectivo()->create(['categoria_personal_id' => $cat('Saldo inicial', 'ingreso')->id, 'descripcion' => 'Saldo inicial', 'monto' => 100, 'fecha' => '2026-09-01', 'tipo' => 'ingreso']);
        $this->user->movimientosEfectivo()->create(['categoria_personal_id' => $cat('Comida', 'egreso')->id, 'descripcion' => 'Pupusas', 'monto' => 12.5, 'fecha' => '2026-10-02', 'tipo' => 'egreso']);
        Sanctum::actingAs($this->user);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_reportes_por_api(): void
    {
        $this->getJson('/api/reportes/ejecucion')->assertOk()
            ->assertJsonPath('titulo', 'Ejecución financiera')
            ->assertJsonPath('periodo.desde', '2026-09-23')->assertJsonPath('periodo.anterior', '2026-09-22')->assertJsonPath('periodo.siguiente', null)
            ->assertJsonPath('periodo.texto', 'Ciclo del 23/09/2026 al 22/10/2026')
            ->assertJsonPath('data.totales.gastos', 12.5)->assertJsonPath('data.efectivo.saldo_final', 87.5);

        $this->getJson('/api/reportes/gastos?desde=2026-09-01&hasta=2026-10-22')->assertOk()
            ->assertJsonCount(2, 'data.ciclos')->assertJsonPath('data.categorias.0.nombre', 'Comida')->assertJsonPath('periodo.es_ciclo', false);
        $this->getJson('/api/reportes/prestamos')->assertOk()->assertJsonPath('data.prestamos', []);
        $this->getJson('/api/reportes/gastos?desde=2026-10-10&hasta=2026-10-01')->assertUnprocessable()->assertJsonValidationErrors('hasta');
        $this->getJson('/api/reportes/inventado')->assertNotFound();
    }

    public function test_estado_de_cuenta_devuelve_los_cortes_de_la_tarjeta(): void
    {
        $tarjeta = $this->user->tarjetasCredito()->create(['nombre' => 'Promerica', 'limite_credito' => 500, 'dia_corte' => 9, 'dia_pago' => 6, 'fecha_registro' => '2026-09-01', 'color' => '#00A651']);
        app(TarjetaService::class)->generarEstados($tarjeta);
        $corte = $tarjeta->estadosCuenta()->orderByDesc('fecha_corte')->firstOrFail();

        $this->getJson("/api/reportes/estado-cuenta?tarjeta={$tarjeta->id}")->assertOk()
            ->assertJsonPath('data', null)->assertJsonPath('cortes.0.id', $corte->id)->assertJsonPath('cortes.0.fecha_corte', '2026-10-09');
        $this->getJson("/api/reportes/estado-cuenta?tarjeta={$tarjeta->id}&estado={$corte->id}")->assertOk()
            ->assertJsonPath('data.tarjeta.nombre', 'Promerica')->assertJsonPath('data.fecha_corte', '2026-10-09');

        // Corte de otra tarjeta u hogar: no se acepta
        $ajena = User::factory()->create()->tarjetasCredito()->create(['nombre' => 'X', 'limite_credito' => 100, 'dia_corte' => 1, 'dia_pago' => 20, 'fecha_registro' => '2026-10-01', 'color' => '#000000']);
        $this->getJson("/api/reportes/estado-cuenta?tarjeta={$ajena->id}")->assertJsonValidationErrors('tarjeta');
    }

    public function test_descargar_exige_permiso(): void
    {
        $this->get('/api/reportes/ejecucion/exportar?formato=pdf')->assertForbidden();

        $this->user->givePermissionTo(Permission::firstOrCreate(['name' => 'reportes.export', 'guard_name' => 'web']));
        $this->get('/api/reportes/ejecucion/exportar?formato=pdf')->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->get('/api/reportes/gastos/exportar?formato=xlsx')->assertOk()->assertDownload('reporte_gastos_2026-10-22.xlsx');
    }
}
