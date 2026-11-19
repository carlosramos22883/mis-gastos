<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\TiendaRequest;
use App\Http\Resources\TiendaResource;
use App\Models\Tienda;
use App\Services\TiendaService;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Tiendas', description: 'Tiendas donde compra el hogar (con precios en línea o anotados)')]
class TiendaController extends Controller
{
    public function __construct(private readonly TiendaService $tiendas) {}

    #[OA\Get(path: '/api/tiendas', summary: 'Tiendas del hogar (activas primero)', tags: ['Tiendas'], security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Lista')])]
    public function index(Request $request)
    {
        return TiendaResource::collection($request->user()->tiendas()->withCount('precios')->orderByDesc('activo')->orderBy('nombre')->get());
    }

    #[OA\Post(path: '/api/tiendas', summary: 'Crear tienda (nombre, fuente: walmart|donjuan|selectos|null, notas, activo)', tags: ['Tiendas'], security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 201, description: 'Creada'), new OA\Response(response: 422, description: 'Error de validación')])]
    public function store(TiendaRequest $request)
    {
        return (new TiendaResource($this->tiendas->crear($request->user(), $request->datos())))->response()->setStatusCode(201);
    }

    #[OA\Put(path: '/api/tiendas/{id}', summary: 'Actualizar tienda', tags: ['Tiendas'], security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Actualizada')])]
    public function update(TiendaRequest $request, Tienda $tienda)
    {
        $this->own($request, $tienda);

        return new TiendaResource($this->tiendas->actualizar($tienda, $request->datos()));
    }

    #[OA\Delete(path: '/api/tiendas/{id}', summary: 'Eliminar tienda (con precios o compras: desactivarla)', tags: ['Tiendas'], security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 204, description: 'Eliminada'), new OA\Response(response: 422, description: 'Tiene historial')])]
    public function destroy(Request $request, Tienda $tienda)
    {
        $this->own($request, $tienda);
        $this->tiendas->eliminar($tienda);

        return response()->noContent();
    }

    #[OA\Post(path: '/api/tiendas/comunes', summary: 'Agregar las tiendas de siempre que faltan', tags: ['Tiendas'], security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'agregadas = cuántas; data = todas las tiendas')])]
    public function comunes(Request $request)
    {
        $n = $this->tiendas->agregarComunes($request->user());

        return TiendaResource::collection($request->user()->tiendas()->orderByDesc('activo')->orderBy('nombre')->get())->additional(['agregadas' => $n]);
    }

    private function own(Request $request, Tienda $tienda): void
    {
        abort_unless($tienda->esDelHogar($request->user()), 404);
    }
}
