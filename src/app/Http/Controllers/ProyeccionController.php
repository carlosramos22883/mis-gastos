<?php

namespace App\Http\Controllers;

use App\Http\Requests\EscenarioProyeccionRequest;
use App\Models\EscenarioProyeccion;
use App\Services\ProyeccionService;
use Illuminate\Http\Request;

/** Proyecciones: la foto de hoy, los escenarios guardados y su comparación (hasta 3 junto a "Si sigo igual"). */
class ProyeccionController extends Controller
{
    public const MAX_COMPARAR = 3;

    public function __construct(private readonly ProyeccionService $proyecciones) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $base = $this->proyecciones->base($user);
        $escenarios = EscenarioProyeccion::delHogar($user)->latest('updated_at')->get();
        $ver = collect($request->query('ver', $escenarios->take(self::MAX_COMPARAR)->pluck('id')->all()))->map(fn ($id) => (int) $id)->take(self::MAX_COMPARAR)->all();

        $comparar = collect([['id' => null, 'nombre' => 'Si sigo igual', 'datos' => ['modo_tarjetas' => 'intereses', 'sobrante' => 'abonar', 'palancas' => []]]])
            ->concat($escenarios->whereIn('id', $ver)->map(fn ($e) => ['id' => $e->id, 'nombre' => $e->nombre, 'datos' => $e->datos]))
            ->values()->map(fn ($e) => $e + ['resultado' => $this->proyecciones->simular($base, $e['datos']),
                'meta' => ! empty($e['datos']['meta']) ? $this->proyecciones->meta($base, $e['datos'], $e['datos']['meta']) : null]);

        return view('proyecciones.index', compact('base', 'escenarios', 'ver', 'comparar'));
    }

    public function create(Request $request)
    {
        return view('proyecciones._form', ['escenario' => new EscenarioProyeccion(['datos' => ['modo_tarjetas' => 'intereses', 'sobrante' => 'abonar', 'palancas' => []]]),
            'base' => $this->proyecciones->base($request->user())]);
    }

    public function store(EscenarioProyeccionRequest $request)
    {
        $escenario = $request->user()->hogar->escenariosProyeccion()->create(['user_id' => $request->user()->id, 'nombre' => $request->validated('nombre'), 'datos' => $request->datos()]);

        return response()->json(['success' => true, 'message' => 'Escenario guardado.', 'redirect' => route('proyecciones.index', ['ver' => $this->conNuevo($request, $escenario->id)])]);
    }

    public function edit(Request $request, EscenarioProyeccion $proyeccion)
    {
        $this->own($request, $proyeccion);

        return view('proyecciones._form', ['escenario' => $proyeccion, 'base' => $this->proyecciones->base($request->user())]);
    }

    public function update(EscenarioProyeccionRequest $request, EscenarioProyeccion $proyeccion)
    {
        $this->own($request, $proyeccion);
        $proyeccion->update(['nombre' => $request->validated('nombre'), 'datos' => $request->datos()]);

        return response()->json(['success' => true, 'message' => 'Escenario actualizado.', 'redirect' => route('proyecciones.index', ['ver' => $this->conNuevo($request, $proyeccion->id)])]);
    }

    public function destroy(Request $request, EscenarioProyeccion $proyeccion)
    {
        $this->own($request, $proyeccion);
        $proyeccion->delete();

        return response()->json(['success' => true, 'message' => 'Escenario eliminado.']);
    }

    /** Los escenarios que se estaban comparando, con el recién guardado incluido. */
    private function conNuevo(Request $request, int $id): array
    {
        $ver = collect(explode(',', (string) $request->input('ver_actual')))->filter()->map(fn ($v) => (int) $v)->reject(fn ($v) => $v === $id);

        return $ver->prepend($id)->take(self::MAX_COMPARAR)->values()->all();
    }

    private function own(Request $request, EscenarioProyeccion $escenario): void
    {
        abort_unless($escenario->esDelHogar($request->user()), 404);
    }
}
