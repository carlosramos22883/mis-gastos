<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

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
            'monedas.view', 'monedas.create', 'monedas.edit', 'monedas.delete',

            // Gestión de bancos
            'bancos.view', 'bancos.create', 'bancos.edit', 'bancos.delete',

            // Gestión de tipos de cuenta
            'tipos_cuenta.view', 'tipos_cuenta.create', 'tipos_cuenta.edit', 'tipos_cuenta.delete',

            //Marcas
            'marcas-red.view', 'marcas-red.create', 'marcas-red.edit', 'marcas-red.delete',
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
            'profile.password.update'
        ]);

        // 4. Crear Usuario Administrador por defecto
        $adminUser = User::firstOrCreate(
            ['email' => 'admin@misgastos.com'],
            [
                'name' => 'Administrador del Sistema',
                'password' => Hash::make('Admin123!'), // Cumple con la nueva regla de contraseña fuerte
                'email_verified_at' => now(), // El admin ya viene verificado
            ]
        );

        // Asignar rol al usuario admin
        $adminUser->assignRole('Administrador');
    }
}