<?php

namespace Tests\Feature;

use App\Models\Catalogos\ConceptoPlanilla;
use App\Models\Catalogos\TipoContacto;
use App\Models\User;
use App\Support\CatalogosAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/** Catálogos simples (tipos de contacto, emisor, préstamo, ingreso y conceptos de planilla): solo el administrador los mantiene. */
class CatalogoGenericoTest extends TestCase
{
    use RefreshDatabase;

    private function usuario(array $permisos): User
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'onboarding_completed' => true]);
        foreach ($permisos as $permiso) {
            $user->givePermissionTo(Permission::firstOrCreate(['name' => $permiso, 'guard_name' => 'web']));
        }

        return $user;
    }

    private function admin(): User
    {
        $permisos = collect(CatalogosAdmin::DEFINICIONES)->flatMap(fn ($d) => collect(['view', 'create', 'edit', 'delete'])->map(fn ($a) => "{$d['permiso']}.{$a}"));

        return $this->usuario($permisos->all());
    }

    public function test_las_pantallas_de_todos_los_catalogos_abren(): void
    {
        $admin = $this->admin();
        foreach (array_keys(CatalogosAdmin::DEFINICIONES) as $uri) {
            $this->actingAs($admin)->get(route("admin.catalogos.{$uri}.index"))->assertOk();
            $this->actingAs($admin)->get(route("admin.catalogos.{$uri}.create"))->assertOk();
        }
    }

    public function test_el_administrador_crea_edita_y_elimina_un_tipo(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->postJson(route('admin.catalogos.tipos-contacto.store'), ['nombre' => 'Vecino', 'orden' => 9, 'activo' => '1'])->assertOk();
        $tipo = TipoContacto::where('nombre', 'Vecino')->firstOrFail();
        $this->assertTrue($tipo->activo);

        $this->actingAs($admin)->postJson(route('admin.catalogos.tipos-contacto.store'), ['nombre' => 'Vecino'])->assertJsonValidationErrors('nombre');

        $this->actingAs($admin)->putJson(route('admin.catalogos.tipos-contacto.update', $tipo->id), ['nombre' => 'Vecina', 'orden' => 9])->assertOk();
        $this->assertFalse($tipo->fresh()->activo);
        $this->assertSame('Vecina', $tipo->fresh()->nombre);

        $this->actingAs($admin)->deleteJson(route('admin.catalogos.tipos-contacto.destroy', $tipo->id))->assertOk();
        $this->assertModelMissing($tipo);
    }

    public function test_un_tipo_en_uso_no_se_elimina(): void
    {
        $admin = $this->admin();
        $mascota = TipoContacto::where('nombre', 'Mascota')->firstOrFail();
        $admin->contactos()->create(['nombre' => 'Totto', 'tipo_contacto_id' => $mascota->id, 'activo' => true]);

        $this->actingAs($admin)->deleteJson(route('admin.catalogos.tipos-contacto.destroy', $mascota->id))->assertJsonValidationErrors('catalogo');
        $this->assertModelExists($mascota);
    }

    public function test_un_tipo_inactivo_no_se_puede_elegir(): void
    {
        $user = $this->usuario(['contactos.view', 'contactos.create']);
        $tipo = TipoContacto::create(['nombre' => 'Viejo', 'activo' => false]);

        $this->actingAs($user)->postJson(route('contactos.store'), ['nombre' => 'Ana', 'tipo_contacto_id' => $tipo->id])->assertJsonValidationErrors('tipo_contacto_id');
    }

    public function test_concepto_de_planilla_con_campos_extra(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->postJson(route('admin.catalogos.conceptos-planilla.store'), [
            'nombre' => 'INPEP', 'tipo' => 'descuento', 'porcentaje' => '6', 'tope' => '', 'monto' => '', 'activo' => '1',
        ])->assertOk();
        $concepto = ConceptoPlanilla::where('nombre', 'INPEP')->firstOrFail();
        $this->assertSame('descuento', $concepto->tipo);
        $this->assertNull($concepto->tope);

        $this->actingAs($admin)->postJson(route('admin.catalogos.conceptos-planilla.store'), ['nombre' => 'X', 'tipo' => 'otro', 'monto' => '1.234'])
            ->assertJsonValidationErrors(['tipo', 'monto']);
    }

    public function test_un_usuario_normal_no_toca_los_catalogos(): void
    {
        $user = $this->usuario(['contactos.view', 'contactos.create']);

        $this->actingAs($user)->get(route('admin.catalogos.tipos-contacto.index'))->assertForbidden();
        $this->actingAs($user)->postJson(route('admin.catalogos.tipos-contacto.store'), ['nombre' => 'Pirata'])->assertForbidden();
        $this->assertDatabaseMissing('tipos_contacto', ['nombre' => 'Pirata']);
    }
}
