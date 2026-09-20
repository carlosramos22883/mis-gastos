<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['user_id', 'banco_id', 'marca_red_id', 'nombre', 'ultimos_digitos', 'limite_credito', 'saldo_actual', 'dia_corte', 'dia_pago', 'activo'])]
class TarjetaCredito extends Model
{
    protected $table = 'tarjetas_credito';

    protected function casts(): array
    {
        return [
            'limite_credito' => 'decimal:2',
            'saldo_actual' => 'decimal:2',
            'activo' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function banco(): BelongsTo
    {
        return $this->belongsTo(Banco::class);
    }

    public function marcaRed(): BelongsTo
    {
        return $this->belongsTo(MarcaRed::class, 'marca_red_id');
    }

    public function movimientos(): HasMany
    {
        return $this->hasMany(MovimientoTarjeta::class);
    }

    public function estadosCuenta(): HasMany
    {
        return $this->hasMany(EstadoCuentaTarjeta::class);
    }

    public function disponible(): float
    {
        return max(0, (float) $this->limite_credito - (float) $this->saldo_actual);
    }
}
