<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** Estado de cuenta de un corte: su detalle (items), el saldo a favor del corte anterior (≤ 0), los intereses
 * bonificables que el banco aparta del disponible hasta que se paga completo y el compromiso con el que se paga. */
#[Fillable(['tarjeta_credito_id', 'fecha_inicio', 'fecha_corte', 'fecha_limite_pago', 'saldo_anterior', 'total', 'intereses_bonificables'])]
class EstadoCuentaTarjeta extends Model
{
    protected $table = 'estados_cuenta_tarjeta';

    protected function casts(): array
    {
        return ['fecha_inicio' => 'date', 'fecha_corte' => 'date', 'fecha_limite_pago' => 'date', 'saldo_anterior' => 'decimal:2', 'total' => 'decimal:2', 'intereses_bonificables' => 'decimal:2'];
    }

    public function tarjeta(): BelongsTo
    {
        return $this->belongsTo(TarjetaCredito::class, 'tarjeta_credito_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(EstadoCuentaItem::class);
    }

    public function compromiso(): HasOne
    {
        return $this->hasOne(Compromiso::class);
    }
}
