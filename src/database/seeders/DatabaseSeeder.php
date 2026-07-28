<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Ejecutamos el seeder de Roles, Permisos y el Usuario Admin
        $this->call([
            RoleAndPermissionSeeder::class,
        ]);
        
    }
}
