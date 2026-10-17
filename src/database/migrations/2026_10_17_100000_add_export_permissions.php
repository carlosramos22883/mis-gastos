<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Exportar pasa a ser un permiso propio en todos los módulos que exportan
 * (antes algunos usaban "ver" y otros no validaban nada).
 */
return new class extends Migration
{
    private const NUEVOS = ['users.export', 'roles.export', 'categorias.export', 'efectivo.export'];

    public function up(): void
    {
        foreach (self::NUEVOS as $nombre) {
            Permission::firstOrCreate(['name' => $nombre, 'guard_name' => 'web']);
        }
        Role::where('name', 'Administrador')->first()?->givePermissionTo(self::NUEVOS);
        Role::where('name', 'Usuario')->first()?->givePermissionTo(['categorias.export', 'efectivo.export']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::whereIn('name', self::NUEVOS)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
