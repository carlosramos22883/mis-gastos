<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAHogar;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Una consulta de precios en línea de los productos de una lista, hecha de uno en uno. */
#[Fillable(['user_id', 'lista_compra_id', 'dia', 'productos', 'hechos', 'nuevos', 'reusados', 'fallas', 'terminada_at'])]
class ConsultaPrecios extends Model
{
    use PerteneceAHogar;

    protected $table = 'consultas_precios';

    protected function casts(): array
    {
        return ['dia' => 'date', 'productos' => 'array', 'terminada_at' => 'datetime'];
    }

    public function lista(): BelongsTo
    {
        return $this->belongsTo(ListaCompra::class, 'lista_compra_id');
    }

    public function total(): int
    {
        return count($this->productos);
    }

    public function terminada(): bool
    {
        return $this->terminada_at !== null;
    }
}
