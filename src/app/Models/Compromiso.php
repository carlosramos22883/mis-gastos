<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['user_id', 'descripcion', 'monto', 'fecha_esperada', 'medio_pago', 'recurrencia_tipo', 'recurrencia_intervalo', 'recurrencia_unidad', 'recurrencia_dia_semana', 'recurrencia_dia_secundario', 'recurrencia_ocurrencias', 'recurrencia_hasta', 'cuotas_pagadas', 'saldo_pendiente', 'recurrencia_indefinida', 'estado'])]
class Compromiso extends Model
{
    protected $table = 'compromisos';

    protected function casts(): array
    {
        return ['monto' => 'decimal:2', 'saldo_pendiente' => 'decimal:2', 'fecha_esperada' => 'date', 'recurrencia_hasta' => 'date', 'recurrencia_indefinida' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function movimientos(): HasMany
    {
        return $this->hasMany(MovimientoEfectivo::class);
    }
}
