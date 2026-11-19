<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAHogar;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Lista de compras de un viaje. "terminada_at" nula = abierta (una sola por hogar). */
#[Fillable(['user_id', 'nombre', 'tienda_actual_id', 'terminada_at'])]
class ListaCompra extends Model
{
    use PerteneceAHogar;

    protected $table = 'listas_compra';

    protected function casts(): array
    {
        return ['terminada_at' => 'datetime'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(ListaCompraItem::class);
    }

    /** Donde se está comprando ahora (para «al carrito», precios y «No había»). */
    public function tiendaActual(): BelongsTo
    {
        return $this->belongsTo(Tienda::class, 'tienda_actual_id');
    }

    public function pagos(): HasMany
    {
        return $this->hasMany(CompraPago::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function abierta(): bool
    {
        return $this->terminada_at === null;
    }
}
