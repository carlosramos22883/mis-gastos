<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DespensaRequest;
use App\Http\Requests\PrecioRequest;
use App\Http\Requests\ProductoRequest;
use App\Http\Resources\ProductoResource;
use App\Models\Precio;
use App\Models\Producto;
use App\Models\ProductoEnLinea;
use App\Services\DespensaService;
use App\Services\PreciosEnLineaService;
use App\Services\PrecioService;
use App\Services\ProductoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Despensa', description: 'Productos del hogar, lo que hay en casa, sus precios y su liga con las tiendas en línea')]
class ProductoController extends Controller
{
    public function __construct(
        private readonly ProductoService $productos,
        private readonly DespensaService $despensa,
        private readonly PrecioService $precios,
        private readonly PreciosEnLineaService $enLinea,
    ) {}

    #[OA\Get(path: '/api/productos', summary: 'Productos con lo que hay en casa (básicos primero)', tags: ['Despensa'], security: [['bearerAuth' => []]],
        description: 'Filtros: buscar (nombre, marca o código), categoria (id), importancia (basico|ocasional), faltan=1, archivados=1. meta.resumen = {faltan, por_vencer}.',
        responses: [new OA\Response(response: 200, description: 'Lista')])]
    public function index(Request $request)
    {
        $filtros = [
            'buscar' => $request->string('buscar')->trim()->value(),
            'categoria' => $request->integer('categoria') ?: null,
            'importancia' => $request->get('importancia'),
            'archivados' => $request->boolean('archivados'),
            'faltan' => $request->boolean('faltan') && ! $request->boolean('archivados'),
        ];
        $hoy = $this->despensa->hoy($request->user());
        $lista = $this->productos->lista($request->user(), $filtros)->each(fn (Producto $p) => $p->setAttribute('estado', $this->despensa->estado($p, $hoy)));

        return ProductoResource::collection($lista)->additional(['meta' => ['resumen' => $this->productos->resumen($request->user())]]);
    }

    #[OA\Get(path: '/api/productos/codigo/{codigo}', summary: 'Buscar por código de barras (al escanear)', tags: ['Despensa'], security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'El producto del hogar con ese código'), new OA\Response(response: 404, description: 'No está en la despensa: crearlo con ese código')])]
    public function porCodigo(Request $request, string $codigo)
    {
        $producto = $request->user()->productos()->where('codigo_barras', preg_replace('/\D/', '', $codigo))->firstOrFail();

        return new ProductoResource($this->detalle($request, $producto));
    }

    #[OA\Post(path: '/api/productos', summary: 'Crear producto (multipart si lleva foto)', tags: ['Despensa'], security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 201, description: 'Creado'), new OA\Response(response: 422, description: 'Error de validación (ej. ya existe)')])]
    public function store(ProductoRequest $request)
    {
        $producto = $this->productos->crear($request->user(), $request->datos(), $request->file('foto'));

        return (new ProductoResource($this->detalle($request, $producto)))->response()->setStatusCode(201);
    }

    #[OA\Get(path: '/api/productos/{id}', summary: 'Producto con sus precios por tienda, historial y ligas en línea', tags: ['Despensa'], security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Detalle'), new OA\Response(response: 404, description: 'No existe o es de otro hogar')])]
    public function show(Request $request, Producto $producto)
    {
        return new ProductoResource($this->detalle($request, $producto));
    }

    #[OA\Put(path: '/api/productos/{id}', summary: 'Actualizar producto (para cambiar la foto: POST con _method=PUT y multipart; quitar_foto=1 la quita)', tags: ['Despensa'], security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Actualizado')])]
    public function update(ProductoRequest $request, Producto $producto)
    {
        $this->own($request, $producto);
        $this->productos->actualizar($producto, $request->datos(), $request->file('foto'), $request->boolean('quitar_foto'));

        return new ProductoResource($this->detalle($request, $producto));
    }

    #[OA\Delete(path: '/api/productos/{id}', summary: 'Eliminar producto (y su foto)', tags: ['Despensa'], security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 204, description: 'Eliminado')])]
    public function destroy(Request $request, Producto $producto)
    {
        $this->own($request, $producto);
        $this->productos->eliminar($producto);

        return response()->noContent();
    }

    #[OA\Get(path: '/api/productos/{id}/foto', summary: 'Foto del producto (privada)', tags: ['Despensa'], security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Imagen'), new OA\Response(response: 404, description: 'Sin foto')])]
    public function foto(Request $request, Producto $producto)
    {
        $this->own($request, $producto);
        abort_unless($producto->foto && Storage::disk(ProductoService::DISCO)->exists($producto->foto), 404);

        return Storage::disk(ProductoService::DISCO)->response($producto->foto, null, ['Cache-Control' => 'private, max-age=86400']);
    }

    #[OA\Patch(path: '/api/productos/{id}/despensa', summary: 'Cambio rápido de lo que hay en casa', tags: ['Despensa'], security: [['bearerAuth' => []]],
        description: 'accion: sumar | restar | cantidad (valor = cuántos) | se_acabo | por_acabarse (valor 1/0) | no_llevar_cuenta | vence (valor = Y-m-d o vacío). Devuelve el estado.',
        responses: [new OA\Response(response: 200, description: 'Estado'), new OA\Response(response: 422, description: 'Error de validación')])]
    public function despensa(DespensaRequest $request, Producto $producto)
    {
        $this->own($request, $producto);
        $producto = $this->despensa->ajustar($producto, $request->validated('accion'), $request->validated('valor'));

        return response()->json(['estado' => $this->despensa->estado($producto, $this->despensa->hoy($request->user()))]);
    }

    #[OA\Post(path: '/api/productos/{id}/precios', summary: 'Anotar un precio visto (tienda_id, precio, unidades = paquete de N, fecha)', tags: ['Despensa'], security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 201, description: 'Producto con sus precios'), new OA\Response(response: 422, description: 'Error de validación')])]
    public function anotarPrecio(PrecioRequest $request, Producto $producto)
    {
        $this->own($request, $producto);
        $tienda = $request->user()->tiendas()->where('activo', true)->find($request->validated('tienda_id'))
            ?? throw ValidationException::withMessages(['tienda_id' => 'Elige una de tus tiendas.']);
        $this->precios->anotar($request->user(), $producto, $tienda, $request->validated());

        return (new ProductoResource($this->detalle($request, $producto)))->response()->setStatusCode(201);
    }

    #[OA\Delete(path: '/api/productos/{id}/precios/{precio}', summary: 'Eliminar un precio anotado', tags: ['Despensa'], security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 204, description: 'Eliminado'), new OA\Response(response: 404, description: 'No es un precio anotado de este producto')])]
    public function eliminarPrecio(Request $request, Producto $producto, Precio $precio)
    {
        $this->own($request, $producto);
        abort_unless($precio->producto_id === $producto->id && $precio->origen === 'anotado', 404);
        $precio->delete();

        return response()->noContent();
    }

    #[OA\Get(path: '/api/productos/{id}/en-linea/{fuente}', summary: 'Buscar el producto en una tienda en línea para ligarlo', tags: ['Despensa'], security: [['bearerAuth' => []]],
        description: 'fuente: walmart | donjuan | selectos. Sin q busca por código de barras y, si no sale, por nombre. 30 búsquedas por hogar por hora.',
        responses: [new OA\Response(response: 200, description: 'resultados[]: codigo, nombre, marca, precio, ean, url, imagen, mismo_codigo'), new OA\Response(response: 422, description: 'Sin esa tienda, límite o tienda pausada')])]
    public function buscarEnLinea(Request $request, Producto $producto, string $fuente)
    {
        $this->own($request, $producto);
        $request->validate(['q' => ['nullable', 'string', 'max:100']]);

        return response()->json(['resultados' => $this->enLinea->buscar($request->user(), $producto, $fuente, $request->input('q'))]);
    }

    #[OA\Post(path: '/api/productos/{id}/en-linea/{fuente}', summary: 'Ligar con un resultado (codigo, nombre, url, imagen, precio, unidades)', tags: ['Despensa'], security: [['bearerAuth' => []]],
        description: 'Ligar Walmart liga también Don Juan (y al revés) si el hogar tiene esa tienda.',
        responses: [new OA\Response(response: 201, description: 'Producto con sus ligas')])]
    public function enlazar(Request $request, Producto $producto, string $fuente)
    {
        $this->own($request, $producto);
        $data = $request->validate([
            'codigo' => ['required', 'string', 'max:40', 'regex:/^[\w-]+$/'],
            'nombre' => ['required', 'string', 'max:300'],
            'url' => ['nullable', 'url', 'max:500', 'starts_with:https://'],
            'imagen' => ['nullable', 'url', 'max:500', 'starts_with:https://'],
            'precio' => ['nullable', 'numeric', 'gt:0', 'max:99999'],
            'unidades' => ['nullable', 'integer', 'min:1', 'max:999'],
        ]);
        $this->enLinea->enlazar($request->user(), $producto, $fuente, $data);

        return (new ProductoResource($this->detalle($request, $producto)))->response()->setStatusCode(201);
    }

    #[OA\Delete(path: '/api/productos/{id}/en-linea/{fuente}', summary: 'Quitar la liga (los precios leídos se quedan)', tags: ['Despensa'], security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 204, description: 'Quitada')])]
    public function desenlazar(Request $request, Producto $producto, string $fuente)
    {
        $this->own($request, $producto);
        $this->enLinea->desenlazar(ProductoEnLinea::where('producto_id', $producto->id)->where('fuente', $fuente)->firstOrFail());

        return response()->noContent();
    }

    private function detalle(Request $request, Producto $producto): Producto
    {
        $this->own($request, $producto);
        $producto = $producto->fresh(['categoria']);

        return $producto->setAttribute('estado', $this->despensa->estado($producto, $this->despensa->hoy($request->user())))
            ->setAttribute('ultimos', $this->precios->ultimosPorTienda($producto))->setAttribute('historial', $this->precios->historial($producto))
            ->setAttribute('enlaces', $this->enLinea->enlaces($producto)->values())->setAttribute('con_detalle', true);
    }

    private function own(Request $request, Producto $producto): void
    {
        abort_unless($producto->esDelHogar($request->user()), 404);
    }
}
