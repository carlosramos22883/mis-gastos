<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'tarjeta_credito_id', 'estado_cuenta_tarjeta_id', 'compromiso_id', 'tipo', 'descripcion', 'monto', 'fecha', 'cuotas', 'cuota_numero'])]
class MovimientoTarjeta extends Model
{
    protected $table = 'movimientos_tarjeta';

    protected function casts(): array
    {
        return [
            'monto' => 'decimal:2',
            'fecha' => 'date',
        ];
    }

    public function tarjeta(): BelongsTo
    {
        return $this->belongsTo(TarjetaCredito::class, 'tarjeta_credito_id');
    }

    public function estadoCuenta(): BelongsTo
    {
        return $this->belongsTo(EstadoCuentaTarjeta::class, 'estado_cuenta_tarjeta_id');
    }

    public function compromiso(): BelongsTo
    {
        return $this->belongsTo(Compromiso::class);
    }
}
