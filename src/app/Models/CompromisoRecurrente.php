<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAHogar;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Regla de un compromiso que se repite (ej. "Cuota de Gaby, $150 mensual el día 5").
 * No se paga directamente: genera un Compromiso por cada fecha de pago.
 */
#[Fillable(['user_id', 'categoria_personal_id', 'descripcion', 'comentario', 'monto', 'medio_pago', 'frecuencia', 'dia_semana', 'dia_pago', 'dia_pago_2', 'fecha_inicio', 'fecha_fin', 'total_cuotas', 'cuotas_generadas', 'generado_hasta', 'activo'])]
class CompromisoRecurrente extends Model
{
    use PerteneceAHogar;

    /** Valor de dia_pago que significa "último día del mes" (28, 29, 30 o 31 según el mes). */
    public const ULTIMO_DIA = 31;

    protected $table = 'compromisos_recurrentes';

    protected function casts(): array
    {
        return [
            'monto' => 'decimal:2',
            'fecha_inicio' => 'date',
            'fecha_fin' => 'date',
            'generado_hasta' => 'date',
            'activo' => 'boolean',
            'dia_semana' => 'integer',
            'dia_pago' => 'integer',
            'dia_pago_2' => 'integer',
            'total_cuotas' => 'integer',
            'cuotas_generadas' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(CategoriaPersonal::class, 'categoria_personal_id');
    }

    public function compromisos(): HasMany
    {
        return $this->hasMany(Compromiso::class);
    }

    public function descripcionFrecuencia(): string
    {
        $dia = fn (?int $d) => $d === self::ULTIMO_DIA ? 'el último día' : "el día {$d}";
        $dias = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];

        return match ($this->frecuencia) {
            'semanal' => 'Cada '.($dias[$this->dia_semana] ?? 'semana'),
            'quincenal' => 'Quincenal: '.$dia($this->dia_pago).' y '.$dia($this->dia_pago_2),
            default => 'Mensual: '.$dia($this->dia_pago),
        };
    }
}
