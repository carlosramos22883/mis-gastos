<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const PERMISOS = ['reportes.view', 'reportes.export'];

    public function up(): void
    {
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
        Permission::whereIn('name', self::PERMISOS)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
