<?php

namespace App\Services;

use App\Models\ListaCompra;
use App\Models\ListaCompraItem;
use App\Models\Precio;
use App\Models\Tienda;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Ruta de compra: en qué tiendas comprar lo que falta de la lista para gastar menos, contando lo que cuesta ir a una
 * tienda más (gasolina, tiempo, parqueo: lo pone el usuario).
 *
 *  - Precio de cada cosa en cada tienda activa = su último precio por unidad (en línea, anotado o de una compra), con
 *    su antigüedad. Una tienda donde esa cosa "No había" en esta lista no cuenta para esa cosa.
 *  - Se prueban todas las combinaciones de tiendas (con 7 tiendas son 127): cada cosa va a la más barata de la
 *    combinación. Gana la que deja menos cosas sin precio y, entre esas, la de menor total + costo de ir × (tiendas − 1).
 *  - Se compara con la mejor de una sola tienda: cuánto se ahorra yendo a más.
 *  - Lo anotado fuera del catálogo o sin ningún precio queda aparte ("sin precio").
 */
class RutaCompraService
{
    /** Más de 7 días: el precio se muestra como viejo. */
    public const DIAS_VIEJO = 7;

    public function __construct(private readonly DespensaService $despensa) {}

    /**
     * @return array{
     *     plan: ?array{tiendas: list<array{tienda: Tienda, items: list<array>, subtotal: float}>, total: float, costo_ir: float, sin_cubrir: list<ListaCompraItem>},
     *     una_tienda: ?array{tienda: Tienda, total: float, sin_cubrir: int},
     *     ahorro: ?float, sin_precio: list<ListaCompraItem>, costo_visita: float, viejos: int
     * }
     */
    public function calcular(ListaCompra $lista, User $user, float $costoVisita): array
    {
        $hoy = $this->despensa->hoy($user)->startOfDay();
        $tiendas = $user->tiendas()->where('activo', true)->orderBy('nombre')->get()->keyBy('id');
        $items = $lista->items()->where('comprado', false)->with('producto')->get();

        // precios[item_id][tienda_id] = [por_unidad, fecha, dias]
        $precios = [];
        $sinPrecio = [];
        foreach ($items as $item) {
            $porTienda = $item->producto ? $this->ultimos($item->producto_id, $tiendas->keys())
                ->reject(fn (Precio $p) => $p->tienda_id === $item->no_habia_tienda_id)
                ->mapWithKeys(fn (Precio $p) => [$p->tienda_id => ['por_unidad' => round($p->porUnidad(), 2), 'fecha' => $p->fecha,
                    'dias' => (int) $p->fecha->copy()->startOfDay()->diffInDays($hoy, false)]])->all() : [];
            if ($porTienda === []) {
                $sinPrecio[] = $item;
            } else {
                $precios[$item->id] = $porTienda;
            }
        }
        $conPrecio = $items->filter(fn ($i) => isset($precios[$i->id]))->values();
        $candidatas = $tiendas->keys()->filter(fn ($t) => $conPrecio->contains(fn ($i) => isset($precios[$i->id][$t])))->values()->all();

        $vacio = ['plan' => null, 'una_tienda' => null, 'ahorro' => null, 'sin_precio' => $sinPrecio, 'costo_visita' => $costoVisita, 'viejos' => 0];
        if ($candidatas === []) {
            return $vacio;
        }

        $mejor = null;
        $mejorUna = null;
        $n = count($candidatas);
        for ($mascara = 1; $mascara < (1 << $n); $mascara++) {
            $elegidas = array_values(array_filter($candidatas, fn ($t, $k) => $mascara & (1 << $k), ARRAY_FILTER_USE_BOTH));
            $r = $this->evaluar($conPrecio, $precios, $elegidas);
            if (count($r['usadas']) < count($elegidas)) {
                continue; // una tienda que no aporta nada solo agrega el costo de ir
            }
            $costo = $r['total'] + $costoVisita * (count($elegidas) - 1);
            $clave = [$r['sin_cubrir'], round($costo, 2), count($elegidas)];
            if ($mejor === null || $clave < $mejor['clave']) {
                $mejor = ['clave' => $clave, 'elegidas' => $elegidas, 'r' => $r];
            }
            if (count($elegidas) === 1 && ($mejorUna === null || [$r['sin_cubrir'], $r['total']] < [$mejorUna['r']['sin_cubrir'], $mejorUna['r']['total']])) {
                $mejorUna = ['tienda' => $elegidas[0], 'r' => $r];
            }
        }

        $r = $mejor['r'];
        $plan = [];
        $viejos = 0;
        foreach ($r['asignacion'] as $tiendaId => $lineas) {
            $plan[] = ['tienda' => $tiendas[$tiendaId], 'subtotal' => round(array_sum(array_column($lineas, 'subtotal')), 2), 'items' => $lineas];
            $viejos += count(array_filter($lineas, fn ($l) => $l['viejo']));
        }
        usort($plan, fn ($a, $b) => $b['subtotal'] <=> $a['subtotal']); // la de la compra grande primero
        $costoIr = round($costoVisita * (count($plan) - 1), 2);
        $una = $mejorUna ? ['tienda' => $tiendas[$mejorUna['tienda']], 'total' => $mejorUna['r']['total'], 'sin_cubrir' => $mejorUna['r']['sin_cubrir']] : null;
        $comparables = $una && count($plan) > 1 && $una['sin_cubrir'] === $r['sin_cubrir'];

        return [
            'plan' => ['tiendas' => $plan, 'total' => $r['total'], 'costo_ir' => $costoIr,
                'sin_cubrir' => $conPrecio->filter(fn ($i) => ! in_array($i->id, $r['cubiertos'], true))->values()->all()],
            'una_tienda' => $una,
            'ahorro' => $comparables ? round($una['total'] - $r['total'], 2) : null,
            'sin_precio' => $sinPrecio,
            'costo_visita' => $costoVisita,
            'viejos' => $viejos,
        ];
    }

