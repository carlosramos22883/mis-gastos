<?php

namespace App\Services;

use App\Models\Hogar;
use App\Models\ListaCompra;
use App\Models\ListaCompraItem;
use App\Models\Producto;
use App\Models\Tienda;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Listas de compras (una por viaje, una sola abierta por hogar).
 *
 *  - Al crearla entra sola lo que falta en la despensa, con lo que hay que comprar para llegar a la cantidad ideal.
 *  - Se pueden agregar productos del catálogo (aunque no falten) o anotar cosas fuera del catálogo.
 *  - Se elige en qué tienda se está comprando: al echar algo al carrito queda con esa tienda y, si no tiene precio, con
 *    el último conocido de esa tienda (por unidad). Su precio pasa al historial de precios (PrecioService).
 *  - «No había» queda con la tienda y la fecha, y la cosa sigue pendiente por si se encuentra en otra tienda.
 *  - «Pagar aquí» (CompraPagoService) registra el gasto de lo del carrito; lo pagado ya no se cambia sin deshacer el pago.
 *  - Al terminarla, lo marcado como comprado sube en la despensa; lo no marcado queda en el historial sin comprar.
 */
class ListaCompraService
{
    public function __construct(private readonly DespensaService $despensa, private readonly PrecioService $precios) {}

    public function abierta(User $user): ?ListaCompra
    {
        return ListaCompra::delHogar($user)->whereNull('terminada_at')->latest('id')->first();
    }

    /** Abre una lista nueva con lo que falta, o devuelve la que ya está abierta. */
    public function crear(User $user): ListaCompra
    {
        return DB::transaction(function () use ($user) {
            Hogar::whereKey($user->hogar_id)->lockForUpdate()->first(); // dos toques a la vez no abren dos listas
            if ($abierta = $this->abierta($user)) {
                return $abierta;
            }
            $lista = ListaCompra::create(['user_id' => $user->id, 'nombre' => 'Compras del '.$this->despensa->hoy($user)->format('d/m/Y')]);
            $this->agregarFaltantes($lista, $user);

            return $lista;
        });
    }

    /** Agrega lo que falta y aún no está en la lista. Devuelve cuántos agregó. */
    public function agregarFaltantes(ListaCompra $lista, User $user): int
    {
        $this->asegurarAbierta($lista);
        $yaEstan = $lista->items()->whereNotNull('producto_id')->pluck('producto_id')->all();
        $faltan = $user->productos()->where('activo', true)->whereNotIn('id', $yaEstan)->get()
            ->filter(fn (Producto $p) => $this->despensa->falta($p));
        foreach ($faltan as $producto) {
            $lista->items()->create(['producto_id' => $producto->id, 'descripcion' => $producto->nombreCompleto(),
                'cantidad' => $this->despensa->porComprar($producto), 'automatico' => true]);
        }

        return $faltan->count();
    }

    /** Un producto del catálogo del hogar (producto_id) o algo anotado (descripcion). */
    public function agregar(ListaCompra $lista, User $user, array $data): ListaCompraItem
    {
        $this->asegurarAbierta($lista);
        if (! empty($data['producto_id'])) {
            $producto = $user->productos()->where('activo', true)->find($data['producto_id'])
                ?? throw ValidationException::withMessages(['producto_id' => 'Elige un producto de tu despensa.']);
            if ($lista->items()->where('producto_id', $producto->id)->exists()) {
                throw ValidationException::withMessages(['producto_id' => 'Ese producto ya está en la lista.']);
            }

            return $lista->items()->create(['producto_id' => $producto->id, 'descripcion' => $producto->nombreCompleto(),
                'cantidad' => $data['cantidad'] ?? max(1, $this->despensa->porComprar($producto))]);
        }

        return $lista->items()->create(['descripcion' => trim($data['descripcion']), 'cantidad' => $data['cantidad'] ?? 1]);
    }

    /** Dónde se está comprando ahora (una tienda activa del hogar, o ninguna). */
    public function elegirTienda(ListaCompra $lista, User $user, ?int $tiendaId): ListaCompra
    {
        $this->asegurarAbierta($lista);
        if ($tiendaId !== null && ! $user->tiendas()->where('activo', true)->whereKey($tiendaId)->exists()) {
            throw ValidationException::withMessages(['tienda_id' => 'Elige una de tus tiendas.']);
        }
        $lista->update(['tienda_actual_id' => $tiendaId]);

        return $lista;
    }

