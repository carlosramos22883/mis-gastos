<?php

namespace App\Services;

use App\Models\ListaCompraItem;
use App\Models\Precio;
use App\Models\Producto;
use App\Models\Tienda;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Historial de precios por producto y tienda. Nunca se pisa un precio: cada uno queda con su fecha y su origen.
 * Un precio que viene de una compra (lista) se corrige si se corrige en la lista, y se borra si la cosa sale del carrito.
 */
class PrecioService
{
    public function __construct(private readonly DespensaService $despensa) {}

    public function anotar(User $user, Producto $producto, Tienda $tienda, array $data): Precio
    {
        return Precio::create([
            'user_id' => $user->id, 'producto_id' => $producto->id, 'tienda_id' => $tienda->id,
            'precio' => $data['precio'], 'unidades' => $data['unidades'] ?? 1, 'origen' => 'anotado',
            'fecha' => $data['fecha'] ?? $this->despensa->hoy($user)->toDateString(),
        ]);
    }

    public function ultimo(Producto $producto, Tienda $tienda): ?Precio
    {
        return Precio::where('producto_id', $producto->id)->where('tienda_id', $tienda->id)
            ->orderByDesc('fecha')->orderByDesc('id')->first();
    }

    /** El precio más reciente en cada tienda, del más barato por unidad al más caro. @return Collection<int, Precio> */
    public function ultimosPorTienda(Producto $producto): Collection
    {
        return Precio::with('tienda')->where('producto_id', $producto->id)->orderByDesc('fecha')->orderByDesc('id')->get()
            ->unique('tienda_id')->sortBy(fn (Precio $p) => $p->porUnidad())->values();
    }

    /** @return Collection<int, Precio> */
    public function historial(Producto $producto, int $limite = 30): Collection
    {
        return Precio::with('tienda')->where('producto_id', $producto->id)->orderByDesc('fecha')->orderByDesc('id')->limit($limite)->get();
    }

    /** Mantiene el precio de una cosa comprada en la lista igual al historial (o lo borra si ya no aplica). */
    public function sincronizarCompra(ListaCompraItem $item, User $user): void
    {
        $aplica = $item->comprado && $item->producto_id && $item->tienda_id && $item->precio !== null;
        $existente = Precio::where('lista_compra_item_id', $item->id)->first();
        if (! $aplica) {
            $existente?->delete();

            return;
        }
        $datos = ['producto_id' => $item->producto_id, 'tienda_id' => $item->tienda_id, 'precio' => $item->precio, 'unidades' => 1, 'origen' => 'compra'];
        if ($existente) {
            $existente->update($datos);
        } else {
            Precio::create($datos + ['user_id' => $user->id, 'lista_compra_item_id' => $item->id, 'fecha' => $this->despensa->hoy($user)->toDateString()]);
        }
    }

    /**
     * Para la lista: el último precio por unidad en cada tienda activa (del más barato al más caro) con su antigüedad.
     *
     * @return list<array{tienda: string, tienda_id: int, por_unidad: float, cuando: string, viejo: bool, origen: string}>
     */
    public function comparacion(Producto $producto, User $user, int $limite = 4): array
    {
        $hoy = $this->despensa->hoy($user)->startOfDay();

        return $this->ultimosPorTienda($producto)->filter(fn (Precio $p) => $p->tienda?->activo)->take($limite)
            ->map(function (Precio $p) use ($hoy) {
                $dias = (int) $p->fecha->copy()->startOfDay()->diffInDays($hoy, false);

                return ['tienda' => $p->tienda->nombre, 'tienda_id' => $p->tienda_id, 'por_unidad' => round($p->porUnidad(), 2),
                    'cuando' => match (true) {
                        $dias <= 0 => 'hoy', $dias === 1 => 'ayer', default => $p->fecha->format('d/m')
                    },
                    'viejo' => $dias > 7, 'origen' => $p->origen];
            })->values()->all();
    }
}
