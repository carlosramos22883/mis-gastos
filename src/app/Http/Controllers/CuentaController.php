<?php

namespace App\Http\Controllers;

use App\Exports\MovimientosEfectivoExport;
use App\Http\Requests\CuentaRequest;
use App\Models\Banco;
use App\Models\CategoriaPersonal;
use App\Models\Cuenta;
use App\Models\TipoCuenta;
use App\Services\CicloEfectivoService;
use App\Services\CuentaService;
use App\Traits\Exportable;
use Carbon\Carbon;
use Illuminate\Http\Request;

class CuentaController extends Controller
{
    use Exportable;

    public function __construct(private readonly CuentaService $cuentas, private readonly CicloEfectivoService $ciclos) {}

    public function index(Request $request)
    {
        $cuentas = $request->user()->cuentas()->with('banco', 'tipoCuenta')->orderByDesc('activo')->orderBy('nombre')->get()
            ->each(fn (Cuenta $c) => $c->setAttribute('saldo_actual', $this->cuentas->saldo($c)));

        if ($request->ajax() || $request->wantsJson()) {
            return response()->json(['html' => view('cuentas._grid', compact('cuentas'))->render()]);
        }

        return view('cuentas.index', compact('cuentas'));
    }

    public function create()
    {
        return view('cuentas._form', $this->formData());
    }

    public function store(CuentaRequest $request)
    {
        $cuenta = $request->user()->cuentas()->create($request->validated());

        return $this->response($request, 'Cuenta creada correctamente.', $cuenta);
    }

    public function show(Request $request, Cuenta $cuenta)
    {
        $this->own($request, $cuenta);
        [$inicio, $fin] = $this->ciclo($request);
        $movimientos = $this->query($request, $cuenta)->paginate($request->integer('per_page', 10))->withQueryString();
        $resumen = $this->cuentas->resumen($cuenta, $inicio, $fin);
        [$inicioActual] = $this->ciclos->cicloConsultado($request->user());
        $esActual = $inicio->toDateString() === $inicioActual->toDateString();

        if ($request->ajax() || $request->wantsJson()) {
            return response()->json([
                'html' => view('cuentas._movimientos', compact('cuenta', 'movimientos', 'inicioActual'))->render(),
                'pagination' => $movimientos->links()->render(),
                // Las tarjetas de resumen se actualizan con cada recarga de la tabla (data-cash-card)
                ...array_map(fn (float $v) => number_format($v, 2, '.', ''), $resumen),
            ]);
        }

        $primera = max($cuenta->fecha_inicial->toDateString(), ($this->ciclos->primeraFecha($request->user()) ?? $cuenta->fecha_inicial)->toDateString());
        $anterior = $inicio->toDateString() <= $primera ? null : $inicio->copy()->subDay()->toDateString();
        $siguiente = $esActual ? null : $fin->copy()->addDay()->toDateString();
        $categorias = CategoriaPersonal::where('hogar_id', $request->user()->hogar_id)->where('activo', true)
            ->where('nombre', '!=', CicloEfectivoService::CATEGORIA_SALDO_INICIAL)->orderBy('nombre')->pluck('nombre', 'id')->all();

        return view('cuentas.show', compact('cuenta', 'movimientos', 'resumen', 'inicio', 'fin', 'esActual', 'anterior', 'siguiente', 'categorias', 'inicioActual'));
    }

    public function edit(Request $request, Cuenta $cuenta)
    {
        $this->own($request, $cuenta);

        return view('cuentas._form', $this->formData() + compact('cuenta'));
    }

    public function update(CuentaRequest $request, Cuenta $cuenta)
    {
        $this->own($request, $cuenta);
        $cuenta->update($request->validated());

        return $this->response($request, 'Cuenta actualizada correctamente.', $cuenta);
    }

    public function destroy(Request $request, Cuenta $cuenta)
    {
        $this->own($request, $cuenta);
        $this->cuentas->eliminar($cuenta);

        return response()->json(['success' => true, 'message' => 'Cuenta eliminada correctamente.']);
    }

    public function export(Request $request, Cuenta $cuenta)
    {
        $this->own($request, $cuenta);

        return $this->handleExport(
            $request,
            $this->query($request, $cuenta)->get(),
            MovimientosEfectivoExport::class,
            'Movimientos de '.$cuenta->nombre,
            ['id' => 'ID', 'descripcion' => 'Descripción', 'monto' => 'Monto', 'fecha' => 'Fecha', 'tipo' => 'Tipo'],
            'cuenta_'.$cuenta->id
        );
    }

    private function query(Request $request, Cuenta $cuenta)
    {
        [$inicio, $fin] = $this->ciclo($request);

        return $this->cuentas->consultarMovimientos($cuenta, $inicio, $fin, $request->only(['search', 'tipo', 'categoria', 'sort', 'direction']));
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function ciclo(Request $request): array
    {
        return $this->ciclos->cicloConsultado($request->user(), $request->get('cycle')) ?? abort(404);
    }

    private function formData(): array
    {
        return [
            'tipos' => TipoCuenta::where('activo', true)->orderBy('nombre')->pluck('nombre', 'id')->all(),
            'bancos' => Banco::where('activo', true)->orderBy('nombre')->pluck('nombre', 'id')->all(),
        ];
    }

    private function own(Request $request, Cuenta $cuenta): void
    {
        abort_unless($cuenta->esDelHogar($request->user()), 404);
    }

    private function response(Request $request, string $message, Cuenta $cuenta)
    {
        return $request->wantsJson()
            ? response()->json(['success' => true, 'message' => $message, 'highlight_id' => $cuenta->id])
            : redirect()->route('cuentas.show', $cuenta)->with('success', $message);
    }
}
