<?php

namespace Tests\Feature;

use App\Models\HogarInvitacion;
use App\Models\User;
use App\Notifications\InvitacionHogarNotification;
use App\Services\CicloEfectivoService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class HogarTest extends TestCase
{
    use RefreshDatabase;

    private User $carlos;

    private User $stef;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-17 15:00:00');
        Notification::fake();
        $this->carlos = $this->usuario('Carlos', 'carlos@test.com', 22);
        $this->stef = $this->usuario('Stefany', 'stefany@test.com', 31);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function usuario(string $nombre, string $email, int $corte): User
    {
        $user = User::factory()->create([
            'name' => $nombre, 'email' => $email, 'email_verified_at' => now(), 'fecha_corte_dia' => $corte,
            'zona_horaria' => 'America/El_Salvador', 'onboarding_completed' => true,
        ]);
        foreach (['hogar.view', 'hogar.edit', 'efectivo.view', 'efectivo.create', 'efectivo.edit', 'cuentas.view'] as $permiso) {
            $user->givePermissionTo(Permission::firstOrCreate(['name' => $permiso, 'guard_name' => 'web']));
        }

        return $user;
    }

    private function efectivoInicial(User $user, float $monto): void
    {
        $saldo = $user->categoriasPersonales()->create(['nombre' => 'Saldo inicial', 'tipo' => 'ingreso']);
        $user->movimientosEfectivo()->create(['categoria_personal_id' => $saldo->id, 'descripcion' => 'Saldo inicial', 'monto' => $monto, 'fecha' => '2026-09-23', 'tipo' => 'ingreso']);
    }

    private function gastar(User $user, string $descripcion, string $monto, array $extra = [])
    {
        $categoria = $user->categoriasPersonales()->firstOrCreate(['nombre' => 'Alimentación', 'tipo' => 'egreso']);

        return $this->actingAs($user)->post(route('efectivo.store'), $extra + [
            'descripcion' => $descripcion, 'monto' => $monto, 'fecha' => '2026-10-17', 'tipo' => 'egreso', 'categoria_personal_id' => $categoria->id,
        ]);
    }

    private function unir(): void
    {
        if (! HogarInvitacion::where('estado', HogarInvitacion::PENDIENTE)->exists()) {
            $this->actingAs($this->carlos)->postJson(route('hogar.invitar'), ['email' => 'Stefany@test.com'])->assertOk();
        }
        $invitacion = HogarInvitacion::firstOrFail();
        $this->actingAs($this->stef)->postJson(route('hogar.aceptar', $invitacion))->assertOk();
        $this->carlos->refresh();
        $this->stef->refresh();
    }

    public function test_todo_usuario_nuevo_tiene_su_hogar_y_lo_administra(): void
    {
        $this->assertNotNull($this->carlos->hogar_id);
        $this->assertNotSame($this->carlos->hogar_id, $this->stef->hogar_id);
        $this->assertTrue($this->carlos->hogar->esAdministrador($this->carlos));
    }

    public function test_invitar_y_aceptar_comparte_el_fondo_y_el_ciclo(): void
    {
        $this->efectivoInicial($this->carlos, 100);

        $this->actingAs($this->carlos)->postJson(route('hogar.invitar'), ['email' => 'stefany@test.com'])->assertOk();
        Notification::assertSentTo($this->stef, InvitacionHogarNotification::class);
        $this->actingAs($this->stef)->get(route('hogar.index'))->assertOk()->assertSee('te invitó a unirte');

        $this->unir();

        $this->assertSame($this->carlos->hogar_id, $this->stef->hogar_id);
        $this->assertSame(22, $this->stef->fecha_corte_dia); // toma el ciclo del hogar
        $this->assertSame(100.0, app(CicloEfectivoService::class)->balance($this->stef));

        // Lo que registra Stef lo ve Carlos, y queda a nombre de ella
        $this->gastar($this->stef, 'Pupusas', '10.00')->assertSessionHasNoErrors();
        $this->assertSame(90.0, app(CicloEfectivoService::class)->balance($this->carlos));
        $this->assertSame($this->stef->id, $this->carlos->movimientosEfectivo()->where('descripcion', 'Pupusas')->value('user_id'));
    }

    public function test_quien_lo_hizo_se_puede_cambiar_solo_a_un_miembro(): void
    {
        $this->efectivoInicial($this->carlos, 100);
        $this->unir();
        $extrano = $this->usuario('Otro', 'otro@test.com', 22);

        $this->gastar($this->carlos, 'Super', '5.00', ['user_id' => $this->stef->id])->assertSessionHasNoErrors();
        $this->assertSame($this->stef->id, $this->carlos->movimientosEfectivo()->where('descripcion', 'Super')->value('user_id'));

        $this->gastar($this->carlos, 'Ajeno', '5.00', ['user_id' => $extrano->id])->assertSessionHasErrors('user_id');
    }

    public function test_al_aceptar_siendo_unico_miembro_sus_registros_se_suman(): void
    {
        $this->efectivoInicial($this->carlos, 100);
        $this->efectivoInicial($this->stef, 40);
        $this->stef->categoriasPersonales()->create(['nombre' => 'Salud', 'tipo' => 'egreso']);
        $hogarStef = $this->stef->hogar_id;

        $this->unir();

        $this->assertSame(140.0, app(CicloEfectivoService::class)->balance($this->carlos));
        // "Saldo inicial" se unifica y "Salud" se agrega
        $this->assertSame(1, $this->carlos->categoriasPersonales()->where('nombre', 'Saldo inicial')->count());
        $this->assertTrue($this->carlos->categoriasPersonales()->where('nombre', 'Salud')->exists());
        $this->assertDatabaseMissing('hogares', ['id' => $hogarStef]);
    }

    public function test_registros_de_otro_hogar_siguen_siendo_404(): void
    {
        $this->efectivoInicial($this->stef, 40);
        $movimiento = $this->stef->movimientosEfectivo()->first();

        $this->actingAs($this->carlos)->get(route('efectivo.edit', $movimiento))->assertNotFound();
    }

    public function test_cambiar_el_corte_lo_cambia_para_todo_el_hogar(): void
    {
        $this->unir();

        $this->carlos->update(['fecha_corte_dia' => 25]);

        $this->assertSame(25, $this->stef->fresh()->fecha_corte_dia);
    }

    public function test_solo_quien_administra_invita(): void
    {
        $this->unir();

        $this->actingAs($this->stef)->postJson(route('hogar.invitar'), ['email' => 'otro@test.com'])->assertForbidden();
        $this->actingAs($this->carlos)->postJson(route('hogar.invitar'), ['email' => 'stefany@test.com'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_salir_deja_los_registros_en_el_hogar(): void
    {
        $this->efectivoInicial($this->carlos, 100);
        $this->unir();
        $hogar = $this->carlos->hogar_id;

        $this->actingAs($this->stef)->postJson(route('hogar.salir'))->assertOk();

        $this->assertNotSame($hogar, $this->stef->fresh()->hogar_id);
        $this->assertSame(0.0, app(CicloEfectivoService::class)->balance($this->stef->fresh()));
        $this->assertSame(100.0, app(CicloEfectivoService::class)->balance($this->carlos));
    }

    public function test_si_sale_quien_administra_la_administracion_pasa_al_otro(): void
    {
        $this->unir();

        $this->actingAs($this->carlos)->postJson(route('hogar.salir'))->assertOk();

        $this->assertTrue($this->stef->fresh()->hogar->esAdministrador($this->stef));
    }

    public function test_eliminar_un_usuario_conserva_sus_registros_en_el_hogar(): void
    {
        $this->efectivoInicial($this->carlos, 100);
        $this->unir();
        $this->gastar($this->stef, 'Pupusas', '10.00')->assertSessionHasNoErrors();

        $this->stef->delete();

        $this->assertSame(90.0, app(CicloEfectivoService::class)->balance($this->carlos));
        $this->assertSame($this->carlos->id, $this->carlos->movimientosEfectivo()->where('descripcion', 'Pupusas')->value('user_id'));
    }

    public function test_las_pantallas_muestran_miembros_y_quien_lo_hizo(): void
    {
        $this->efectivoInicial($this->carlos, 100);
        $this->unir();
        $this->gastar($this->stef, 'Pupusas', '10.00')->assertSessionHasNoErrors();

        $this->actingAs($this->carlos)->get(route('hogar.index'))->assertOk()->assertSee('Stefany')->assertSee('Invitar a alguien');
        $this->actingAs($this->stef)->get(route('hogar.index'))->assertOk()->assertSee('Salir del hogar')->assertDontSee('Invitar a alguien');
        $this->actingAs($this->carlos)->get(route('efectivo.index'))->assertOk()->assertSee('Pupusas')->assertSee('title="Stefany"', false);
        $this->actingAs($this->carlos)->get(route('efectivo.create'))->assertOk()->assertSee('¿Quién lo hizo?');
    }
}
