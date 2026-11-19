<?php

namespace App\Http\Controllers;

use App\Http\Requests\TiendaRequest;
use App\Models\Tienda;
use App\Services\TiendaService;
use Illuminate\Http\Request;

/** Tiendas donde compra el hogar. */
class TiendaController extends Controller
{
    public function __construct(private readonly TiendaService $tiendas) {}

    public function index(Request $request)
    {
        $tiendas = $request->user()->tiendas()->withCount('precios')->orderByDesc('activo')->orderBy('nombre')->get();

        return view('tiendas.index', compact('tiendas'));
    }

    public function create()
    {
        return view('tiendas._form');
    }

    public function store(TiendaRequest $request)
    {
        $tienda = $this->tiendas->crear($request->user(), $request->datos());

        return response()->json(['success' => true, 'message' => "Tienda agregada: {$tienda->nombre}."]);
    }

    public function edit(Request $request, Tienda $tienda)
    {
        $this->own($request, $tienda);

        return view('tiendas._form', compact('tienda'));
    }

    public function update(TiendaRequest $request, Tienda $tienda)
    {
        $this->own($request, $tienda);
        $this->tiendas->actualizar($tienda, $request->datos());

        return response()->json(['success' => true, 'message' => 'Tienda actualizada.']);
    }

    public function destroy(Request $request, Tienda $tienda)
    {
        $this->own($request, $tienda);
        $this->tiendas->eliminar($tienda);

        return response()->json(['success' => true, 'message' => 'Tienda eliminada.']);
    }

    public function comunes(Request $request)
    {
        $n = $this->tiendas->agregarComunes($request->user());

        return response()->json(['success' => true, 'message' => match ($n) {
            0 => 'Ya tienes todas las tiendas comunes.', 1 => 'Se agregó 1 tienda.', default => "Se agregaron {$n} tiendas.",
        }]);
    }

    private function own(Request $request, Tienda $tienda): void
    {
        abort_unless($tienda->esDelHogar($request->user()), 404);
    }
}
