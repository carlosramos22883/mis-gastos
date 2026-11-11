<?php

namespace App\Http\Controllers;

use App\Http\Requests\CargoFijoRequest;
use App\Models\CargoFijoTarjeta;
use App\Models\CategoriaPersonal;
use App\Models\TarjetaCredito;
use App\Services\TarjetaService;
use Illuminate\Http\Request;

/** Cargos fijos de una tarjeta: cada mes, el día indicado, se crea solo su movimiento (TarjetaService::aplicarCargosFijos). */
class CargoFijoController extends Controller
{
    public function __construct(private readonly TarjetaService $tarjetas) {}

    public function create(Request $request, TarjetaCredito $tarjeta)
    {
        $this->own($request, $tarjeta);

        return view('tarjetas._cargo_fijo_form', $this->formData($request, $tarjeta));
    }

    public function store(CargoFijoRequest $request, TarjetaCredito $tarjeta)
    {
        $this->own($request, $tarjeta);
        $tarjeta->cargosFijos()->create($request->datos() + ['user_id' => $request->user()->id]);
        $this->tarjetas->generarEstados($tarjeta); // si ya tocaba cobrarlo, su movimiento se crea de una vez

        return response()->json(['success' => true, 'message' => 'Cargo fijo agregado: se registrará solo cada mes el día indicado.']);
    }

    public function edit(Request $request, TarjetaCredito $tarjeta, CargoFijoTarjeta $cargo)
    {
        $this->own($request, $tarjeta, $cargo);

        return view('tarjetas._cargo_fijo_form', $this->formData($request, $tarjeta) + compact('cargo'));
    }

    public function update(CargoFijoRequest $request, TarjetaCredito $tarjeta, CargoFijoTarjeta $cargo)
    {
        $this->own($request, $tarjeta, $cargo);
        $cargo->update($request->datos());
        $this->tarjetas->generarEstados($tarjeta);

        return response()->json(['success' => true, 'message' => 'Cargo fijo actualizado: aplica desde su próximo cobro.']);
    }

    public function destroy(Request $request, TarjetaCredito $tarjeta, CargoFijoTarjeta $cargo)
    {
        $this->own($request, $tarjeta, $cargo);
        $cargo->delete(); // lo ya cobrado queda como movimiento de la tarjeta

        return response()->json(['success' => true, 'message' => 'Cargo fijo eliminado: ya no se cobrará en los próximos meses.']);
    }

    private function formData(Request $request, TarjetaCredito $tarjeta): array
    {
        return [
            'tarjeta' => $tarjeta,
            'hoy' => now($request->user()->zona_horaria ?: config('app.timezone'))->toDateString(),
            'categorias' => CategoriaPersonal::where('hogar_id', $request->user()->hogar_id)->where('activo', true)->where('tipo', 'egreso')->orderBy('nombre')->pluck('nombre', 'id')->all(),
        ];
    }

    private function own(Request $request, TarjetaCredito $tarjeta, ?CargoFijoTarjeta $cargo = null): void
    {
        abort_unless($tarjeta->esDelHogar($request->user()), 404);
        abort_if($cargo && $cargo->tarjeta_credito_id !== $tarjeta->id, 404);
    }
}
