<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Proyecciones: escenarios guardados ("tomo el préstamo y vendo el Kia") que se simulan sobre los datos reales del
 * hogar. "datos" guarda la configuración: modo de las tarjetas, qué hacer con el sobrante y las palancas.
 */
return new class extends Migration
{
    private const PERMISOS = ['proyecciones.view', 'proyecciones.create', 'proyecciones.edit', 'proyecciones.delete'];

    public function up(): void
    {
        Schema::create('escenarios_proyeccion', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hogar_id')->constrained('hogares')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('nombre', 100);
            $table->json('datos');
            $table->timestamps();
        });

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
        Schema::dropIfExists('escenarios_proyeccion');
        Permission::whereIn('name', self::PERMISOS)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
