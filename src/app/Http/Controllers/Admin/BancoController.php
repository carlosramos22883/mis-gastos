<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Banco;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class BancoController extends Controller
{
    public function index(Request $request)
    {
        $query = Banco::query();

        if ($request->filled('search')) {
            $query->where('nombre', 'like', "%{$request->search}%")
                ->orWhere('codigo', 'like', "%{$request->search}%");
        }

        $sortField = $request->get('sort', 'created_at');
        $sortDirection = $request->get('direction', 'desc');
        $query->orderBy($sortField, $sortDirection);

        $items = $query->paginate($request->get('per_page', 10))->withQueryString();

        if ($request->ajax() || $request->wantsJson()) {
            return response()->json([
                'html' => view('admin.catalogos.bancos._table_body', compact('items'))->render(),
                'pagination' => $items->links()->render(),
            ]);
        }

        return view('admin.catalogos.bancos.index', compact('items'));
    }

    public function create()
    {
        return view('admin.catalogos.bancos._form');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'nombre' => 'required|string|max:255',
            'logo' => 'nullable|image|max:2048', // Máximo 2MB
            'activo' => 'boolean',
        ]);

        if ($request->hasFile('logo')) {
            $validated['logo'] = $request->file('logo')->store('logos/bancos', 'public');
        }

        Banco::create($validated);

        return redirect()->back()->with('success', 'Banco creado correctamente.');
    }

    public function edit(Banco $banco)
    {
        return view('admin.catalogos.bancos._form', compact('banco'));
    }

    public function update(Request $request, Banco $banco)
    {
        $validated = $request->validate([
            'nombre' => 'required|string|max:255',
            'logo' => 'nullable|image|max:2048',
            'activo' => 'boolean',
        ]);

        if ($request->hasFile('logo')) {
            // Eliminar logo anterior
            if ($banco->logo && Storage::disk('public')->exists($banco->logo)) {
                Storage::disk('public')->delete($banco->logo);
            }
            $validated['logo'] = $request->file('logo')->store('logos/bancos', 'public');
        }

        $banco->update($validated);

        return redirect()->back()->with('success', 'Banco actualizado correctamente.');
    }

    public function destroy(Request $request, Banco $banco)
    {
        $banco->delete();

        if ($request->wantsJson()) {
            return response()->json(['success' => true, 'message' => 'Banco eliminado exitosamente.'], 200);
        }
        return redirect()->back()->with('success', 'Banco eliminado exitosamente.');
    }
}
