<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAHogar;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un precio visto de un producto en una tienda, con su fecha. "unidades" = el paquete en que lo vende la tienda
 * (precio por unidad = precio / unidades). Origen: anotado (a mano), compra (de una lista) o en_linea (consultado).
 */
#[Fillable(['user_id', 'producto_id', 'tienda_id', 'precio', 'unidades', 'origen', 'fecha', 'lista_compra_item_id'])]
class Precio extends Model
{
    use PerteneceAHogar;

    public const ORIGENES = ['anotado' => 'Anotado', 'compra' => 'Compra', 'en_linea' => 'En línea'];

    protected $table = 'precios';

    protected function casts(): array
    {
        return ['precio' => 'decimal:2', 'unidades' => 'integer', 'fecha' => 'date'];
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }

    public function tienda(): BelongsTo
    {
        return $this->belongsTo(Tienda::class);
    }

    public function porUnidad(): float
    {
        return round((float) $this->precio / max(1, $this->unidades), 4);
    }
}
