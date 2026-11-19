<?php

namespace App\Services\PreciosEnLinea;

use App\Models\ProductoEnLinea;

/** Conector de una tienda en línea. Sin IA: lee su búsqueda pública. */
interface Fuente
{
    /**
     * Productos de la tienda que coinciden con el texto o el código de barras (el más parecido primero).
     *
     * @return list<array{codigo: string, nombre: string, marca: ?string, precio: ?float, ean: ?string, url: ?string, imagen: ?string}>
     *
     * @throws FuenteNoResponde
     */
    public function buscar(string $texto): array;

    /**
     * El precio de hoy del producto ligado (null si la tienda ya no lo tiene o no muestra precio).
     *
     * @return array{precio: ?float, nombre: string}|null
     *
     * @throws FuenteNoResponde
     */
    public function consultar(ProductoEnLinea $enlace): ?array;
}
