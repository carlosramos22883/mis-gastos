<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TipoCuenta;
use Illuminate\Http\Request;

class TipoCuentaController extends Controller
{
    public function index(Request $request)
    {
        $query = TipoCuenta::query();

        if ($request->filled('search')) {
            $query->where('nombre', 'like', "%{$request->search}%")
                  ->orWhere('descripcion', 'like', "%{$request->search}%");
        }

        $sortField = $request->get('sort', 'created_at');
        $sortDirection = $request->get('direction', 'desc');
        $query->orderBy($sortField, $sortDirection);

        $items = $query->paginate($request->get('per_page', 10))->withQueryString();

        if ($request->ajax() || $request->wantsJson()) {
            return response()->json([
                'html' => view('admin.catalogos.tipos-cuenta._table_body', compact('items'))->render(),
                'pagination' => $items->links()->render(),
            ]);
        }

        return view('admin.catalogos.tipos-cuenta.index', compact('items'));
    }

    public function create()
    {
        return view('admin.catalogos.tipos-cuenta._form');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'descripcion' => ['nullable', 'string', 'max:500'],
            'activo' => ['nullable', 'boolean'],
        ]);

        $validated['activo'] = $request->has('activo');

        TipoCuenta::create($validated);

        if ($request->wantsJson()) {
            return response()->json(['success' => true, 'message' => 'Tipo de cuenta creado exitosamente.'], 200);
        }
        return redirect()->back()->with('success', 'Tipo de cuenta creado exitosamente.');
    }

    public function edit(TipoCuenta $tipoCuenta)
    {
        return view('admin.catalogos.tipos-cuenta._form', compact('tipoCuenta'));
    }

    public function update(Request $request, TipoCuenta $tipoCuenta)
    {
        $validated = $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'descripcion' => ['nullable', 'string', 'max:500'],
            'activo' => ['nullable', 'boolean'],
        ]);

        $validated['activo'] = $request->has('activo');

        $tipoCuenta->update($validated);

        if ($request->wantsJson()) {
            return response()->json(['success' => true, 'message' => 'Tipo de cuenta actualizado exitosamente.'], 200);
        }
        return redirect()->back()->with('success', 'Tipo de cuenta actualizado exitosamente.');
    }

    public function destroy(Request $request, TipoCuenta $tipoCuenta)
    {
        $tipoCuenta->delete();

        if ($request->wantsJson()) {
            return response()->json(['success' => true, 'message' => 'Tipo de cuenta eliminado exitosamente.'], 200);
        }
        return redirect()->back()->with('success', 'Tipo de cuenta eliminado exitosamente.');
    }
}