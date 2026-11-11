<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAHogar;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Escenario de una proyección (ver ProyeccionService::simular): datos = {modo_tarjetas, perdida_rotar, sobrante, palancas}.
 */
#[Fillable(['user_id', 'nombre', 'datos'])]
class EscenarioProyeccion extends Model
{
    use PerteneceAHogar;

    protected $table = 'escenarios_proyeccion';

    protected function casts(): array
    {
        return ['datos' => 'array'];
    }
}
