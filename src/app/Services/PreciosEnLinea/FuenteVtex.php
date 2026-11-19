<?php

namespace App\Services\PreciosEnLinea;

use App\Models\ProductoEnLinea;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Tiendas en VTEX (Walmart y La Despensa de Don Juan, que comparten catálogo): búsqueda pública
 * /api/catalog_system/pub/products/search por texto (ft), por código de barras (alternateIds_Ean) o por id (productId).
 */
class FuenteVtex implements Fuente
{
    public function __construct(private readonly string $base) {}

    public function buscar(string $texto): array
    {
        $texto = trim($texto);
        $consulta = preg_match('/^\d{8,14}$/', $texto) ? ['fq' => 'alternateIds_Ean:'.$texto] : ['ft' => $texto];

        return array_map(fn (array $p) => $this->resultado($p), $this->pedir($consulta + ['_from' => 0, '_to' => 9]));
    }

    public function consultar(ProductoEnLinea $enlace): ?array
    {
        $producto = $this->pedir(['fq' => 'productId:'.$enlace->codigo])[0] ?? null;
        if (! $producto) {
            return null;
        }
        $r = $this->resultado($producto);

        return ['precio' => $r['precio'], 'nombre' => $r['nombre']];
    }

    /** @return list<array<string, mixed>> */
    private function pedir(array $consulta): array
    {
        try {
            $respuesta = Http::withHeaders(PreciosEnLineaHttp::CABECERAS)->connectTimeout(5)->timeout(10)
                ->get($this->base.'/api/catalog_system/pub/products/search', $consulta);
        } catch (ConnectionException $e) {
            throw new FuenteNoResponde('Sin conexión con la tienda.', previous: $e);
        }
        $this->revisar($respuesta);
        $datos = $respuesta->json();

        return is_array($datos) ? array_values(array_filter($datos, 'is_array')) : [];
    }

    private function revisar(Response $respuesta): void
    {
        if (! $respuesta->successful()) { // 206 también es éxito (respuesta parcial de VTEX)
            throw new FuenteNoResponde('La tienda respondió '.$respuesta->status().'.');
        }
    }

    private function resultado(array $p): array
    {
        $item = $p['items'][0] ?? [];
        $oferta = $item['sellers'][0]['commertialOffer'] ?? [];
        $precio = isset($oferta['Price']) && (float) $oferta['Price'] > 0 ? round((float) $oferta['Price'], 2) : null;

        return [
            'codigo' => (string) ($p['productId'] ?? ''),
            'nombre' => trim((string) ($p['productName'] ?? '')),
            'marca' => isset($p['brand']) ? trim((string) $p['brand']) : null,
            'precio' => $precio,
            'ean' => isset($item['ean']) && $item['ean'] !== '' ? (string) $item['ean'] : null,
            'url' => $p['link'] ?? null,
            'imagen' => $item['images'][0]['imageUrl'] ?? null,
        ];
    }
}
