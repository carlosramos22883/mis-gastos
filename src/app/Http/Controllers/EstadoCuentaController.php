<?php

namespace App\Http\Controllers;

use App\Models\EstadoCuentaTarjeta;
use App\Services\TarjetaService;
use Carbon\Carbon;
use Illuminate\Http\Request;

/** Detalle de un estado de cuenta, cambio de su fecha límite y registro de mora. */
class EstadoCuentaController extends Controller
{
    public function __construct(private readonly TarjetaService $tarjetas) {}

    public function show(Request $request, EstadoCuentaTarjeta $estado)
    {
        $this->own($request, $estado);

        return view('tarjetas._estado', ['estado' => $estado->load('tarjeta', 'compromiso', 'items.movimiento')]);
    }

    public function fechaLimite(Request $request, EstadoCuentaTarjeta $estado)
    {
        $this->own($request, $estado);
        $data = $request->validate(['fecha_limite_pago' => ['required', 'date']]);
        $this->tarjetas->actualizarFechaLimite($estado, Carbon::parse($data['fecha_limite_pago'])->toDateString());

        return response()->json(['success' => true, 'message' => 'Fecha límite actualizada; el compromiso de pago también.']);
    }

    public function moraForm(Request $request, EstadoCuentaTarjeta $estado)
    {
        $this->own($request, $estado);

        return view('tarjetas._mora_form', ['estado' => $estado->load('tarjeta', 'compromiso')]);
    }

    public function mora(Request $request, EstadoCuentaTarjeta $estado)
    {
        $this->own($request, $estado);
        $data = $request->validate([
            'descripcion' => ['required', 'string', 'max:255'],
            'monto' => ['required', 'numeric', 'gt:0', 'regex:/^\d+(?:\.\d{1,2})?$/'],
        ]);
        $this->tarjetas->registrarMora($estado, (float) $data['monto'], $data['descripcion']);

        return response()->json(['success' => true, 'message' => 'Mora registrada como un nuevo compromiso de pago.']);
    }

    private function own(Request $request, EstadoCuentaTarjeta $estado): void
    {
        abort_unless($estado->tarjeta->esDelHogar($request->user()), 404);
    }
}
