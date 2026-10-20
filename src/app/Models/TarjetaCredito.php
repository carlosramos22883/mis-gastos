<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAHogar;
use App\Support\Color;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tarjeta de crédito. La deuda y el disponible se calculan en TarjetaService a partir de
 * sus movimientos y de lo pagado en sus compromisos (estados de cuenta).
 */
#[Fillable(['user_id', 'banco_id', 'marca_red_id', 'nombre', 'ultimos_digitos', 'color', 'limite_credito', 'tasa_interes', 'deuda_inicial', 'dia_corte', 'dia_pago', 'fecha_registro', 'activo'])]
class TarjetaCredito extends Model
{
    use PerteneceAHogar;

    protected $table = 'tarjetas_credito';

    protected function casts(): array
    {
        return [
            'limite_credito' => 'decimal:2',
            'tasa_interes' => 'decimal:2',
            'deuda_inicial' => 'decimal:2',
            'dia_corte' => 'integer',
            'dia_pago' => 'integer',
            'fecha_registro' => 'date',
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

    public function compromisos(): HasMany
    {
        return $this->hasMany(Compromiso::class);
    }

    public function textColor(): string
    {
        return Color::textoSobre($this->color, '#1E293B');
    }
}
