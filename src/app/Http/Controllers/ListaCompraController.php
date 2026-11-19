<?php

namespace App\Http\Controllers;

use App\Http\Requests\ListaCompraItemRequest;
use App\Models\ListaCompra;
use App\Models\ListaCompraItem;
use App\Models\Tienda;
use App\Services\CompraPagoService;
use App\Services\ListaCompraService;
use App\Services\PreciosEnLineaService;
use App\Services\PrecioService;
use App\Services\ProductoService;
use App\Services\RutaCompraService;
use Illuminate\Http\Request;

/** Listas de compras: la abierta (una por hogar) y el historial. */
class ListaCompraController extends Controller
{
    public function __construct(private readonly ListaCompraService $listas) {}

    public function index(Request $request)
    {
        $abierta = $this->listas->abierta($request->user());
        $historial = ListaCompra::delHogar($request->user())->whereNotNull('terminada_at')->withCount([
            'items', 'items as comprados_count' => fn ($q) => $q->where('comprado', true),
        ])->latest('terminada_at')->limit(30)->get();
        $abierta?->loadCount(['items', 'items as comprados_count' => fn ($q) => $q->where('comprado', true)]);
        $faltan = app(ProductoService::class)->resumen($request->user())['faltan'];

        return view('compras.index', compact('abierta', 'historial', 'faltan'));
    }

    /** Nueva lista con lo que falta (o la abierta, si ya hay una). */
    public function store(Request $request)
    {
        $lista = $this->listas->crear($request->user());

        return redirect()->route('compras.show', $lista);
    }

    public function show(Request $request, ListaCompra $lista)
    {
        $this->own($request, $lista);
        $partes = $this->listas->partes($lista);
        $opciones = $lista->abierta() ? $this->listas->opcionesParaAgregar($lista, $request->user()) : [];
        $resumen = $this->listas->resumen($lista);
        $tiendas = Tienda::opciones($request->user(), $lista->tienda_actual_id);
        $pagos = app(CompraPagoService::class)->deLista($lista);
        $comparacion = $lista->abierta() ? $partes['pendientes']->filter(fn ($i) => $i->producto)
            ->mapWithKeys(fn ($i) => [$i->id => app(PrecioService::class)->comparacion($i->producto, $request->user())])->all() : [];
        $enLinea = app(PreciosEnLineaService::class);
        $consultas = ['restantes' => $enLinea->restantesHoy($request->user()), 'fuentes' => $enLinea->fuentesDelHogar($request->user())->isNotEmpty()];

        return view('compras.show', compact('lista', 'partes', 'opciones', 'resumen', 'tiendas', 'pagos', 'comparacion', 'consultas'));
    }

    /** Ruta de compra: en qué tiendas comprar lo que falta (costo = lo que cuesta ir a una tienda más). */
    public function ruta(Request $request, ListaCompra $lista)
    {
        $this->own($request, $lista);
        abort_unless($lista->abierta(), 404);
        $request->validate(['costo' => ['nullable', 'numeric', 'min:0', 'max:100']]);
        $costo = round((float) $request->input('costo', 2), 2);
        $ruta = app(RutaCompraService::class)->calcular($lista, $request->user(), $costo);

        return view('compras.ruta', compact('lista', 'ruta'));
    }

    public function destroy(Request $request, ListaCompra $lista)
    {
        $this->own($request, $lista);
        $this->listas->eliminar($lista);

        return response()->json(['success' => true, 'message' => 'Lista eliminada.', 'redirect' => route('compras.index')]);
    }

    public function agregarFaltantes(Request $request, ListaCompra $lista)
    {
        $this->own($request, $lista);
        $n = $this->listas->agregarFaltantes($lista, $request->user());

        return response()->json(['success' => true, 'message' => match ($n) {
            0 => 'No falta nada más en la despensa.', 1 => 'Se agregó 1 producto que falta.', default => "Se agregaron {$n} productos que faltan.",
        }]);
    }

    public function terminar(Request $request, ListaCompra $lista)
    {
        $this->own($request, $lista);
        $n = $this->listas->terminar($lista);

        return response()->json(['success' => true, 'redirect' => route('compras.index'), 'message' => $n
            ? ($n === 1 ? 'Compra terminada. 1 producto subió en tu despensa.' : "Compra terminada. {$n} productos subieron en tu despensa.")
            : 'Compra terminada.']);
    }

    public function agregarItem(ListaCompraItemRequest $request, ListaCompra $lista)
    {
        $this->own($request, $lista);
        $item = $this->listas->agregar($lista, $request->user(), $request->datos());

        return response()->json(['success' => true, 'message' => "Agregado: {$item->descripcion}."]);
    }

    public function actualizarItem(ListaCompraItemRequest $request, ListaCompra $lista, ListaCompraItem $item)
    {
        $this->ownItem($request, $lista, $item);
        $item = $this->listas->actualizarItem($item, $request->user(), $request->datos());

        return response()->json(['success' => true, 'item' => $this->datosItem($item), 'resumen' => $this->listas->resumen($lista)]);
    }

    public function elegirTienda(Request $request, ListaCompra $lista)
    {
        $this->own($request, $lista);
        $request->validate(['tienda_id' => ['nullable', 'integer']]);
        $lista = $this->listas->elegirTienda($lista, $request->user(), $request->integer('tienda_id') ?: null);

        return response()->json(['success' => true, 'message' => $lista->tiendaActual ? "Estás en {$lista->tiendaActual->nombre}." : 'Sin tienda elegida.']);
    }

    public function quitarItem(Request $request, ListaCompra $lista, ListaCompraItem $item)
    {
        $this->ownItem($request, $lista, $item);
        $this->listas->quitar($item);

        return response()->json(['success' => true, 'message' => 'Quitado de la lista.']);
    }

    /** Lo que la pantalla necesita de una cosa de la lista (itemCompra en app.js). */
    public static function datosItem(ListaCompraItem $item): array
    {
        $item->loadMissing(['tienda', 'noHabiaEn']);

        return [
            'cantidad' => $item->cantidad, 'comprado' => $item->comprado,
            'precio' => $item->precio !== null ? number_format((float) $item->precio, 2, '.', '') : null,
            'tienda' => $item->tienda?->nombre,
            'no_habia' => $item->noHabiaEn ? "No había en {$item->noHabiaEn->nombre} ({$item->no_habia_el->format('d/m')})" : null,
        ];
    }

    private function own(Request $request, ListaCompra $lista): void
    {
        abort_unless($lista->esDelHogar($request->user()), 404);
    }

    private function ownItem(Request $request, ListaCompra $lista, ListaCompraItem $item): void
    {
        $this->own($request, $lista);
        abort_unless($item->lista_compra_id === $lista->id, 404);
    }
}
