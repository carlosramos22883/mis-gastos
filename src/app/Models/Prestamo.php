<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAHogar;
use App\Support\Color;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Préstamo que el hogar debe (a un banco o a una persona). Saldo, intereses y proyecciones en PrestamoService.
 */
#[Fillable(['user_id', 'nombre', 'tipo', 'banco_id', 'acreedor', 'monto_original', 'tasa_anual', 'tasa_mora', 'plazo_meses', 'fecha_otorgamiento',
    'dia_pago', 'cuota', 'saldo_inicial', 'fecha_saldo', 'medio_pago', 'monto_planilla', 'compromiso_recurrente_id', 'color', 'notas', 'activo'])]
class Prestamo extends Model
{
    use PerteneceAHogar;

    public const TIPOS = ['consumo' => 'Consumo / personal', 'hipotecario' => 'Hipotecario', 'vehiculo' => 'Vehículo', 'persona' => 'De una persona', 'otro' => 'Otro'];

    public const MEDIOS = ['transferencia' => 'Desde una cuenta', 'efectivo' => 'En efectivo', 'planilla' => 'Descuento de planilla', 'por_definir' => 'Por definir'];

    protected $table = 'prestamos';

    protected function casts(): array
    {
        return [
            'monto_original' => 'decimal:2', 'tasa_anual' => 'decimal:3', 'tasa_mora' => 'decimal:3', 'cuota' => 'decimal:2',
            'saldo_inicial' => 'decimal:2', 'monto_planilla' => 'decimal:2', 'fecha_otorgamiento' => 'date', 'fecha_saldo' => 'date',
            'plazo_meses' => 'integer', 'dia_pago' => 'integer', 'activo' => 'boolean',
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

    public function cargos(): HasMany
    {
        return $this->hasMany(PrestamoCargo::class);
    }

    public function pagos(): HasMany
    {
        return $this->hasMany(PrestamoPago::class); // sin orden: se usa para sumar (PostgreSQL no admite ORDER BY con agregados)
    }

    public function recurrente(): BelongsTo
    {
        return $this->belongsTo(CompromisoRecurrente::class, 'compromiso_recurrente_id');
    }

    public function nombreAcreedor(): string
    {
        return $this->banco?->nombre ?? $this->acreedor ?? '—';
    }

    public function textColor(): string
    {
        return Color::textoSobre($this->color);
    }
}
