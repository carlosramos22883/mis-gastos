<?php

namespace App\Http\Controllers;

use App\Http\Requests\DespensaRequest;
use App\Http\Requests\PrecioRequest;
use App\Http\Requests\ProductoRequest;
use App\Models\Catalogos\CategoriaDespensa;
use App\Models\Precio;
use App\Models\Producto;
use App\Models\Tienda;
use App\Services\DespensaService;
use App\Services\PreciosEnLineaService;
use App\Services\PrecioService;
use App\Services\ProductoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/** Despensa: los productos que el hogar compra siempre. */
class ProductoController extends Controller
{
    public function __construct(private readonly ProductoService $productos, private readonly DespensaService $despensa) {}

    public function index(Request $request)
    {
        $filtros = [
            'buscar' => $request->string('buscar')->trim()->value(),
            'categoria' => $request->integer('categoria') ?: null,
            'importancia' => $request->get('importancia'),
            'archivados' => $request->boolean('archivados'),
            'faltan' => $request->boolean('faltan') && ! $request->boolean('archivados'),
        ];
        $productos = $this->productos->lista($request->user(), $filtros);
        $categorias = CategoriaDespensa::opciones($filtros['categoria']);
        $resumen = $this->productos->resumen($request->user());
        $hoy = $this->despensa->hoy($request->user());
        $estados = $productos->mapWithKeys(fn (Producto $p) => [$p->id => $this->despensa->estado($p, $hoy)]);

        return view('productos.index', compact('productos', 'filtros', 'categorias', 'resumen', 'estados'));
    }

    public function create()
    {
        return view('productos._form');
    }

    public function store(ProductoRequest $request)
    {
        $producto = $this->productos->crear($request->user(), $request->datos(), $request->file('foto'));

        return response()->json(['success' => true, 'message' => "Producto agregado: {$producto->nombreCompleto()}."]);
    }

    public function edit(Request $request, Producto $producto)
    {
        $this->own($request, $producto);

        return view('productos._form', compact('producto'));
    }

    public function update(ProductoRequest $request, Producto $producto)
    {
        $this->own($request, $producto);
        $this->productos->actualizar($producto, $request->datos(), $request->file('foto'), $request->boolean('quitar_foto'));

        return response()->json(['success' => true, 'message' => 'Producto actualizado.']);
    }

    public function destroy(Request $request, Producto $producto)
    {
        $this->own($request, $producto);
        $this->productos->eliminar($producto);

        return response()->json(['success' => true, 'message' => 'Producto eliminado.']);
    }

    /** Cambio rápido en lo que hay en casa (sumar, restar, «se está acabando», «se acabó», vencimiento). */
    public function despensa(DespensaRequest $request, Producto $producto)
    {
        $this->own($request, $producto);
        $producto = $this->despensa->ajustar($producto, $request->validated('accion'), $request->validated('valor'));

        return response()->json(['success' => true, 'estado' => $this->despensa->estado($producto, $this->despensa->hoy($request->user()))]);
    }

    /** Precios del producto: el último de cada tienda (más barato por unidad primero) y el historial. */
    public function precios(Request $request, Producto $producto, PrecioService $precios)
    {
        $this->own($request, $producto);

        return view('productos._precios', [
            'producto' => $producto, 'ultimos' => $precios->ultimosPorTienda($producto), 'historial' => $precios->historial($producto),
            'tiendas' => Tienda::opciones($request->user()), 'hoy' => $this->despensa->hoy($request->user()),
            'fuentes' => app(PreciosEnLineaService::class)->fuentesDelHogar($request->user())->keys()->all(),
            'enlaces' => app(PreciosEnLineaService::class)->enlaces($producto),
        ]);
    }

    public function anotarPrecio(PrecioRequest $request, Producto $producto, PrecioService $precios)
    {
        $this->own($request, $producto);
        $tienda = $request->user()->tiendas()->where('activo', true)->find($request->validated('tienda_id'))
            ?? throw ValidationException::withMessages(['tienda_id' => 'Elige una de tus tiendas.']);
        $precio = $precios->anotar($request->user(), $producto, $tienda, $request->validated());

        return response()->json(['success' => true, 'message' => "Precio anotado: {$tienda->nombre}, $ ".number_format((float) $precio->precio, 2).'.']);
    }

    public function eliminarPrecio(Request $request, Producto $producto, Precio $precio)
    {
        $this->own($request, $producto);
        abort_unless($precio->producto_id === $producto->id && $precio->origen === 'anotado', 404);
        $precio->delete();

        return response()->json(['success' => true, 'message' => 'Precio eliminado.']);
    }

    /** La foto se sirve desde el disco privado, solo a miembros del hogar. */
    public function foto(Request $request, Producto $producto)
    {
        $this->own($request, $producto);
        abort_unless($producto->foto && Storage::disk(ProductoService::DISCO)->exists($producto->foto), 404);

        return Storage::disk(ProductoService::DISCO)->response($producto->foto, null, ['Cache-Control' => 'private, max-age=86400']);
    }

    private function own(Request $request, Producto $producto): void
    {
        abort_unless($producto->esDelHogar($request->user()), 404);
    }
}
