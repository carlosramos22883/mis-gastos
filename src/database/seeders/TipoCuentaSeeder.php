<?php

namespace Database\Seeders;

use App\Models\TipoCuenta;
use Illuminate\Database\Seeder;

class TipoCuentaSeeder extends Seeder
{
    public function run(): void
    {
        $tipos = [
            ['nombre' => 'Cuenta de Ahorro', 'descripcion' => 'Cuenta que genera intereses', 'activo' => true],
            ['nombre' => 'Cuenta Planillera', 'descripcion' => 'Cuenta para depósito de salario', 'activo' => true],
            ['nombre' => 'Cuenta Corriente', 'descripcion' => 'Cuenta con chequera y mayor movimiento', 'activo' => true],
        ];

        foreach ($tipos as $tipo) {
            TipoCuenta::updateOrCreate(
                ['nombre' => $tipo['nombre']],
                $tipo
            );
        }
    }
}
