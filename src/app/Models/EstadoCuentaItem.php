<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Línea de un estado de cuenta: un movimiento completo o una cuota de una compra a plazos. */
#[Fillable(['estado_cuenta_tarjeta_id', 'movimiento_tarjeta_id', 'cuota_numero', 'monto'])]
class EstadoCuentaItem extends Model
{
    protected $table = 'estado_cuenta_items';

    protected function casts(): array
    {
        return ['monto' => 'decimal:2', 'cuota_numero' => 'integer'];
    }

    public function estadoCuenta(): BelongsTo
    {
        return $this->belongsTo(EstadoCuentaTarjeta::class, 'estado_cuenta_tarjeta_id');
    }

    public function movimiento(): BelongsTo
    {
        return $this->belongsTo(MovimientoTarjeta::class, 'movimiento_tarjeta_id');
    }
}
