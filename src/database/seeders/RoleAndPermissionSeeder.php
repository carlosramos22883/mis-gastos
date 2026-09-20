<?php

namespace Database\Seeders;

use App\Models\Moneda;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleAndPermissionSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Crear Permisos
        $permissions = [
            // Perfil (los que ya teníamos)
            'profile.view',
            'profile.update',
            'profile.avatar.update',
            'profile.password.update',
            'profile.delete',

            // Gestión de Usuarios (NUEVOS)
            'users.view',
            'users.create',
            'users.edit',
            'users.delete',

            // Gestión de Roles y Permisos (NUEVOS)
            'roles.view',
            'roles.create',
            'roles.edit',
            'roles.delete',

            // Gestión de monedas
            'monedas.view',
            'monedas.create',
            'monedas.edit',
            'monedas.delete',

            // Gestión de bancos
            'bancos.view',
            'bancos.create',
            'bancos.edit',
            'bancos.delete',

            // Gestión de tipos de cuenta
            'tipos_cuenta.view',
            'tipos_cuenta.create',
            'tipos_cuenta.edit',
            'tipos_cuenta.delete',

            // Marcas
            'marcas-red.view',
            'marcas-red.create',
            'marcas-red.edit',
            'marcas-red.delete',
            'categorias.view',
            'categorias.create',
            'categorias.edit',
            'categorias.delete',
            'efectivo.view',
            'efectivo.create',
            'efectivo.edit',
            'efectivo.delete',
            'compromisos.view',
            'compromisos.create',
            'compromisos.edit',
            'compromisos.delete',
            'compromisos.export',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        // 2. Crear Rol Administrador y asignarle todos los permisos
        $adminRole = Role::firstOrCreate(['name' => 'Administrador']);
        $adminRole->givePermissionTo(Permission::all());

        // 3. Rol Usuario Básico (Solo puede ver y editar su propio perfil, NO gestión)
        $userRole = Role::firstOrCreate(['name' => 'Usuario']);
        $userRole->givePermissionTo([
            'profile.view',
            'profile.update',
            'profile.avatar.update',
            'profile.password.update', 'categorias.view', 'categorias.create', 'categorias.edit', 'categorias.delete',
            'efectivo.view', 'efectivo.create', 'efectivo.edit', 'efectivo.delete',
            'compromisos.view', 'compromisos.create', 'compromisos.edit', 'compromisos.delete', 'compromisos.export',
        ]);

        // 4. Crear Usuario Administrador por defecto

        // 4. Crear Usuario Administrador por defecto CON MONEDA USD
        // Buscamos la moneda USD que fue creada por el MonedaSeeder
        $monedaUsd = Moneda::where('codigo', 'USD')->first();

        $adminPassword = env('ADMIN_PASSWORD');
        if (blank($adminPassword)) {
            throw new RuntimeException('ADMIN_PASSWORD debe estar definido antes de ejecutar los seeders.');
        }

        $adminUser = User::firstOrCreate(
            ['email' => 'admin@misgastos.com'],
            [
                'name' => 'Administrador del Sistema',
                'password' => Hash::make($adminPassword),
                'email_verified_at' => now(),
                // Asignamos el ID de la moneda USD
                'moneda_preferida' => $monedaUsd ? $monedaUsd->id : null,
                'fecha_corte_dia' => 31,
                'zona_horaria' => 'America/El_Salvador',
            ]
        );

        // Asignar rol al usuario admin
        $adminUser->assignRole('Administrador');
    }
}
