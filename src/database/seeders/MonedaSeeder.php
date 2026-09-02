<?php

namespace Database\Seeders;

use App\Models\Moneda;
use Illuminate\Database\Seeder;

class MonedaSeeder extends Seeder
{
    public function run(): void
    {
        $monedas = [
            ['nombre' => 'Dólar Estadounidense', 'codigo' => 'USD', 'simbolo' => '$', 'activo' => true],
            ['nombre' => 'Quetzal', 'codigo' => 'GTQ', 'simbolo' => 'Q', 'activo' => true],
            ['nombre' => 'Euro', 'codigo' => 'EUR', 'simbolo' => '€', 'activo' => true],
            ['nombre' => 'Peso Mexicano', 'codigo' => 'MXN', 'simbolo' => '$', 'activo' => true],
        ];

        foreach ($monedas as $moneda) {
            Moneda::updateOrCreate(
                ['codigo' => $moneda['codigo']],
                $moneda
            );
        }
    }
}
