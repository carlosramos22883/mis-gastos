<?php

namespace App\Http\Controllers;

use App\Http\Requests\MovimientoCuentaRequest;
use App\Models\CategoriaPersonal;
use App\Models\Cuenta;
use App\Models\MovimientoCuenta;
use App\Services\CicloEfectivoService;
use App\Services\CuentaService;
use Illuminate\Http\Request;

class MovimientoCuentaController extends Controller
{
    public function __construct(private readonly CuentaService $cuentas) {}

    public function create(Request $request, Cuenta $cuenta)
    {
        $this->own($request, $cuenta);
        abort_unless($cuenta->activo, 403, 'La cuenta está inactiva.');

        return view('cuentas._movimiento_form', ['cuenta' => $cuenta] + $this->formData($request));
    }

    public function store(MovimientoCuentaRequest $request, Cuenta $cuenta)
    {
        $this->own($request, $cuenta);
        abort_unless($cuenta->activo, 403, 'La cuenta está inactiva.');
        $movimiento = $this->cuentas->registrarMovimiento($cuenta, $request->validated());

        return response()->json(['success' => true, 'message' => 'Movimiento registrado correctamente.', 'highlight_id' => $movimiento->id, 'redirect_to_page' => 1]);
    }

    public function edit(Request $request, Cuenta $cuenta, MovimientoCuenta $movimiento)
    {
        $this->own($request, $cuenta, $movimiento);
        abort_unless($this->cuentas->esEditable($movimiento), 403);

        return view('cuentas._movimiento_form', compact('cuenta', 'movimiento') + $this->formData($request));
    }

    public function update(MovimientoCuentaRequest $request, Cuenta $cuenta, MovimientoCuenta $movimiento)
    {
        $this->own($request, $cuenta, $movimiento);
        $this->cuentas->actualizarMovimiento($movimiento, $request->validated());

        return response()->json(['success' => true, 'message' => 'Movimiento actualizado correctamente.', 'highlight_id' => $movimiento->id]);
    }

    public function destroy(Request $request, Cuenta $cuenta, MovimientoCuenta $movimiento)
    {
        $this->own($request, $cuenta, $movimiento);
        $this->cuentas->eliminarMovimiento($movimiento);

        return response()->json(['success' => true, 'message' => 'Movimiento eliminado correctamente.']);
    }

    private function formData(Request $request): array
    {
        [$inicio, $fin] = app(CicloEfectivoService::class)->cicloConsultado($request->user());

        return [
            'categorias' => CategoriaPersonal::where('user_id', $request->user()->id)->where('activo', true)
                ->where('nombre', '!=', CicloEfectivoService::CATEGORIA_SALDO_INICIAL)->orderBy('nombre')->get(),
            'cycleStart' => $inicio,
            'cycleEnd' => $fin,
        ];
    }

    private function own(Request $request, Cuenta $cuenta, ?MovimientoCuenta $movimiento = null): void
    {
        abort_unless($cuenta->user_id === $request->user()->id, 404);
        abort_if($movimiento && $movimiento->cuenta_id !== $cuenta->id, 404);
    }
}
