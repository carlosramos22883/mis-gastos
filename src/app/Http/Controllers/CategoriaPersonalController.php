<?php

namespace App\Http\Controllers;

use App\Exports\CategoriasPersonalesExport;
use App\Models\CategoriaPersonal;
use App\Traits\Exportable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CategoriaPersonalController extends Controller
{
    use Exportable;

    private function query(Request $request)
    {
        return CategoriaPersonal::where('user_id', $request->user()->id)
            ->when($request->filled('search'), fn ($q) => $q->where('nombre', 'like', '%'.$request->search.'%'))
            ->when($request->filled('tipo'), fn ($q) => $q->where('tipo', $request->tipo))
            ->when($request->filled('activo'), fn ($q) => $q->where('activo', $request->activo === '1'))
            ->orderBy(in_array($request->get('sort'), ['nombre', 'tipo', 'activo', 'created_at'], true) ? $request->get('sort') : 'nombre', $request->get('direction') === 'desc' ? 'desc' : 'asc');
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

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $request->user()->categoriasPersonales()->create($data);

        return $this->success($request, 'Categoría creada correctamente.');
    }

    public function edit(Request $request, CategoriaPersonal $categoria)
    {
        abort_unless($categoria->user_id === $request->user()->id, 404);

        return view('categorias._form', compact('categoria'));
    }

    public function update(Request $request, CategoriaPersonal $categoria)
    {
        abort_unless($categoria->user_id === $request->user()->id, 404);
        $categoria->update($this->validated($request, $categoria));

        return $this->success($request, 'Categoría actualizada correctamente.');
    }

    public function destroy(Request $request, CategoriaPersonal $categoria)
    {
        abort_unless($categoria->user_id === $request->user()->id, 404);
        $categoria->delete();

        return $this->success($request, 'Categoría eliminada correctamente.');
    }

    public function export(Request $request)
    {
        return $this->handleExport($request, $this->query($request)->get(), CategoriasPersonalesExport::class, 'Categorías personales', ['id' => 'ID', 'nombre' => 'Nombre', 'tipo' => 'Tipo', 'color' => 'Color', 'activo' => 'Activa'], 'categorias');
    }

    private function validated(Request $request, ?CategoriaPersonal $categoria = null): array
    {
        return $request->validate([
            'nombre' => ['required', 'string', 'max:100', Rule::unique('categorias_personales')->where(fn ($q) => $q->where('user_id', $request->user()->id)->where('tipo', $request->input('tipo', 'egreso')))->ignore($categoria?->id)],
            'tipo' => ['required', Rule::in(['ingreso', 'egreso'])],
            'color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'activo' => ['nullable', 'boolean'],
        ], [], ['nombre' => 'Nombre', 'tipo' => 'Tipo', 'color' => 'Color']);
    }

    private function success(Request $request, string $message)
    {
        return $request->expectsJson()
            ? response()->json(['success' => true, 'message' => $message])
            : redirect()->route('categorias.index')->with('success', $message);
    }
}
