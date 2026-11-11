<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** Un producto de un evento (una columna de la tabla) con su precio unitario; cada participante pidió cierta cantidad. */
#[Fillable(['descripcion', 'precio', 'orden'])]
class EventoConsumo extends Model
{
    protected $table = 'evento_consumos';

    protected function casts(): array
    {
        return ['precio' => 'decimal:2'];
    }

    public function evento(): BelongsTo
    {
        return $this->belongsTo(Evento::class);
    }

    public function participantes(): BelongsToMany
    {
        return $this->belongsToMany(EventoParticipante::class, 'evento_consumo_participante')->withPivot('cantidad');
    }

    /** Cuántos se pidieron en total (requiere los participantes cargados). */
    public function cantidad(): int
    {
        return (int) $this->participantes->sum('pivot.cantidad');
    }

    public function total(): float
    {
        return round($this->cantidad() * (float) $this->precio, 2);
    }
}
