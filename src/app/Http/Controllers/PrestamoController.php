<?php

namespace App\Http\Controllers;

use App\Http\Requests\PrestamoPagoRequest;
use App\Http\Requests\PrestamoRequest;
use App\Models\Banco;
use App\Models\Prestamo;
use App\Models\PrestamoPago;
use App\Services\CicloEfectivoService;
use App\Services\CuentaService;
use App\Services\PrestamoService;
use Illuminate\Http\Request;

class PrestamoController extends Controller
{
    public function __construct(private readonly PrestamoService $prestamos) {}

    public function index(Request $request)
    {
        $prestamos = $request->user()->prestamos()->with('banco', 'cargos')->orderByDesc('activo')->orderBy('nombre')->get()
            ->each(fn (Prestamo $p) => $p->setAttribute('resumen', $this->prestamos->resumen($p)));

        return view('prestamos.index', compact('prestamos'));
    }

    public function create()
    {
        return view('prestamos._form', $this->formData());
    }

    public function store(PrestamoRequest $request)
    {
        $prestamo = $this->prestamos->crear($request->user(), $request->datos(), $request->cargos() ?? [], $request->boolean('crear_compromiso'));

        return response()->json(['success' => true, 'message' => 'Préstamo registrado.', 'redirect' => route('prestamos.show', $prestamo)]);
    }

    public function show(Request $request, Prestamo $prestamo)
    {
        $this->own($request, $prestamo);
        $prestamo->load('banco', 'cargos', 'pagos.movimientoCuenta.cuenta', 'recurrente');

        return view('prestamos.show', [
            'prestamo' => $prestamo,
            'resumen' => $this->prestamos->resumen($prestamo),
            'escenarios' => $this->prestamos->escenarios($prestamo),
        ]);
    }

    /** Simulador: devuelve los escenarios con lo que el usuario quiera probar. */
    public function simular(Request $request, Prestamo $prestamo)
    {
        $this->own($request, $prestamo);
        $op = $request->validate([
            'extra_mensual' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'abono' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'modo' => ['nullable', 'in:plazo,cuota'],
            'cuota' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
        ]);

        return view('prestamos._escenarios', ['prestamo' => $prestamo, 'escenarios' => $this->prestamos->escenarios($prestamo, array_filter($op, fn ($v) => $v !== null && $v !== ''))]);
    }

    public function edit(Request $request, Prestamo $prestamo)
    {
        $this->own($request, $prestamo);
        $prestamo->load('cargos');

        return view('prestamos._form', $this->formData() + ['prestamo' => $prestamo]);
    }

    public function update(PrestamoRequest $request, Prestamo $prestamo)
    {
        $this->own($request, $prestamo);
        $this->prestamos->actualizar($prestamo, $request->datos(), $request->cargos());

        return response()->json(['success' => true, 'message' => 'Préstamo actualizado.']);
    }

    public function destroy(Request $request, Prestamo $prestamo)
    {
        $this->own($request, $prestamo);
        $this->prestamos->eliminar($prestamo);

        return response()->json(['success' => true, 'message' => 'Préstamo eliminado.', 'redirect' => route('prestamos.index')]);
    }

    public function crearPago(Request $request, Prestamo $prestamo)
    {
        $this->own($request, $prestamo);

        return view('prestamos._pago_form', $this->pagoData($request, $prestamo));
    }

    public function guardarPago(PrestamoPagoRequest $request, Prestamo $prestamo)
    {
        $this->own($request, $prestamo);
        $this->prestamos->registrarPago($request->user(), $prestamo, $request->validated());

        return response()->json(['success' => true, 'message' => 'Pago registrado.']);
    }

    public function eliminarPago(Request $request, PrestamoPago $pago)
    {
        abort_unless($pago->esDelHogar($request->user()), 404);
        $this->prestamos->eliminarPago($pago);

        return response()->json(['success' => true, 'message' => 'Pago eliminado.']);
    }

    /** Datos del formulario de pago (también lo usa "Pagar" en Compromisos para las cuotas de un préstamo). */
    public function pagoData(Request $request, Prestamo $prestamo): array
    {
        $user = $request->user();
        $cuentas = app(CuentaService::class);
        $origenes = ['efectivo' => 'Efectivo ($ '.number_format(app(CicloEfectivoService::class)->balance($user), 2).')'];
        foreach ($user->cuentas()->where('activo', true)->orderBy('nombre')->get() as $cuenta) {
            $origenes['cuenta:'.$cuenta->id] = $cuenta->nombre.' ($ '.number_format($cuentas->saldo($cuenta), 2).')';
        }
        $origenes += ['planilla' => 'Descuento de planilla (no mueve dinero aquí)', 'ninguno' => 'Solo anotar (ya está registrado)'];
        $origen = match ($prestamo->medio_pago) {
            'planilla' => 'planilla',
            'efectivo' => 'efectivo',
            'transferencia' => array_keys($origenes)[1] ?? 'efectivo',
            default => 'efectivo',
        };

        return ['prestamo' => $prestamo, 'sugerencia' => $this->prestamos->sugerencia($prestamo), 'saldo' => $this->prestamos->saldo($prestamo),
            'origenes' => $origenes, 'origen' => $origen];
    }

    private function formData(): array
    {
        return ['bancos' => Banco::where('activo', true)->orderBy('nombre')->pluck('nombre', 'id')->all()];
    }

    private function own(Request $request, Prestamo $prestamo): void
    {
        abort_unless($prestamo->esDelHogar($request->user()), 404);
    }
}
