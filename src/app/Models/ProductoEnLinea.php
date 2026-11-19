<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un producto de la despensa ligado con el suyo en una tienda en línea (fuente). Ver PreciosEnLineaService. */
#[Fillable(['producto_id', 'fuente', 'codigo', 'nombre', 'url', 'imagen', 'unidades', 'consultado_at', 'error'])]
class ProductoEnLinea extends Model
{
    protected $table = 'productos_en_linea';

    protected function casts(): array
    {
        return ['unidades' => 'integer', 'consultado_at' => 'datetime'];
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }
}
