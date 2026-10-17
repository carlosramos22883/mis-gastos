<?php

namespace App\Http\Controllers;

use App\Exports\CategoriasPersonalesExport;
use App\Http\Requests\CategoriaPersonalRequest;
use App\Models\CategoriaPersonal;
use App\Services\CategoriaPersonalService;
use App\Traits\Exportable;
use Illuminate\Http\Request;

class CategoriaPersonalController extends Controller
{
    use Exportable;

    private function query(Request $request)
    {
        $search = $request->has('table_search') ? $request->input('table_search') : $request->input('search');
        $tipo = $request->has('table_tipo') ? $request->input('table_tipo') : $request->input('tipo');
        $activo = $request->has('table_activo') ? $request->input('table_activo') : $request->input('activo');

        $sort = in_array($request->get('sort'), ['nombre', 'tipo', 'activo', 'created_at'], true)
            ? $request->get('sort')
            : 'nombre';
        $direction = $request->get('direction') === 'desc' ? 'desc' : 'asc';

        $query = CategoriaPersonal::where('user_id', $request->user()->id)
            ->where('nombre', '!=', 'Saldo inicial')
            ->when($search !== null && $search !== '', fn ($q) => $q->where('nombre', 'like', '%'.$search.'%'))
            ->when($tipo !== null && $tipo !== '', fn ($q) => $q->where('tipo', $tipo))
            ->when($activo !== null && $activo !== '', fn ($q) => $q->where('activo', $activo === '1'));

        if ($sort === 'nombre') {
            $query->orderByRaw("LOWER(nombre) {$direction}")->orderBy('id', 'asc');
        } else {
            $query->orderBy($sort, $direction);
        }

        return $query;
    }

    public function index(Request $request)
    {
        $categorias = $this->query($request)->paginate((int) $request->get('per_page', 10))->withQueryString();
        if ($request->ajax() || $request->wantsJson()) {
            return response()->json(['html' => view('categorias._table', compact('categorias'))->render(), 'pagination' => $categorias->links()->render()]);
        }

        return view('categorias.index', compact('categorias'));
    }

    public function create()
    {
        return view('categorias._form');
    }

    public function store(CategoriaPersonalRequest $request)
    {
        $categoria = $request->user()->categoriasPersonales()->create($request->validated());

        return $this->success($request, 'Categoría creada correctamente.', $categoria);
    }

    public function edit(Request $request, CategoriaPersonal $categoria)
    {
        $this->own($request, $categoria);

        return view('categorias._form', compact('categoria'));
    }

    public function update(CategoriaPersonalRequest $request, CategoriaPersonal $categoria)
    {
        $this->own($request, $categoria);
        $categoria->update($request->validated());

        return $this->success($request, 'Categoría actualizada correctamente.', $categoria);
    }

    public function destroy(Request $request, CategoriaPersonal $categoria)
    {
        $this->own($request, $categoria);
        $currentPage = max(1, (int) $request->input('page', 1));
        $perPage = max(1, (int) $request->input('per_page', 10));
        app(CategoriaPersonalService::class)->eliminar($categoria);
        $lastPage = max(1, (int) ceil($this->query($request)->count() / $perPage));

        return $this->success($request, 'Categoría eliminada correctamente.', null, min($currentPage, $lastPage));
    }

    public function export(Request $request)
    {
        return $this->handleExport($request, $this->query($request)->get(), CategoriasPersonalesExport::class, 'Categorías personales', ['id' => 'ID', 'nombre' => 'Nombre', 'tipo' => 'Tipo', 'color' => 'Color', 'activo' => 'Activa'], 'categorias');
    }

    private function own(Request $request, CategoriaPersonal $categoria): void
    {
        abort_unless($categoria->user_id === $request->user()->id, 404);
        abort_unless(app(CategoriaPersonalService::class)->esEditable($categoria), 403);
    }

    private function success(Request $request, string $message, ?CategoriaPersonal $categoria = null, ?int $page = null)
    {
        return $request->expectsJson()
            ? response()->json(array_filter([
                'success' => true,
                'message' => $message,
                'highlight_id' => $categoria?->id,
                'redirect_to_page' => $page ?? ($categoria ? $this->pageFor($request, $categoria) : null),
            ], static fn ($value) => $value !== null))
            : redirect()->route('categorias.index')->with('success', $message);
    }

    private function pageFor(Request $request, CategoriaPersonal $categoria): int
    {
        $perPage = max(1, (int) $request->input('per_page', 10));
        $query = $this->query($request);
        $ids = $query->pluck('id')->map(static fn ($id) => (string) $id)->values();
        $position = $ids->search((string) $categoria->getKey(), true);

        return $position === false ? 1 : (int) floor($position / $perPage) + 1;
    }
}
