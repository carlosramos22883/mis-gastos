<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Moneda;
use Illuminate\Http\Request;

class MonedaController extends Controller
{
    public function index(Request $request)
    {
        $query = Moneda::query();

        if ($request->filled('search')) {
            $query->where('nombre', 'like', "%{$request->search}%")
                  ->orWhere('codigo', 'like', "%{$request->search}%")
                  ->orWhere('simbolo', 'like', "%{$request->search}%");
        }

        $sortField = $request->get('sort', 'created_at');
        $sortDirection = $request->get('direction', 'desc');
        $query->orderBy($sortField, $sortDirection);

        $items = $query->paginate($request->get('per_page', 10))->withQueryString();

        if ($request->ajax() || $request->wantsJson()) {
            return response()->json([
                'html' => view('admin.catalogos.monedas._table_body', compact('items'))->render(),
                'pagination' => $items->links()->render(),
            ]);
        }

        return view('admin.catalogos.monedas.index', compact('items'));
    }

    public function create()
    {
        return view('admin.catalogos.monedas._form');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'codigo' => ['required', 'string', 'max:10'],
            'simbolo' => ['required', 'string', 'max:10'],
            'activo' => ['nullable', 'boolean'],
        ]);

        $validated['activo'] = $request->has('activo');

        Moneda::create($validated);

        if ($request->wantsJson()) {
            return response()->json(['success' => true, 'message' => 'Moneda creada exitosamente.'], 200);
        }
        return redirect()->back()->with('success', 'Moneda creada exitosamente.');
    }

    public function edit(Moneda $moneda)
    {
        return view('admin.catalogos.monedas._form', compact('moneda'));
    }

    public function update(Request $request, Moneda $moneda)
    {
        $validated = $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'codigo' => ['required', 'string', 'max:10'],
            'simbolo' => ['required', 'string', 'max:10'],
            'activo' => ['nullable', 'boolean'],
        ]);

        $validated['activo'] = $request->has('activo');

        $moneda->update($validated);

        if ($request->wantsJson()) {
            return response()->json(['success' => true, 'message' => 'Moneda actualizada exitosamente.'], 200);
        }
        return redirect()->back()->with('success', 'Moneda actualizada exitosamente.');
    }

    public function destroy(Request $request, Moneda $moneda)
    {
        $moneda->delete();

        if ($request->wantsJson()) {
            return response()->json(['success' => true, 'message' => 'Moneda eliminada exitosamente.'], 200);
        }
        return redirect()->back()->with('success', 'Moneda eliminada exitosamente.');
    }
}