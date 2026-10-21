<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\HandlesTableNavigation;
use App\Http\Controllers\Controller;
use App\Support\CatalogosAdmin;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * CRUD de los catálogos simples definidos en App\Support\CatalogosAdmin (tipos de contacto, de préstamo…).
 * $catalogo llega como valor por defecto de la ruta y Laravel lo pasa después de {id}: por eso va al final.
 */
class CatalogoGenericoController extends Controller
{
    use HandlesTableNavigation;

    public function index(Request $request, string $catalogo)
    {
        $def = CatalogosAdmin::definicion($catalogo);
        $items = $this->consulta($request, $def)->paginate($request->get('per_page', 10))->withQueryString();
        $items->getCollection()->each(fn ($i) => $i->setAttribute('usos', CatalogosAdmin::usos($def, $i->id)));

        if ($request->ajax() || $request->wantsJson()) {
            return response()->json([
                'html' => view('admin.catalogos.generico._table_body', compact('items', 'def', 'catalogo'))->render(),
                'pagination' => $items->links()->render(),
            ]);
        }

        return view('admin.catalogos.generico.index', compact('def', 'catalogo', 'items'));
    }

    public function create(string $catalogo)
    {
        return view('admin.catalogos.generico._form', ['def' => CatalogosAdmin::definicion($catalogo), 'catalogo' => $catalogo, 'item' => null]);
    }

    public function store(Request $request, string $catalogo)
    {
        $def = CatalogosAdmin::definicion($catalogo);
        $item = $def['modelo']::create($this->validar($request, $def));

        return response()->json(['success' => true, 'message' => ucfirst($def['singular']).' creado.'] + $this->tableNavigation($this->consulta($request, $def), $request, $item->id));
    }

    public function edit(string $id, string $catalogo)
    {
        $def = CatalogosAdmin::definicion($catalogo);

        return view('admin.catalogos.generico._form', ['def' => $def, 'catalogo' => $catalogo, 'item' => $def['modelo']::findOrFail($id)]);
    }

    public function update(Request $request, string $id, string $catalogo)
    {
        $def = CatalogosAdmin::definicion($catalogo);
        $item = $def['modelo']::findOrFail($id);
        $item->update($this->validar($request, $def, $item->id));

        return response()->json(['success' => true, 'message' => ucfirst($def['singular']).' actualizado.'] + $this->tableNavigation($this->consulta($request, $def), $request, $item->id));
    }

    public function destroy(Request $request, string $id, string $catalogo)
    {
        $def = CatalogosAdmin::definicion($catalogo);
        $item = $def['modelo']::findOrFail($id);
        if ($usos = CatalogosAdmin::usos($def, $item->id)) {
            throw ValidationException::withMessages(['catalogo' => "«{$item->nombre}» está en uso en {$usos} registro(s). Desactívalo en lugar de eliminarlo."]);
        }
        $item->delete();

        return response()->json(['success' => true, 'message' => ucfirst($def['singular']).' eliminado.'] + $this->tableNavigation($this->consulta($request, $def), $request));
    }

    private function validar(Request $request, array $def, ?int $ignorar = null): array
    {
        $tabla = (new $def['modelo'])->getTable();
        $reglas = [
            'nombre' => ['required', 'string', 'max:100', Rule::unique($tabla, 'nombre')->ignore($ignorar)],
            'descripcion' => ['nullable', 'string', 'max:255'],
            'orden' => ['nullable', 'integer', 'between:0,999'],
        ];
        foreach ($def['campos'] as $campo => [, , $reglasCampo]) {
            $reglas[$campo] = $reglasCampo;
        }
        $datos = $request->validate($reglas, ['regex' => 'El monto debe tener máximo dos decimales.']);
        foreach (array_keys($def['campos']) as $campo) {
            $datos[$campo] = ($datos[$campo] ?? '') === '' ? null : $datos[$campo];
        }

        return $datos + ['activo' => $request->boolean('activo'), 'orden' => (int) ($datos['orden'] ?? 0)];
    }

    private function consulta(Request $request, array $def): Builder
    {
        $this->tableRequest($request);
        $sort = in_array($request->get('sort'), ['nombre', 'orden', 'activo', 'created_at'], true) ? $request->get('sort') : 'orden';

        return $def['modelo']::query()
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($q) => $q->where('nombre', 'like', "%{$request->search}%")->orWhere('descripcion', 'like', "%{$request->search}%")))
            ->orderBy($sort, $request->get('direction') === 'desc' ? 'desc' : 'asc')->orderBy('nombre');
    }
}
