<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAHogar;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Cargo que la tarjeta cobra cada corte (seguro, membresía…). Lo aplica TarjetaService al generar el estado de cuenta. */
#[Fillable(['user_id', 'tarjeta_credito_id', 'categoria_personal_id', 'descripcion', 'monto', 'desde', 'activo'])]
class CargoFijoTarjeta extends Model
{
    use PerteneceAHogar;

    protected $table = 'cargos_fijos_tarjeta';

    protected function casts(): array
    {
        return ['monto' => 'decimal:2', 'desde' => 'date', 'activo' => 'boolean'];
    }

    public function tarjeta(): BelongsTo
    {
        return $this->belongsTo(TarjetaCredito::class, 'tarjeta_credito_id');
    }

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(CategoriaPersonal::class, 'categoria_personal_id');
    }

    public function movimientos(): HasMany
    {
        return $this->hasMany(MovimientoTarjeta::class, 'cargo_fijo_id');
    }
}
