<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un gasto de un pago de compras (uno por categoría), ligado a su movimiento de efectivo, cuenta o tarjeta. */
#[Fillable(['categoria_personal_id', 'monto', 'movimiento_efectivo_id', 'movimiento_cuenta_id', 'movimiento_tarjeta_id'])]
class CompraPagoMovimiento extends Model
{
    protected $table = 'compra_pago_movimientos';

    protected function casts(): array
    {
        return ['monto' => 'decimal:2'];
    }

    public function pago(): BelongsTo
    {
        return $this->belongsTo(CompraPago::class, 'compra_pago_id');
    }

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(CategoriaPersonal::class, 'categoria_personal_id');
    }

    public function movimiento(): MovimientoEfectivo|MovimientoCuenta|MovimientoTarjeta|null
    {
        return match (true) {
            (bool) $this->movimiento_efectivo_id => MovimientoEfectivo::find($this->movimiento_efectivo_id),
            (bool) $this->movimiento_cuenta_id => MovimientoCuenta::find($this->movimiento_cuenta_id),
            (bool) $this->movimiento_tarjeta_id => MovimientoTarjeta::find($this->movimiento_tarjeta_id),
            default => null,
        };
    }
}
