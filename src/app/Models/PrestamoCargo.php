<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Cargo fijo de cada cuota de un préstamo (seguro de vida, de daños, IVA…). */
#[Fillable(['prestamo_id', 'nombre', 'monto'])]
class PrestamoCargo extends Model
{
    protected $table = 'prestamo_cargos';

    protected $casts = ['monto' => 'decimal:2'];
}
