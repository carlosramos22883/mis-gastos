<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAHogar;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un pago concreto por realizar. Puede ser de una sola vez o una cuota generada
 * por un CompromisoRecurrente.
 */
#[Fillable(['user_id', 'contacto_id', 'compromiso_recurrente_id', 'categoria_personal_id', 'tarjeta_credito_id', 'estado_cuenta_tarjeta_id', 'descripcion', 'comentario', 'monto', 'saldo_pendiente', 'fecha_esperada', 'ciclo_inicio', 'numero_cuota', 'total_cuotas', 'medio_pago', 'es_presupuesto', 'estado'])]
class Compromiso extends Model
{
    use PerteneceAHogar;

    /** Estados guardados en la base de datos. */
    public const PENDIENTE = 'pendiente';

    public const PAGADO = 'pagado';

    public const CANCELADO = 'cancelado';

    /** Estados que ve el usuario (se calculan a partir del estado guardado, la fecha y el ciclo). */
    public const ESTADOS_VISUALES = [
        'pendiente' => 'Pendiente',
        'parcial' => 'Parcial',
        'vencido' => 'Vencido',
        'antiguo' => 'Antiguo',
        'pagado' => 'Pagado',
        'cancelado' => 'Cancelado',
    ];

    protected $table = 'compromisos';

    protected function casts(): array
    {
        return [
            'monto' => 'decimal:2',
            'saldo_pendiente' => 'decimal:2',
            'fecha_esperada' => 'date',
            'ciclo_inicio' => 'date',
            'numero_cuota' => 'integer',
            'total_cuotas' => 'integer',
            'es_presupuesto' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function recurrente(): BelongsTo
    {
        return $this->belongsTo(CompromisoRecurrente::class, 'compromiso_recurrente_id');
    }

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(CategoriaPersonal::class, 'categoria_personal_id');
    }

    public function tarjeta(): BelongsTo
    {
        return $this->belongsTo(TarjetaCredito::class, 'tarjeta_credito_id');
    }

    public function estadoCuenta(): BelongsTo
    {
        return $this->belongsTo(EstadoCuentaTarjeta::class, 'estado_cuenta_tarjeta_id');
    }

    public function movimientos(): HasMany
    {
        return $this->hasMany(MovimientoEfectivo::class);
    }

    /** Abonos cargados a una tarjeta (ej. pagar la Credisiman con la Atlántida). */
    public function pagosConTarjeta(): HasMany
    {
        return $this->hasMany(MovimientoTarjeta::class, 'pago_compromiso_id');
    }

    public function movimientosCuenta(): HasMany
    {
        return $this->hasMany(MovimientoCuenta::class);
    }

    public function montoPagado(): float
    {
        return round((float) $this->monto - (float) $this->saldo_pendiente, 2);
    }

    /**
     * Antiguo: quedó pendiente de un ciclo anterior. Vencido: su fecha ya pasó en el ciclo actual.
     * Parcial: tiene abonos pero aún debe algo.
     */
    public function estadoVisual(Carbon $hoy, Carbon $inicioCiclo): string
    {
        // Se comparan fechas de calendario: las fechas guardadas y las del ciclo pueden venir en zonas horarias distintas
        return match (true) {
            $this->estado !== self::PENDIENTE => $this->estado,
            // Un presupuesto no vence: se va gastando y se cierra al terminar su ciclo
            $this->es_presupuesto => (float) $this->saldo_pendiente < (float) $this->monto ? 'parcial' : 'pendiente',
            $this->ciclo_inicio && $this->ciclo_inicio->toDateString() < $inicioCiclo->toDateString() => 'antiguo',
            $this->fecha_esperada && $this->fecha_esperada->toDateString() < $hoy->toDateString() => 'vencido',
            (float) $this->saldo_pendiente < (float) $this->monto => 'parcial',
            default => 'pendiente',
        };
    }

    /** Mismo criterio que estadoVisual(), para filtrar en la base de datos. */
    public function scopeEstadoVisual(Builder $query, string $estado, Carbon $hoy, Carbon $inicioCiclo): Builder
    {
        $hoy = $hoy->toDateString();
        $inicio = $inicioCiclo->toDateString();
        $noAntiguo = fn (Builder $q) => $q->where(fn ($q) => $q->whereNull('ciclo_inicio')->orWhereDate('ciclo_inicio', '>=', $inicio));
        $noVencido = fn (Builder $q) => $q->where(fn ($q) => $q->whereNull('fecha_esperada')->orWhereDate('fecha_esperada', '>=', $hoy));

        return match ($estado) {
            'pagado', 'cancelado' => $query->where('estado', $estado),
            'antiguo' => $query->where('estado', self::PENDIENTE)->whereDate('ciclo_inicio', '<', $inicio),
            'vencido' => $query->where('estado', self::PENDIENTE)->tap($noAntiguo)->whereDate('fecha_esperada', '<', $hoy),
            'parcial' => $query->where('estado', self::PENDIENTE)->tap($noAntiguo)->tap($noVencido)->whereColumn('saldo_pendiente', '<', 'monto'),
            'pendiente' => $query->where('estado', self::PENDIENTE)->tap($noAntiguo)->tap($noVencido)->whereColumn('saldo_pendiente', '>=', 'monto'),
            default => $query,
        };
    }

    /** Con quién (persona, tienda, empresa…). */
    public function contacto(): BelongsTo
    {
        return $this->belongsTo(Contacto::class);
    }
}
