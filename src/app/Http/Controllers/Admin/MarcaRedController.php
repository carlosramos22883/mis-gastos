<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MarcaRed;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class MarcaRedController extends Controller
{
    public function index(Request $request)
    {
        $query = MarcaRed::query();

        if ($request->filled('search')) {
            $query->where('nombre', 'like', "%{$request->search}%")
                ->orWhere('color', 'like', "%{$request->search}%");
        }

        $sortField = $request->get('sort', 'created_at');
        $sortDirection = $request->get('direction', 'desc');
        $query->orderBy($sortField, $sortDirection);

        $items = $query->paginate($request->get('per_page', 10))->withQueryString();

        if ($request->ajax() || $request->wantsJson()) {
            return response()->json([
                'html' => view('admin.catalogos.marca-red._table_body', compact('items'))->render(),
                'pagination' => $items->links()->render(),
            ]);
        }

        return view('admin.catalogos.marca-red.index', compact('items'));
    }

    public function create()
    {
        return view('admin.catalogos.marca-red._form');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'nombre' => 'required|string|max:255',
            'logo' => 'nullable|image|max:2048', // Máximo 2MB
            'activo' => 'boolean',
        ]);

        if ($request->hasFile('logo')) {
            $validated['logo'] = $request->file('logo')->store('logos/marcaRed', 'public');
        }

        MarcaRed::create($validated);

        return redirect()->back()->with('success', 'Marca de la Red creada correctamente.');
    }

    public function edit(MarcaRed $marcaRed)
    {
        return view('admin.catalogos.marca-red._form', compact('marcaRed'));
    }

    public function update(Request $request, MarcaRed $marcaRed)
    {
        $validated = $request->validate([
            'nombre' => 'required|string|max:255',
            'logo' => 'nullable|image|max:2048',
            'activo' => 'boolean',
        ]);

        if ($request->hasFile('logo')) {
            // Eliminar logo anterior
            if ($marcaRed->logo && Storage::disk('public')->exists($marcaRed->logo)) {
                Storage::disk('public')->delete($marcaRed->logo);
            }
            $validated['logo'] = $request->file('logo')->store('logos/marcaRed', 'public');
        }

        $marcaRed->update($validated);

        return redirect()->back()->with('success', 'Marca de la Red actualizada correctamente.');
    }

    public function destroy(Request $request, MarcaRed $marcaRed)
    {
        $marcaRed->delete();

        if ($request->wantsJson()) {
            return response()->json(['success' => true, 'message' => 'Marca de red eliminada exitosamente.'], 200);
        }
        return redirect()->back()->with('success', 'Marca de red eliminada exitosamente.');
    }
}
