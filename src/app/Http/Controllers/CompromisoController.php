<?php

namespace App\Http\Controllers;

use App\Exports\CompromisosExport;
use App\Models\CategoriaPersonal;
use App\Models\Compromiso;
use App\Services\CicloEfectivoService;
use App\Services\CompromisoService;
use App\Traits\Exportable;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CompromisoController extends Controller
{
    use Exportable;

    public function __construct(private readonly CompromisoService $compromisos, private readonly CicloEfectivoService $ciclos) {}

    private function query(Request $request)
    {
        $user = $request->user();
        [$hoy, $inicio, $fin] = $this->fechas($request);
        $sort = in_array($request->get('sort'), ['descripcion', 'monto', 'saldo_pendiente', 'fecha_esperada'], true) ? $request->get('sort') : 'fecha_esperada';
        $direction = $request->get('direction') === 'desc' ? 'desc' : 'asc';
        $periodo = $request->input('periodo') ?: 'actual';

        return $user->compromisos()->with('categoria', 'recurrente')
            ->when($request->filled('search'), fn ($q) => $q->where('descripcion', 'like', '%'.$request->search.'%'))
            ->when($request->filled('estado'), fn ($q) => $q->estadoVisual($request->estado, $hoy, $inicio))
            // Ciclo actual: lo de este ciclo más lo que quedó pendiente de ciclos anteriores
            ->when($periodo === 'actual', fn ($q) => $q
                ->whereDate('ciclo_inicio', '<=', $fin->toDateString())
                ->where(fn ($q) => $q->whereDate('ciclo_inicio', '>=', $inicio->toDateString())->orWhere('estado', Compromiso::PENDIENTE)))
            ->when($periodo === 'proximos', fn ($q) => $q->whereDate('ciclo_inicio', '>', $fin->toDateString()))
            ->orderByRaw('fecha_esperada IS NULL')
            ->orderBy($sort, $direction)
            ->orderBy('id');
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

    public function store(Request $request)
    {
        $user = $request->user();

        if ($request->input('recurrencia_tipo') === 'recurrente') {
            $recurrente = $this->compromisos->crearRecurrente($user, $this->validatedRecurrente($request));
            $compromiso = $recurrente->compromisos()->orderBy('fecha_esperada')->first();
            $message = $compromiso
                ? 'Compromiso recurrente creado. Se generaron los pagos de este ciclo.'
                : 'Compromiso recurrente creado. Su primer pago aparecerá en el ciclo que le corresponde.';

            return $this->response($request, $compromiso, $message);
        }

        $compromiso = $this->compromisos->crearUnico($user, $this->validated($request, ['fecha_esperada' => ['nullable', 'date']]));

        return $this->response($request, $compromiso, 'Compromiso creado correctamente.');
    }

    public function edit(Request $request, Compromiso $compromiso)
    {
        $this->own($request, $compromiso);
        abort_unless($compromiso->estado === Compromiso::PENDIENTE, 403);

        return view('compromisos._form', array_merge($this->formData($request), compact('compromiso')));
    }

    /** Edita solo este pago; la regla recurrente (si existe) no cambia. */
    public function update(Request $request, Compromiso $compromiso)
    {
        $this->own($request, $compromiso);
        abort_unless($compromiso->estado === Compromiso::PENDIENTE, 403);
        $data = $this->validated($request, ['fecha_esperada' => ['nullable', 'date']]);

        $pagado = $compromiso->montoPagado();
        if ((float) $data['monto'] < $pagado) {
            return $this->validationError($request, 'monto', 'El monto no puede ser menor a lo ya pagado ('.number_format($pagado, 2).').');
        }

        $fecha = isset($data['fecha_esperada']) ? Carbon::parse($data['fecha_esperada']) : null;
        $compromiso->update(array_merge($data, [
            'saldo_pendiente' => round((float) $data['monto'] - $pagado, 2),
            'ciclo_inicio' => $fecha ? $this->ciclos->cycleFor($request->user(), $fecha)[0] : $compromiso->ciclo_inicio,
        ]));

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

    /** Valida los campos comunes a todo compromiso más las reglas adicionales indicadas. */
    private function validated(Request $request, array $extra, array $messages = []): array
    {
        return $request->validate(array_merge([
            'descripcion' => ['required', 'string', 'max:255'],
            'comentario' => ['nullable', 'string', 'max:1000'],
            'monto' => ['required', 'numeric', 'gt:0', 'regex:/^\d+(?:\.\d{1,2})?$/'],
            'medio_pago' => ['required', Rule::in(['efectivo', 'tarjeta', 'transferencia', 'por_definir'])],
            'categoria_personal_id' => ['nullable', 'integer', Rule::exists('categorias_personales', 'id')->where(fn ($q) => $q->where('user_id', $request->user()->id)->where('tipo', 'egreso')->where('activo', true))],
        ], $extra), array_merge(['monto.regex' => 'El monto debe tener máximo dos decimales y ser positivo.'], $messages));
    }

    private function validatedRecurrente(Request $request): array
    {
        $data = $this->validated($request, [
            'frecuencia' => ['required', Rule::in(['semanal', 'quincenal', 'mensual'])],
            'dia_semana' => ['nullable', 'required_if:frecuencia,semanal', 'integer', 'between:0,6'],
            'dia_pago' => ['nullable', 'required_unless:frecuencia,semanal', 'integer', 'between:1,31'],
            'dia_pago_2' => ['nullable', 'required_if:frecuencia,quincenal', 'integer', 'between:1,31', 'different:dia_pago'],
            'fecha_inicio' => ['required', 'date'],
            'finalizacion' => ['required', Rule::in(['indefinido', 'fecha', 'cuotas'])],
            'fecha_fin' => ['nullable', 'required_if:finalizacion,fecha', 'date', 'after_or_equal:fecha_inicio'],
            'total_cuotas' => ['nullable', 'required_if:finalizacion,cuotas', 'integer', 'min:1', 'max:600'],
        ], ['dia_pago_2.different' => 'Los dos días de pago deben ser distintos.']);

        if ($data['frecuencia'] !== 'semanal') {
            $data['dia_semana'] = null;
        } else {
            $data['dia_pago'] = null;
        }
        if ($data['frecuencia'] !== 'quincenal') {
            $data['dia_pago_2'] = null;
        }
        if ($data['finalizacion'] !== 'fecha') {
            $data['fecha_fin'] = null;
        }
        if ($data['finalizacion'] !== 'cuotas') {
            $data['total_cuotas'] = null;
        }
        unset($data['finalizacion']);

        return $data;
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
        $user = $request->user();
        [$inicio, $fin] = $this->ciclos->cycleFor($user);

        return [Carbon::today($user->zona_horaria ?: config('app.timezone')), $inicio, $fin];
    }

    private function own(Request $request, Compromiso $compromiso): void
    {
        abort_unless($compromiso->user_id === $request->user()->id, 404);
    }

    private function validationError(Request $request, string $field, string $message)
    {
        return $request->wantsJson()
            ? response()->json(['message' => $message, 'errors' => [$field => [$message]]], 422)
            : back()->withErrors([$field => $message])->withInput();
    }

    private function response(Request $request, ?Compromiso $compromiso, string $message)
    {
        $data = array_filter(['success' => true, 'message' => $message, 'highlight_id' => $compromiso?->id, 'redirect_to_page' => 1], fn ($v) => $v !== null);

        return $request->wantsJson() ? response()->json($data) : redirect()->route('compromisos.index')->with('success', $message);
    }
}
