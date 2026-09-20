<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['tarjeta_credito_id', 'fecha_inicio', 'fecha_corte', 'fecha_limite_pago', 'saldo_anterior', 'consumos', 'pagos', 'saldo_final', 'estado'])]
class EstadoCuentaTarjeta extends Model
{
    protected $table = 'estados_cuenta_tarjeta';

    protected function casts(): array
    {
        return [
            'fecha_inicio' => 'date',
            'fecha_corte' => 'date',
            'fecha_limite_pago' => 'date',
            'saldo_anterior' => 'decimal:2',
            'consumos' => 'decimal:2',
            'pagos' => 'decimal:2',
            'saldo_final' => 'decimal:2',
        ];
    }

    public function tarjeta(): BelongsTo
    {
        return $this->belongsTo(TarjetaCredito::class, 'tarjeta_credito_id');
    }

    public function movimientos(): HasMany
    {
        return $this->hasMany(MovimientoTarjeta::class, 'estado_cuenta_tarjeta_id');
    }
}
