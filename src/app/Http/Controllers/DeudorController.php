<?php

namespace App\Http\Controllers;

use App\Http\Requests\DeudaPagoRequest;
use App\Http\Requests\DeudaRequest;
use App\Http\Requests\DeudorRequest;
use App\Models\Deuda;
use App\Models\DeudaPago;
use App\Models\Deudor;
use App\Services\DeudorService;
use Illuminate\Http\Request;

class DeudorController extends Controller
{
    public function __construct(private readonly DeudorService $deudores) {}

    public function index(Request $request)
    {
        $deudores = $this->deudores->listar($request->user());

        return view('deudores.index', compact('deudores'));
    }

    public function create()
    {
        return view('deudores._form');
    }

    public function store(DeudorRequest $request)
    {
        $deudor = $request->user()->deudores()->create($request->datos());

        return response()->json(['success' => true, 'message' => 'Deudor creado correctamente.', 'redirect' => route('deudores.show', $deudor)]);
    }

    public function show(Request $request, Deudor $deudor)
    {
        $this->own($request, $deudor);
        $deudor->load(['deudas' => fn ($q) => $q->withSum('pagos', 'monto')]);
        $this->deudores->resumir($deudor);
        $pagos = DeudaPago::with('deuda', 'movimientoCuenta.cuenta')->whereIn('deuda_id', $deudor->deudas->pluck('id'))
            ->orderByDesc('fecha')->orderByDesc('id')->get();

        return view('deudores.show', compact('deudor', 'pagos'));
    }

    public function edit(Request $request, Deudor $deudor)
    {
        $this->own($request, $deudor);

        return view('deudores._form', compact('deudor'));
    }

    public function update(DeudorRequest $request, Deudor $deudor)
    {
        $this->own($request, $deudor);
        $deudor->update($request->datos());

        return response()->json(['success' => true, 'message' => 'Deudor actualizado correctamente.']);
    }

    public function destroy(Request $request, Deudor $deudor)
    {
        $this->own($request, $deudor);
        $this->deudores->eliminarDeudor($deudor);

        return response()->json(['success' => true, 'message' => 'Deudor eliminado correctamente.', 'redirect' => route('deudores.index')]);
    }

    public function crearDeuda(Request $request, Deudor $deudor)
    {
        $this->own($request, $deudor);

        return view('deudores._deuda_form', ['deudor' => $deudor, 'origenes' => $this->origenes($request, true)]);
    }

    public function guardarDeuda(DeudaRequest $request, Deudor $deudor)
    {
        $this->own($request, $deudor);
        $this->deudores->registrarDeuda($request->user(), $deudor, $request->validated());

        return response()->json(['success' => true, 'message' => 'Deuda registrada.']);
    }

    public function eliminarDeuda(Request $request, Deuda $deuda)
    {
        abort_unless($deuda->esDelHogar($request->user()), 404);
        $this->deudores->eliminarDeuda($deuda);

        return response()->json(['success' => true, 'message' => 'Deuda eliminada.']);
    }

    public function crearPago(Request $request, Deudor $deudor)
    {
        $this->own($request, $deudor);
        $deudor->load(['deudas' => fn ($q) => $q->withSum('pagos', 'monto')]);
        $this->deudores->resumir($deudor);

        return view('deudores._pago_form', ['deudor' => $deudor, 'destinos' => $this->origenes($request, false)]);
    }

    public function guardarPago(DeudaPagoRequest $request, Deudor $deudor)
    {
        $this->own($request, $deudor);
        $pagos = $this->deudores->registrarPago($request->user(), $deudor, $request->validated());

        return response()->json(['success' => true, 'message' => $pagos->count() > 1
            ? "Pago aplicado a {$pagos->count()} deudas, de la más antigua a la más reciente."
            : 'Pago registrado.']);
    }

    public function eliminarPago(Request $request, DeudaPago $pago)
    {
        abort_unless($pago->esDelHogar($request->user()), 404);
        $this->deudores->eliminarPago($pago);

        return response()->json(['success' => true, 'message' => 'Pago eliminado.']);
    }

    /** Opciones de dónde sale (deuda) o a dónde entra (pago) el dinero. */
    private function origenes(Request $request, bool $conTarjetas): array
    {
        $user = $request->user();
        $opciones = ['efectivo' => 'Efectivo'];
        foreach ($user->cuentas()->where('activo', true)->orderBy('nombre')->get() as $cuenta) {
            $opciones['cuenta:'.$cuenta->id] = $cuenta->nombre;
        }
        if ($conTarjetas) {
            foreach ($user->tarjetasCredito()->where('activo', true)->orderBy('nombre')->get() as $tarjeta) {
                $opciones['tarjeta:'.$tarjeta->id] = 'Tarjeta '.$tarjeta->nombre;
            }
        }

        return $opciones + ['ninguno' => 'Solo anotar (el dinero ya está registrado)'];
    }

    private function own(Request $request, Deudor $deudor): void
    {
        abort_unless($deudor->esDelHogar($request->user()), 404);
    }
}