    /** Cada cosa a la más barata de las elegidas. */
    private function evaluar(Collection $items, array $precios, array $elegidas): array
    {
        $asignacion = [];
        $cubiertos = [];
        $total = 0.0;
        foreach ($items as $item) {
            $opciones = array_intersect_key($precios[$item->id], array_flip($elegidas));
            if ($opciones === []) {
                continue;
            }
            uasort($opciones, fn ($a, $b) => [$a['por_unidad'], $a['dias']] <=> [$b['por_unidad'], $b['dias']]);
            $tiendaId = array_key_first($opciones);
            $p = $opciones[$tiendaId];
            $subtotal = round($p['por_unidad'] * $item->cantidad, 2);
            $asignacion[$tiendaId][] = ['item' => $item, 'por_unidad' => $p['por_unidad'], 'subtotal' => $subtotal,
                'cuando' => match (true) {
                    $p['dias'] <= 0 => 'hoy', $p['dias'] === 1 => 'ayer', default => $p['fecha']->format('d/m')
                },
                'viejo' => $p['dias'] > self::DIAS_VIEJO];
            $cubiertos[] = $item->id;
            $total += $subtotal;
        }

        return ['asignacion' => $asignacion, 'usadas' => array_keys($asignacion), 'cubiertos' => $cubiertos,
            'sin_cubrir' => $items->count() - count($cubiertos), 'total' => round($total, 2)];
    }

    /** El último precio del producto en cada tienda. @return Collection<int, Precio> */
    private function ultimos(int $productoId, Collection $tiendas): Collection
    {
        return Precio::where('producto_id', $productoId)->whereIn('tienda_id', $tiendas)->orderByDesc('fecha')->orderByDesc('id')->get()->unique('tienda_id');
    }
}
