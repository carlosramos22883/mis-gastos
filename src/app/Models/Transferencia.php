<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Movimiento de dinero entre efectivo, cuentas y tarjetas (avance). Crea sus dos movimientos
 * (salida y entrada) y al eliminarse se borran en cascada.
 */
#[Fillable(['user_id', 'origen_tipo', 'origen_cuenta_id', 'origen_tarjeta_id', 'destino_tipo', 'destino_cuenta_id', 'descripcion', 'monto', 'fecha'])]
class Transferencia extends Model
{
    protected $table = 'transferencias';

    protected function casts(): array
    {
        return ['monto' => 'decimal:2', 'fecha' => 'date'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function origenCuenta(): BelongsTo
    {
        return $this->belongsTo(Cuenta::class, 'origen_cuenta_id');
    }

    public function origenTarjeta(): BelongsTo
    {
        return $this->belongsTo(TarjetaCredito::class, 'origen_tarjeta_id');
    }

    public function destinoCuenta(): BelongsTo
    {
        return $this->belongsTo(Cuenta::class, 'destino_cuenta_id');
    }

    public function movimientosCuenta(): HasMany
    {
        return $this->hasMany(MovimientoCuenta::class);
    }

    public function movimientoEfectivo(): HasOne
    {
        return $this->hasOne(MovimientoEfectivo::class);
    }

    public function nombreOrigen(): string
    {
        return match ($this->origen_tipo) {
            'cuenta' => $this->origenCuenta?->nombre ?? 'Cuenta',
            'tarjeta' => $this->origenTarjeta?->nombre ?? 'Tarjeta',
            default => 'Efectivo',
        };
    }

    public function nombreDestino(): string
    {
        return $this->destino_tipo === 'cuenta' ? ($this->destinoCuenta?->nombre ?? 'Cuenta') : 'Efectivo';
    }
}
