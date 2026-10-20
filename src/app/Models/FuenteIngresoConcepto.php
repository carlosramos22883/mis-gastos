<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Descuento o adición habitual del recibo: monto fijo, o porcentaje del bruto con tope opcional. */
#[Fillable(['fuente_ingreso_id', 'nombre', 'tipo', 'monto', 'porcentaje', 'tope', 'prestamo_id', 'orden'])]
class FuenteIngresoConcepto extends Model
{
    protected $table = 'fuente_ingreso_conceptos';

    protected $casts = ['monto' => 'decimal:2', 'porcentaje' => 'decimal:3', 'tope' => 'decimal:2'];

    public function prestamo(): BelongsTo
    {
        return $this->belongsTo(Prestamo::class);
    }

    /** Monto para un bruto dado (porcentaje con tope, o el monto fijo; el monto del préstamo si está ligado y no tiene monto). */
    public function montoPara(float $bruto): float
    {
        if ($this->porcentaje !== null) {
            $monto = round($bruto * (float) $this->porcentaje / 100, 2);

            return $this->tope !== null ? min($monto, (float) $this->tope) : $monto;
        }

        return round((float) $this->monto, 2);
    }
}
