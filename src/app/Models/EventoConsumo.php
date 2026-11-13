<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Un producto de un evento (una columna de la tabla) con su precio unitario; cada participante pidió cierta cantidad.
 * Si es compartido (ej. una bandeja para todos), el precio es el del producto completo y se divide entre quienes
 * comieron de él según sus porciones (la celda: 1 = partes iguales).
 */
#[Fillable(['descripcion', 'precio', 'compartido', 'orden'])]
class EventoConsumo extends Model
{
    protected $table = 'evento_consumos';

    protected function casts(): array
    {
        return ['precio' => 'decimal:2', 'compartido' => 'boolean'];
    }

    public function evento(): BelongsTo
    {
        return $this->belongsTo(Evento::class);
    }

    public function participantes(): BelongsToMany
    {
        return $this->belongsToMany(EventoParticipante::class, 'evento_consumo_participante')->withPivot('cantidad');
    }

    /** Cuántos se pidieron en total, o las porciones si es compartido (requiere los participantes cargados). */
    public function cantidad(): int
    {
        return (int) $this->participantes->sum('pivot.cantidad');
    }

    public function total(): float
    {
        if ($this->compartido) {
            return $this->cantidad() > 0 ? round((float) $this->precio, 2) : 0.0;
        }

        return round($this->cantidad() * (float) $this->precio, 2);
    }
}
