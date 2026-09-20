<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    public function up(): void
    {
        $permissions = collect(['view', 'create', 'edit', 'delete', 'export'])
            ->map(fn (string $action) => "compromisos.{$action}");

        $permissions->each(fn (string $name) => Permission::findOrCreate($name, 'web'));

        foreach (['Administrador', 'Usuario'] as $roleName) {
            if ($role = Role::where('name', $roleName)->first()) {
                $role->givePermissionTo($permissions->all());
            }
        }
    }

    public function down(): void
    {
        Permission::whereIn('name', collect(['view', 'create', 'edit', 'delete', 'export'])->map(fn ($action) => "compromisos.{$action}"))->delete();
    }
};
