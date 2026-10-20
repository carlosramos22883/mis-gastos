<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAHogar;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Lo prestado a un deudor. Pagado = suma de sus pagos; pendiente = monto − pagado (no se guarda). */
#[Fillable(['user_id', 'deudor_id', 'descripcion', 'comentario', 'monto', 'fecha', 'origen', 'movimiento_efectivo_id', 'movimiento_cuenta_id', 'movimiento_tarjeta_id'])]
class Deuda extends Model
{
    use PerteneceAHogar;

    public const ACTIVA = 'activa';

    public const PARCIAL = 'parcial';

    public const PAGADA = 'pagada';

    protected $table = 'deudas';

    protected $casts = ['monto' => 'decimal:2', 'fecha' => 'date'];

    public function deudor(): BelongsTo
    {
        return $this->belongsTo(Deudor::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function pagos(): HasMany
    {
        return $this->hasMany(DeudaPago::class); // sin orden: se usa para sumar (PostgreSQL no admite ORDER BY con agregados)
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

    /** Requiere pagos_sum_monto (withSum) o carga los pagos. */
    public function pagado(): float
    {
        return round((float) ($this->pagos_sum_monto ?? $this->pagos()->sum('monto')), 2);
    }

    public function pendiente(): float
    {
        return round((float) $this->monto - $this->pagado(), 2);
    }

    public function estado(): string
    {
        $pagado = $this->pagado();

        return $pagado <= 0 ? self::ACTIVA : ($pagado >= (float) $this->monto ? self::PAGADA : self::PARCIAL);
    }
}
