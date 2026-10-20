<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAHogar;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Un pago recibido con su desglose. El neto entró como ingreso al efectivo o a una cuenta. */
#[Fillable(['user_id', 'fuente_ingreso_id', 'fecha', 'bruto', 'neto', 'destino', 'movimiento_efectivo_id', 'movimiento_cuenta_id', 'comentario'])]
class Ingreso extends Model
{
    use PerteneceAHogar;

    protected $table = 'ingresos';

    protected $casts = ['fecha' => 'date', 'bruto' => 'decimal:2', 'neto' => 'decimal:2'];

    public function fuente(): BelongsTo
    {
        return $this->belongsTo(FuenteIngreso::class, 'fuente_ingreso_id');
    }

    public function lineas(): HasMany
    {
        return $this->hasMany(IngresoLinea::class);
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
