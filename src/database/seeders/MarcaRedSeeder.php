<?php

namespace Database\Seeders;

use App\Models\MarcaRed;
use Illuminate\Database\Seeder;

class MarcaRedSeeder extends Seeder
{
    public function run(): void
    {
        $marcas = [
            ['nombre' => 'Visa', 'logo' => null, 'activo' => true],
            ['nombre' => 'Mastercard', 'logo' => null, 'activo' => true],
            ['nombre' => 'American Express', 'logo' => null, 'activo' => true],
        ];

        foreach ($marcas as $marca) {
            MarcaRed::updateOrCreate(
                ['nombre' => $marca['nombre']],
                $marca
            );
        }
    }
}