    /** Cantidad, precio, «al carrito» o «No había» de una cosa de la lista. */
    public function actualizarItem(ListaCompraItem $item, User $user, array $data): ListaCompraItem
    {
        return DB::transaction(function () use ($item, $user, $data) {
            $lista = $item->lista;
            $this->asegurarAbierta($lista);
            $this->asegurarSinPagar($item);
            $tienda = $lista->tiendaActual;

            if (array_key_exists('no_habia', $data)) {
                if ($data['no_habia'] && ! $tienda) {
                    throw ValidationException::withMessages(['no_habia' => 'Elige primero en qué tienda estás.']);
                }
                $item->fill($data['no_habia']
                    ? ['no_habia_tienda_id' => $tienda->id, 'no_habia_el' => $this->despensa->hoy($user)->toDateString(), 'comprado' => false, 'tienda_id' => null, 'precio' => null]
                    : ['no_habia_tienda_id' => null, 'no_habia_el' => null]);
            }
            if (array_key_exists('comprado', $data)) {
                if ($data['comprado'] && ! $item->comprado) {
                    $item->fill(['comprado' => true, 'tienda_id' => $tienda?->id]);
                    if ($item->precio === null && $tienda && $item->producto && ($ultimo = $this->precios->ultimo($item->producto, $tienda))) {
                        $item->precio = round($ultimo->porUnidad(), 2); // sugerido: se corrige si el del estante es otro
                    }
                } elseif (! $data['comprado']) {
                    $item->fill(['comprado' => false, 'tienda_id' => null, 'precio' => null]);
                }
            }
            if (array_key_exists('cantidad', $data)) {
                $item->cantidad = $data['cantidad'];
            }
            if (array_key_exists('precio', $data)) {
                $item->precio = $data['precio'];
            }
            $item->save();
            $this->precios->sincronizarCompra($item, $user);

            return $item;
        });
    }

    /**
     * Lo que va en el carrito: cuántas cosas, el total con precio y cuántas no tienen precio; y lo que falta pagar en
     * la tienda donde se está (por_pagar = cuántas cosas, por_pagar_total = su total) y lo ya pagado (sin los extras).
     */
    public function resumen(ListaCompra $lista): array
    {
        $items = $lista->items()->where('extra', false)->get();
        $carrito = $items->where('comprado', true);
        $porPagar = $carrito->whereNull('compra_pago_id')->filter(fn (ListaCompraItem $i) => $i->tienda_id === null || $i->tienda_id === $lista->tienda_actual_id);

        return [
            'total_items' => $items->count(),
            'en_carrito' => $carrito->count(),
            'total' => round($carrito->sum(fn (ListaCompraItem $i) => $i->subtotal() ?? 0), 2),
            'sin_precio' => $carrito->whereNull('precio')->count(),
            'por_pagar' => $porPagar->count(),
            'por_pagar_total' => round($porPagar->sum(fn (ListaCompraItem $i) => $i->subtotal() ?? 0), 2),
            'pagado' => round((float) $lista->pagos()->sum('total'), 2),
        ];
    }

    public function quitar(ListaCompraItem $item): void
    {
        $this->asegurarAbierta($item->lista);
        $this->asegurarSinPagar($item);
        $item->delete();
    }

    /** Cierra la lista y sube en la despensa lo comprado. Devuelve cuántos productos subieron. */
    public function terminar(ListaCompra $lista): int
    {
        return DB::transaction(function () use ($lista) {
            $lista = ListaCompra::whereKey($lista->id)->lockForUpdate()->firstOrFail();
            $this->asegurarAbierta($lista);
            $comprados = $lista->items()->with('producto')->where('comprado', true)->whereNotNull('producto_id')->get();
            foreach ($comprados as $item) {
                $producto = $item->producto;
                if ($producto->en_casa === null) {
                    $this->despensa->ajustar($producto, 'por_acabarse', false); // no se lleva la cuenta: solo deja de faltar
                } else {
                    $this->despensa->ajustar($producto, 'cantidad', min(999, $producto->en_casa + $item->cantidad));
                }
            }
            $lista->update(['terminada_at' => now()]);

            return $comprados->count();
        });
    }

    public function eliminar(ListaCompra $lista): void
    {
        $lista->delete();
    }

    /**
     * Lo que falta comprar y lo que ya está en el carrito, cada parte con los básicos primero, por categoría y nombre;
     * lo anotado fuera del catálogo al final.
     *
     * @return array{pendientes: Collection<int, ListaCompraItem>, comprados: Collection<int, ListaCompraItem>}
     */
    public function partes(ListaCompra $lista): array
    {
        $items = $lista->items()->with(['producto.categoria', 'producto', 'tienda', 'noHabiaEn'])->get()->sortBy(fn (ListaCompraItem $i) => $i->producto
            ? [0, $i->producto->esBasico() ? 0 : 1, $i->producto->categoria->orden, mb_strtolower($i->descripcion)]
            : [1, 0, 0, mb_strtolower($i->descripcion)]);

        return ['pendientes' => $items->where('comprado', false)->values(), 'comprados' => $items->where('comprado', true)->values()];
    }

    /** Productos del catálogo que aún no están en la lista (para agregarlos). @return array<int, string> */
    public function opcionesParaAgregar(ListaCompra $lista, User $user): array
    {
        $yaEstan = $lista->items()->whereNotNull('producto_id')->pluck('producto_id')->all();

        return $user->productos()->where('activo', true)->whereNotIn('id', $yaEstan)->orderBy('nombre')->get()
            ->mapWithKeys(fn (Producto $p) => [$p->id => $p->nombreCompleto()])->all();
    }

    private function asegurarSinPagar(ListaCompraItem $item): void
    {
        if ($item->compra_pago_id) {
            throw ValidationException::withMessages(['item' => 'Ya se pagó. Para cambiarlo, deshaz el pago.']);
        }
    }

    private function asegurarAbierta(ListaCompra $lista): void
    {
        if (! $lista->abierta()) {
            throw ValidationException::withMessages(['lista' => 'Esta lista ya se terminó. Abre una nueva.']);
        }
    }
}
