<?php

namespace App\Http\Controllers;

use App\Exports\CompromisosExport;
use App\Http\Requests\CompromisoRequest;
use App\Models\CategoriaPersonal;
use App\Models\Compromiso;
use App\Services\CicloEfectivoService;
use App\Services\CompromisoService;
use App\Traits\Exportable;
use Carbon\Carbon;
use Illuminate\Http\Request;

class CompromisoController extends Controller
{
    use Exportable;

    public function __construct(private readonly CompromisoService $compromisos, private readonly CicloEfectivoService $ciclos) {}

    private function query(Request $request)
    {
        return $this->compromisos->consultar($request->user(), $request->only(['search', 'estado', 'periodo', 'sort', 'direction']));
    }

    public function index(Request $request)
    {
        $this->compromisos->sincronizar($request->user());
        [$hoy, $inicio] = $this->fechas($request);
        $compromisos = $this->query($request)->paginate($request->integer('per_page', 10))->withQueryString();

        if ($request->ajax() || $request->wantsJson()) {
            return response()->json([
                'html' => view('compromisos._table', compact('compromisos', 'hoy', 'inicio'))->render(),
                'pagination' => $compromisos->links()->render(),
            ]);
        }

        return view('compromisos.index', compact('compromisos', 'hoy', 'inicio'));
    }

    public function create(Request $request)
    {
        return view('compromisos._form', $this->formData($request));
    }

    public function show(Request $request, Compromiso $compromiso)
    {
        $this->own($request, $compromiso);
        [$hoy, $inicio] = $this->fechas($request);
        $compromiso->load('categoria', 'recurrente');
        $pagos = $compromiso->movimientos()->where('tipo', 'egreso')->latest('fecha')->latest('id')->get();

        return view('compromisos.show', compact('compromiso', 'pagos', 'hoy', 'inicio'));
    }

    public function store(CompromisoRequest $request)
    {
        $user = $request->user();

        if ($request->esRecurrente()) {
            $recurrente = $this->compromisos->crearRecurrente($user, $request->datosRecurrente());
            $compromiso = $recurrente->compromisos()->orderBy('fecha_esperada')->first();
            $message = $compromiso
                ? 'Compromiso recurrente creado. Se generaron los pagos de este ciclo.'
                : 'Compromiso recurrente creado. Su primer pago aparecerá en el ciclo que le corresponde.';

            return $this->response($request, $compromiso, $message);
        }

        $compromiso = $this->compromisos->crearUnico($user, $request->datosPago());

        return $this->response($request, $compromiso, 'Compromiso creado correctamente.');
    }

    public function edit(Request $request, Compromiso $compromiso)
    {
        $this->own($request, $compromiso);
        abort_unless($compromiso->estado === Compromiso::PENDIENTE, 403);

        return view('compromisos._form', array_merge($this->formData($request), compact('compromiso')));
    }

    /** Edita solo este pago; la regla recurrente (si existe) no cambia. */
    public function update(CompromisoRequest $request, Compromiso $compromiso)
    {
        $this->own($request, $compromiso);
        $this->compromisos->actualizarPago($compromiso, $request->datosPago());

        return $this->response($request, $compromiso, 'Compromiso actualizado correctamente.');
    }

    public function destroy(Request $request, Compromiso $compromiso)
    {
        $this->own($request, $compromiso);
        $serie = $request->input('alcance') === 'serie';
        $this->compromisos->cancelar($compromiso, $serie);

        return response()->json([
            'success' => true,
            'message' => $serie ? 'Se canceló este pago y los siguientes de la serie.' : 'Compromiso cancelado correctamente.',
        ]);
    }

    public function export(Request $request)
    {
        abort_unless($request->user()->can('compromisos.export'), 403);
        [$hoy, $inicio] = $this->fechas($request);
        $items = $this->query($request)->get()->each(fn (Compromiso $c) => $c->setAttribute('estado_visual', Compromiso::ESTADOS_VISUALES[$c->estadoVisual($hoy, $inicio)]));

        return $this->handleExport(
            $request,
            $items,
            CompromisosExport::class,
            'Compromisos',
            ['descripcion' => 'Descripción', 'monto' => 'Monto', 'saldo_pendiente' => 'Pendiente', 'fecha_esperada' => 'Fecha de pago', 'numero_cuota' => 'Cuota', 'estado_visual' => 'Estado'],
            'compromisos'
        );
    }

    private function formData(Request $request): array
    {
        return [
            'categorias' => CategoriaPersonal::where('user_id', $request->user()->id)->where('tipo', 'egreso')->where('activo', true)->orderBy('nombre')->pluck('nombre', 'id')->all(),
            'hoy' => $this->fechas($request)[0],
        ];
    }

    /** @return array{0: Carbon, 1: Carbon, 2: Carbon} hoy, inicio y fin del ciclo actual */
    private function fechas(Request $request): array
    {
        return $this->compromisos->fechas($request->user());
    }

    private function own(Request $request, Compromiso $compromiso): void
    {
        abort_unless($compromiso->user_id === $request->user()->id, 404);
    }

    private function response(Request $request, ?Compromiso $compromiso, string $message)
    {
        $data = array_filter(['success' => true, 'message' => $message, 'highlight_id' => $compromiso?->id, 'redirect_to_page' => 1], fn ($v) => $v !== null);

        return $request->wantsJson() ? response()->json($data) : redirect()->route('compromisos.index')->with('success', $message);
    }
}
