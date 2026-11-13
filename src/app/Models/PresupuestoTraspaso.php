<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAHogar;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Presupuesto que pasa de una categoría (origen) a otra (destino) en el mismo ciclo. Con movimiento: lo pidió ese
 * gasto para no pasarse (regresa si se borra o baja). Sin movimiento: se movió a mano.
 */
#[Fillable(['user_id', 'origen_id', 'destino_id', 'monto', 'movimiento_efectivo_id', 'movimiento_cuenta_id', 'movimiento_tarjeta_id'])]
class PresupuestoTraspaso extends Model
{
    use PerteneceAHogar;

    protected $table = 'presupuesto_traspasos';

    protected $casts = ['monto' => 'decimal:2'];

    public function origen(): BelongsTo
    {
        return $this->belongsTo(Compromiso::class, 'origen_id');
    }

    public function destino(): BelongsTo
    {
        return $this->belongsTo(Compromiso::class, 'destino_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function movimientoEfectivo(): BelongsTo
    {
        return $this->belongsTo(MovimientoEfectivo::class);
    }

    public function movimientoCuenta(): BelongsTo
    {
        return $this->belongsTo(MovimientoCuenta::class);
    }

    public function movimientoTarjeta(): BelongsTo
    {
        return $this->belongsTo(MovimientoTarjeta::class);
    }

    public function movimiento(): ?Model
    {
        return $this->movimientoEfectivo ?? $this->movimientoCuenta ?? $this->movimientoTarjeta;
    }
}
