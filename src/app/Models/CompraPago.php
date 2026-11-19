<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAHogar;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Lo que se pagó en una tienda de una lista de compras: un gasto por categoría (CompraPagoMovimiento). */
#[Fillable(['user_id', 'lista_compra_id', 'tienda_id', 'origen', 'total', 'fecha'])]
class CompraPago extends Model
{
    use PerteneceAHogar;

    protected $table = 'compras_pagos';

    protected function casts(): array
    {
        return ['total' => 'decimal:2', 'fecha' => 'date'];
    }

    public function lista(): BelongsTo
    {
        return $this->belongsTo(ListaCompra::class, 'lista_compra_id');
    }

    public function tienda(): BelongsTo
    {
        return $this->belongsTo(Tienda::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function movimientos(): HasMany
    {
        return $this->hasMany(CompraPagoMovimiento::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ListaCompraItem::class);
    }
}
