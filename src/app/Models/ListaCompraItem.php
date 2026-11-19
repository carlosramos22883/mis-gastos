<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Una cosa de la lista: un producto del catálogo o algo anotado (producto_id nulo). */
#[Fillable(['producto_id', 'descripcion', 'cantidad', 'automatico', 'comprado', 'precio', 'tienda_id', 'no_habia_tienda_id', 'no_habia_el', 'compra_pago_id', 'extra'])]
class ListaCompraItem extends Model
{
    protected $table = 'lista_compra_items';

    protected function casts(): array
    {
        return ['cantidad' => 'integer', 'automatico' => 'boolean', 'comprado' => 'boolean', 'precio' => 'decimal:2', 'no_habia_el' => 'date', 'extra' => 'boolean'];
    }

    public function lista(): BelongsTo
    {
        return $this->belongsTo(ListaCompra::class, 'lista_compra_id');
    }

    /** Donde se compró (se fija al echarlo al carrito). */
    public function tienda(): BelongsTo
    {
        return $this->belongsTo(Tienda::class);
    }

    public function noHabiaEn(): BelongsTo
    {
        return $this->belongsTo(Tienda::class, 'no_habia_tienda_id');
    }

    public function subtotal(): ?float
    {
        return $this->precio === null ? null : round((float) $this->precio * $this->cantidad, 2);
    }

    /** El pago en que se pagó (null = aún no se paga). */
    public function pago(): BelongsTo
    {
        return $this->belongsTo(CompraPago::class, 'compra_pago_id');
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }
}
