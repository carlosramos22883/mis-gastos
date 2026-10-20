<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CategoriaPersonalRequest;
use App\Http\Resources\CategoriaResource;
use App\Models\CategoriaPersonal;
use App\Services\CategoriaPersonalService;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Categorías', description: 'Categorías personales de ingresos y egresos')]
class CategoriaController extends Controller
{
    public function __construct(private readonly CategoriaPersonalService $categorias) {}

    #[OA\Get(path: '/api/categorias', summary: 'Listar categorías del usuario', tags: ['Categorías'], security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'tipo', in: 'query', schema: new OA\Schema(type: 'string', enum: ['ingreso', 'egreso'])),
            new OA\Parameter(name: 'activo', in: 'query', schema: new OA\Schema(type: 'boolean')),
        ],
        responses: [new OA\Response(response: 200, description: 'Lista de categorías')])]
    public function index(Request $request)
    {
        $categorias = $request->user()->categoriasPersonales()
            ->when($request->filled('tipo'), fn ($q) => $q->where('tipo', $request->tipo))
            ->when($request->has('activo'), fn ($q) => $q->where('activo', $request->boolean('activo')))
            ->orderBy('tipo')->orderBy('nombre')->get();

        return CategoriaResource::collection($categorias);
    }

    #[OA\Post(path: '/api/categorias', summary: 'Crear categoría', tags: ['Categorías'], security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 201, description: 'Creada'), new OA\Response(response: 422, description: 'Error de validación')])]
    public function store(CategoriaPersonalRequest $request)
    {
        $categoria = $request->user()->categoriasPersonales()->create($request->validated() + ['activo' => true]);

        return (new CategoriaResource($categoria))->response()->setStatusCode(201);
    }

    #[OA\Put(path: '/api/categorias/{id}', summary: 'Actualizar categoría', tags: ['Categorías'], security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Actualizada'), new OA\Response(response: 403, description: 'Categoría del sistema')])]
    public function update(CategoriaPersonalRequest $request, CategoriaPersonal $categoria)
    {
        $this->own($request, $categoria);
        $categoria->update($request->validated());

        return new CategoriaResource($categoria);
    }

    #[OA\Delete(path: '/api/categorias/{id}', summary: 'Eliminar categoría (si no tiene registros)', tags: ['Categorías'], security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 204, description: 'Eliminada'), new OA\Response(response: 422, description: 'En uso: desactivarla')])]
    public function destroy(Request $request, CategoriaPersonal $categoria)
    {
        $this->own($request, $categoria);
        $this->categorias->eliminar($categoria);

        return response()->noContent();
    }

    private function own(Request $request, CategoriaPersonal $categoria): void
    {
        abort_unless($categoria->esDelHogar($request->user()), 404);
        abort_unless($this->categorias->esEditable($categoria), 403, 'Esta categoría la administra el sistema.');
    }
}
