<?php

namespace App\Http\Controllers;

use App\Http\Requests\FuenteIngresoRequest;
use App\Http\Requests\IngresoRequest;
use App\Models\FuenteIngreso;
use App\Models\Ingreso;
use App\Services\IngresoService;
use Carbon\Carbon;
use Illuminate\Http\Request;

/** Ingresos con desglose: fuentes (sueldos, servicios…) y cada pago recibido. */
class IngresoController extends Controller
{
    public function __construct(private readonly IngresoService $ingresos) {}

    public function index(Request $request)
    {
        $hoy = $this->hoy($request);
        $fuentes = $request->user()->fuentesIngreso()->with('user')->orderByDesc('activo')->orderBy('nombre')->get()
            ->each(function (FuenteIngreso $f) use ($hoy) {
                $f->setAttribute('ultimo', $f->ingresos()->latest('fecha')->latest('id')->first());
                $f->setAttribute('proxima', $this->ingresos->proximaFecha($f, $hoy));
                $f->setAttribute('neto_anio', round((float) $f->ingresos()->whereYear('fecha', $hoy->year)->sum('neto'), 2));
            });

        return view('ingresos.index', compact('fuentes'));
    }

    public function create(Request $request)
    {
        return view('ingresos._form', $this->formData($request));
    }

    public function store(FuenteIngresoRequest $request)
    {
        $fuente = $this->ingresos->crearFuente($request->user(), $request->datos(), $request->conceptos() ?? []);

        return response()->json(['success' => true, 'message' => 'Fuente de ingreso creada.', 'redirect' => route('ingresos.show', $fuente)]);
    }

    public function show(Request $request, FuenteIngreso $fuente)
    {
        $this->own($request, $fuente);
        $anio = (int) $request->integer('anio', $this->hoy($request)->year);
        $fuente->load('conceptos.prestamo', 'user');
        $recibos = $fuente->ingresos()->with('lineas', 'movimientoCuenta.cuenta')->orderByDesc('fecha')->orderByDesc('id')->get();

        return view('ingresos.show', ['fuente' => $fuente, 'recibos' => $recibos, 'resumen' => $this->ingresos->resumenAnual($fuente, $anio),
            'anios' => $recibos->toBase()->map(fn ($r) => $r->fecha->year)->push($anio)->unique()->sortDesc()->values()]);
    }

    public function edit(Request $request, FuenteIngreso $fuente)
    {
        $this->own($request, $fuente);
        $fuente->load('conceptos');

        return view('ingresos._form', $this->formData($request) + ['fuente' => $fuente]);
    }

    public function update(FuenteIngresoRequest $request, FuenteIngreso $fuente)
    {
        $this->own($request, $fuente);
        $this->ingresos->actualizarFuente($fuente, $request->datos(), $request->conceptos());

        return response()->json(['success' => true, 'message' => 'Fuente de ingreso actualizada.']);
    }

    public function destroy(Request $request, FuenteIngreso $fuente)
    {
        $this->own($request, $fuente);
        $this->ingresos->eliminarFuente($fuente);

        return response()->json(['success' => true, 'message' => 'Fuente de ingreso eliminada.', 'redirect' => route('ingresos.index')]);
    }

    public function crearRecibo(Request $request, FuenteIngreso $fuente)
    {
        $this->own($request, $fuente);
        $fuente->load('conceptos');

        return view('ingresos._recibo_form', ['fuente' => $fuente, 'propuesta' => $this->ingresos->propuesta($fuente),
            'destinos' => $this->destinos($request) + ['ninguno' => 'Solo anotar (ya está registrado)'], 'prestamos' => $this->prestamos($request)]);
    }

    public function guardarRecibo(IngresoRequest $request, FuenteIngreso $fuente)
    {
        $this->own($request, $fuente);
        $ingreso = $this->ingresos->registrar($request->user(), $fuente, $request->validated());

        return response()->json(['success' => true, 'message' => 'Pago registrado: neto $ '.number_format((float) $ingreso->neto, 2).'.']);
    }

    public function eliminarRecibo(Request $request, Ingreso $ingreso)
    {
        abort_unless($ingreso->esDelHogar($request->user()), 404);
        $this->ingresos->eliminar($ingreso);

        return response()->json(['success' => true, 'message' => 'Pago eliminado.']);
    }

    private function formData(Request $request): array
    {
        return ['destinos' => $this->destinos($request), 'prestamos' => $this->prestamos($request)];
    }

    private function destinos(Request $request): array
    {
        $opciones = ['efectivo' => 'Efectivo'];
        foreach ($request->user()->cuentas()->where('activo', true)->orderBy('nombre')->get() as $cuenta) {
            $opciones['cuenta:'.$cuenta->id] = $cuenta->nombre;
        }

        return $opciones;
    }

    private function prestamos(Request $request): array
    {
        return $request->user()->prestamos()->where('activo', true)->orderBy('nombre')->pluck('nombre', 'id')->all();
    }

    private function hoy(Request $request): Carbon
    {
        return Carbon::parse(Carbon::now($request->user()->zona_horaria ?: config('app.timezone'))->toDateString());
    }

    private function own(Request $request, FuenteIngreso $fuente): void
    {
        abort_unless($fuente->esDelHogar($request->user()), 404);
    }
}
