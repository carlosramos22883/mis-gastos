<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAHogar;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Movimiento de una tarjeta. Tipos: compra (con "cuotas" > 1 si es a tasa cero), avance (a efectivo o a una
 * cuenta, vía transferencia), cargo (intereses, seguro, comisión o mora) y credito (devolución o promoción).
 * Un cargo con compromiso_id (mora) se cobra en su propio compromiso y no entra en los cortes.
 */
#[Fillable(['user_id', 'tarjeta_credito_id', 'categoria_personal_id', 'compromiso_id', 'transferencia_id', 'tipo', 'descripcion', 'comentario', 'monto', 'fecha', 'cuotas'])]
class MovimientoTarjeta extends Model
{
    use PerteneceAHogar;

    public const TIPOS = ['compra' => 'Compra', 'avance' => 'Avance', 'cargo' => 'Cargo', 'credito' => 'Crédito'];

    protected $table = 'movimientos_tarjeta';

    protected function casts(): array
    {
        return ['monto' => 'decimal:2', 'fecha' => 'date', 'cuotas' => 'integer'];
    }

    public function tarjeta(): BelongsTo
    {
        return $this->belongsTo(TarjetaCredito::class, 'tarjeta_credito_id');
    }

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(CategoriaPersonal::class, 'categoria_personal_id');
    }

    public function compromiso(): BelongsTo
    {
        return $this->belongsTo(Compromiso::class);
    }

    public function transferencia(): BelongsTo
    {
        return $this->belongsTo(Transferencia::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(EstadoCuentaItem::class);
    }

    public function esEnCuotas(): bool
    {
        return $this->tipo === 'compra' && $this->cuotas > 1;
    }
}
