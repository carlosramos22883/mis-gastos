<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['ingreso_id', 'nombre', 'tipo', 'monto', 'prestamo_pago_id'])]
class IngresoLinea extends Model
{
    protected $table = 'ingreso_lineas';

    protected $casts = ['monto' => 'decimal:2'];

    public function prestamoPago(): BelongsTo
    {
        return $this->belongsTo(PrestamoPago::class);
    }
}
