<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAHogar;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Pago de un préstamo con su desglose. Si salió del efectivo o de una cuenta, guarda ese egreso (y se va con él). */
#[Fillable(['user_id', 'prestamo_id', 'fecha', 'capital', 'interes', 'cargos', 'mora', 'planilla', 'origen', 'movimiento_efectivo_id', 'movimiento_cuenta_id', 'compromiso_id', 'monto_compromiso', 'comentario'])]
class PrestamoPago extends Model
{
    use PerteneceAHogar;

    protected $table = 'prestamo_pagos';

    protected $casts = ['fecha' => 'date', 'capital' => 'decimal:2', 'interes' => 'decimal:2', 'cargos' => 'decimal:2', 'mora' => 'decimal:2', 'planilla' => 'decimal:2', 'monto_compromiso' => 'decimal:2'];

    public function prestamo(): BelongsTo
    {
        return $this->belongsTo(Prestamo::class);
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

    public function compromiso(): BelongsTo
    {
        return $this->belongsTo(Compromiso::class);
    }

    public function total(): float
    {
        return round((float) $this->capital + (float) $this->interes + (float) $this->cargos + (float) $this->mora, 2);
    }
}
