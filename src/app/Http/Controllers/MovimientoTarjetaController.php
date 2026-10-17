<?php

namespace App\Http\Controllers;

use App\Http\Requests\MovimientoTarjetaRequest;
use App\Models\CategoriaPersonal;
use App\Models\MovimientoTarjeta;
use App\Models\TarjetaCredito;
use App\Services\TarjetaService;
use Illuminate\Http\Request;

class MovimientoTarjetaController extends Controller
{
    public function __construct(private readonly TarjetaService $tarjetas) {}

    public function create(Request $request, TarjetaCredito $tarjeta)
    {
        $this->own($request, $tarjeta);
        abort_unless($tarjeta->activo, 403, 'La tarjeta está inactiva.');

        return view('tarjetas._movimiento_form', ['tarjeta' => $tarjeta, 'tipo' => $request->query('tipo', 'compra')] + $this->formData($request, $tarjeta));
    }

    public function store(MovimientoTarjetaRequest $request, TarjetaCredito $tarjeta)
    {
        $this->own($request, $tarjeta);
        abort_unless($tarjeta->activo, 403, 'La tarjeta está inactiva.');
        $this->tarjetas->registrarMovimiento($tarjeta, $request->validated());

        return response()->json(['success' => true, 'message' => 'Movimiento registrado. Disponible: $ '.number_format($this->tarjetas->disponible($tarjeta), 2)]);
    }

    public function edit(Request $request, TarjetaCredito $tarjeta, MovimientoTarjeta $movimiento)
    {
        $this->own($request, $tarjeta, $movimiento);
        abort_unless($this->tarjetas->esEditable($movimiento), 403);

        return view('tarjetas._movimiento_form', ['tarjeta' => $tarjeta, 'movimiento' => $movimiento, 'tipo' => $movimiento->tipo] + $this->formData($request, $tarjeta));
    }

    public function update(MovimientoTarjetaRequest $request, TarjetaCredito $tarjeta, MovimientoTarjeta $movimiento)
    {
        $this->own($request, $tarjeta, $movimiento);
        $this->tarjetas->actualizarMovimiento($movimiento, $request->validated());

        return response()->json(['success' => true, 'message' => 'Movimiento actualizado correctamente.']);
    }

    public function destroy(Request $request, TarjetaCredito $tarjeta, MovimientoTarjeta $movimiento)
    {
        $this->own($request, $tarjeta, $movimiento);
        $this->tarjetas->eliminarMovimiento($movimiento);

        return response()->json(['success' => true, 'message' => 'Movimiento eliminado correctamente.']);
    }

    private function formData(Request $request, TarjetaCredito $tarjeta): array
    {
        return [
            'categorias' => CategoriaPersonal::where('user_id', $request->user()->id)->where('activo', true)->where('tipo', 'egreso')->orderBy('nombre')->pluck('nombre', 'id')->all(),
            'desde' => $this->tarjetas->inicioPeriodoAbierto($tarjeta),
            'disponible' => $this->tarjetas->disponible($tarjeta),
        ];
    }

    private function own(Request $request, TarjetaCredito $tarjeta, ?MovimientoTarjeta $movimiento = null): void
    {
        abort_unless($tarjeta->user_id === $request->user()->id, 404);
        abort_if($movimiento && $movimiento->tarjeta_credito_id !== $tarjeta->id, 404);
    }
}
