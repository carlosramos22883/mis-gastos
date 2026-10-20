<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAHogar;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'deuda_id', 'monto', 'fecha', 'destino', 'movimiento_efectivo_id', 'movimiento_cuenta_id', 'comentario'])]
class DeudaPago extends Model
{
    use PerteneceAHogar;

    protected $table = 'deuda_pagos';

    protected $casts = ['monto' => 'decimal:2', 'fecha' => 'date'];

    public function deuda(): BelongsTo
    {
        return $this->belongsTo(Deuda::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function movimientoEfectivo(): BelongsTo
    {
        return $this->belongsTo(MovimientoEfectivo::class);
    }

    public function movimientoCuenta(): BelongsTo
    {
        return $this->belongsTo(MovimientoCuenta::class);
    }
}
