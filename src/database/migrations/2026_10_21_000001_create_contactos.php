<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Contactos del hogar (personas, tiendas, empresas, instituciones). Los deudores pasan a ser contactos
 * con deudas, y préstamos, compromisos y movimientos pueden decir "con quién".
 */
return new class extends Migration
{
    private const PERMISOS = ['contactos.view', 'contactos.create', 'contactos.edit', 'contactos.delete'];

    private const CON_CONTACTO = ['prestamos', 'compromisos', 'compromisos_recurrentes', 'movimientos_efectivo', 'movimientos_cuenta', 'movimientos_tarjeta'];

    public function up(): void
    {
        Schema::rename('deudores', 'contactos');
        Schema::table('contactos', function (Blueprint $table) {
            $table->string('apellido', 100)->nullable()->after('nombre');
            $table->string('telefono', 30)->nullable()->after('tipo');
            $table->string('email', 150)->nullable()->after('telefono');
            $table->string('foto')->nullable()->after('email'); // disco privado: solo la ven los miembros del hogar
        });
        // El antiguo campo libre "contacto" pasa a las notas
        DB::table('contactos')->whereNotNull('contacto')->where('contacto', '!=', '')->orderBy('id')->each(function ($c) {
            DB::table('contactos')->where('id', $c->id)->update(['notas' => trim(($c->notas ? $c->notas."\n" : '').'Contacto: '.$c->contacto)]);
        });
        Schema::table('contactos', function (Blueprint $table) {
            $table->dropColumn('contacto');
            $table->dropUnique('deudores_hogar_id_nombre_unique');
            $table->unique(['hogar_id', 'nombre', 'apellido']);
        });

        Schema::table('deudas', fn (Blueprint $table) => $table->renameColumn('deudor_id', 'contacto_id'));

        foreach (self::CON_CONTACTO as $tabla) {
            Schema::table($tabla, function (Blueprint $table) {
                $table->foreignId('contacto_id')->nullable()->constrained('contactos')->nullOnDelete();
            });
        }

        foreach (self::PERMISOS as $nombre) {
            Permission::firstOrCreate(['name' => $nombre, 'guard_name' => 'web']);
        }
        foreach (['Administrador', 'Usuario'] as $rol) {
            Role::where('name', $rol)->first()?->givePermissionTo(self::PERMISOS);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        foreach (self::CON_CONTACTO as $tabla) {
            Schema::table($tabla, fn (Blueprint $table) => $table->dropConstrainedForeignId('contacto_id'));
        }
        Schema::table('deudas', fn (Blueprint $table) => $table->renameColumn('contacto_id', 'deudor_id'));
        Schema::table('contactos', function (Blueprint $table) {
            $table->dropUnique(['hogar_id', 'nombre', 'apellido']);
            $table->string('contacto', 150)->nullable();
            $table->dropColumn(['apellido', 'telefono', 'email', 'foto']);
            $table->unique(['hogar_id', 'nombre'], 'deudores_hogar_id_nombre_unique');
        });
        Schema::rename('contactos', 'deudores');
        Permission::whereIn('name', self::PERMISOS)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
