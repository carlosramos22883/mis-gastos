<?php

namespace App\Http\Controllers;

use App\Models\ConsultaPrecios;
use App\Models\ListaCompra;
use App\Models\Producto;
use App\Models\ProductoEnLinea;
use App\Models\Tienda;
use App\Services\PreciosEnLineaService;
use Illuminate\Http\Request;

/** Ligar productos con su tienda en línea y consultar los precios de una lista (PreciosEnLineaService). */
class PreciosEnLineaController extends Controller
{
    public function __construct(private readonly PreciosEnLineaService $enLinea) {}

    public function buscar(Request $request, Producto $producto, string $fuente)
    {
        $this->ownProducto($request, $producto);
        $request->validate(['q' => ['nullable', 'string', 'max:100']]);

        return response()->json(['resultados' => $this->enLinea->buscar($request->user(), $producto, $fuente, $request->input('q'))]);
    }

    public function enlazar(Request $request, Producto $producto, string $fuente)
    {
        $this->ownProducto($request, $producto);
        $data = $request->validate([
            'codigo' => ['required', 'string', 'max:40', 'regex:/^[\w-]+$/'],
            'nombre' => ['required', 'string', 'max:300'],
            'url' => ['nullable', 'url', 'max:500', 'starts_with:https://'],
            'imagen' => ['nullable', 'url', 'max:500', 'starts_with:https://'],
            'precio' => ['nullable', 'numeric', 'gt:0', 'max:99999'],
            'unidades' => ['nullable', 'integer', 'min:1', 'max:999'],
        ]);
        $this->enLinea->enlazar($request->user(), $producto, $fuente, $data);

        return response()->json(['success' => true, 'message' => 'Listo: '.$producto->nombre.' quedó ligado en '.$this->nombreFuente($fuente).'.']);
    }

    public function desenlazar(Request $request, Producto $producto, string $fuente)
    {
        $this->ownProducto($request, $producto);
        $enlace = ProductoEnLinea::where('producto_id', $producto->id)->where('fuente', $fuente)->firstOrFail();
        $this->enLinea->desenlazar($enlace);

        return response()->json(['success' => true, 'message' => 'Se quitó la liga con '.$this->nombreFuente($fuente).'.']);
    }

    /** Empieza o continúa la consulta de la lista. */
    public function iniciar(Request $request, ListaCompra $lista)
    {
        abort_unless($lista->esDelHogar($request->user()), 404);
        $consulta = $this->enLinea->iniciar($request->user(), $lista);
        if (! $consulta) {
            return response()->json(['success' => true, 'al_dia' => true, 'message' => 'Los precios en línea de esta lista ya son de hoy.']);
        }

        return response()->json(['success' => true, 'siguiente' => route('compras.consultas.siguiente', [$lista, $consulta]), 'estado' => $this->enLinea->estado($consulta)]);
    }

    public function siguiente(Request $request, ListaCompra $lista, ConsultaPrecios $consulta)
    {
        abort_unless($lista->esDelHogar($request->user()) && $consulta->esDelHogar($request->user()) && $consulta->lista_compra_id === $lista->id, 404);

        return response()->json($this->enLinea->siguiente($consulta, $request->user()));
    }

    private function ownProducto(Request $request, Producto $producto): void
    {
        abort_unless($producto->esDelHogar($request->user()), 404);
    }

    private function nombreFuente(string $fuente): string
    {
        return preg_replace('/ \(.*$/', '', Tienda::FUENTES[$fuente]);
    }
}
