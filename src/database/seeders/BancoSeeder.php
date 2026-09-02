<?php

namespace Database\Seeders;

use App\Models\Banco;
use Illuminate\Database\Seeder;

class BancoSeeder extends Seeder
{
    public function run(): void
    {
        $bancos = [
            ['nombre' => 'Banco Agrícola', 'logo' => null, 'activo' => true],
            ['nombre' => 'Banco Davivienda', 'logo' => null, 'activo' => true],
            ['nombre' => 'Banco de América Central (BAC)', 'logo' => null, 'activo' => true],
        ];

        foreach ($bancos as $banco) {
            Banco::updateOrCreate(
                ['nombre' => $banco['nombre']],
                $banco
            );
        }
    }
}
