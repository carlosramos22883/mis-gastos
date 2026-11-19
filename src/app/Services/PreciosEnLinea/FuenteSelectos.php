<?php

namespace App\Services\PreciosEnLinea;

use App\Models\ProductoEnLinea;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Súper Selectos: no tiene búsqueda en JSON. Se lee su página de búsqueda (/products?keyword=): en cada tarjeta, el
 * precio actual (strong.precio; el de antes de una oferta va aparte) y el nombre con su productId. No da código de
 * barras. Para consultar un producto ligado se busca por su nombre y se toma la tarjeta con su productId.
 */
class FuenteSelectos implements Fuente
{
    public const BASE = 'https://www.superselectos.com';

    /** La página trae también productos promocionados: se ordenan por parecido y se quitan los que no se parecen. */
    public function buscar(string $texto): array
    {
        $palabras = array_values(array_filter(preg_split('/\s+/', self::normalizar($texto)), fn ($p) => mb_strlen($p) > 1));
        $puntaje = fn (array $t) => count(array_filter($palabras, fn ($p) => str_contains(self::normalizar($t['nombre']), $p)));
        $tarjetas = collect($this->tarjetas($this->pagina(trim($texto))))->map(fn ($t) => [$puntaje($t), $t]);
        $minimo = $palabras === [] ? 0 : max(1, (int) ceil(count($palabras) / 2));

        return $tarjetas->filter(fn ($x) => $x[0] >= $minimo)->sortByDesc(fn ($x) => $x[0])->pluck(1)->values()->all();
    }

    private static function normalizar(string $texto): string
    {
        return mb_strtolower(strtr($texto, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u', 'ñ' => 'n', 'Ñ' => 'n']));
    }

    public function consultar(ProductoEnLinea $enlace): ?array
    {
        foreach ($this->tarjetas($this->pagina($enlace->nombre)) as $t) {
            if ($t['codigo'] === $enlace->codigo) {
                return ['precio' => $t['precio'], 'nombre' => $t['nombre']];
            }
        }

        return null;
    }

    private function pagina(string $texto): string
    {
        try {
            $respuesta = Http::withHeaders(PreciosEnLineaHttp::CABECERAS)->connectTimeout(5)->timeout(12)
                ->get(self::BASE.'/products', ['keyword' => $texto]);
        } catch (ConnectionException $e) {
            throw new FuenteNoResponde('Sin conexión con la tienda.', previous: $e);
        }
        if (! $respuesta->successful()) {
            throw new FuenteNoResponde('La tienda respondió '.$respuesta->status().'.');
        }

        return $respuesta->body();
    }

    /** @return list<array{codigo: string, nombre: string, marca: null, precio: ?float, ean: null, url: string, imagen: null}> */
    private function tarjetas(string $html): array
    {
        $resultados = [];
        foreach (array_slice(explode('class="info-prod"', $html), 1) as $tarjeta) {
            $tarjeta = mb_substr($tarjeta, 0, 4000);
            if (! preg_match('/productId=(\d+)"[^>]*>([^<]+)<\/a>/', $tarjeta, $nombre)) {
                continue;
            }
            $precio = preg_match('/<strong class="precio"[^>]*>\s*\$?\s*([\d.,]+)/', $tarjeta, $m) ? round((float) str_replace(',', '', $m[1]), 2) : null;
            $resultados[$nombre[1]] ??= [
                'codigo' => $nombre[1],
                'nombre' => trim(html_entity_decode($nombre[2], ENT_QUOTES | ENT_HTML5, 'UTF-8')),
                'marca' => null,
                'precio' => $precio > 0 ? $precio : null,
                'ean' => null,
                'url' => self::BASE.'/products?productId='.$nombre[1],
                'imagen' => null,
            ];
        }

        return array_values($resultados);
    }
}
