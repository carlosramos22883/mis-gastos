<?php

namespace App\Http\Controllers;

use App\Exports\CompromisosExport;
use App\Models\Compromiso;
use App\Traits\Exportable;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CompromisoController extends Controller
{
    use Exportable;

    public function index(Request $request)
    {
        $sort = in_array($request->get('sort'), ['descripcion', 'monto', 'fecha_esperada', 'estado'], true) ? $request->get('sort') : 'fecha_esperada';
        $direction = $request->get('direction') === 'asc' ? 'asc' : 'desc';
        $query = $request->user()->compromisos()->orderBy($sort, $direction)->orderBy('id');
        if ($request->filled('search')) {
            $query->where('descripcion', 'like', '%'.$request->search.'%');
        }
        if ($request->filled('estado')) {
            $query->where('estado', $request->estado);
        }
        $compromisos = $query->paginate($request->integer('per_page', 10))->withQueryString();
        if ($request->ajax() || $request->wantsJson()) {
            return response()->json([
                'html' => view('compromisos._table', compact('compromisos'))->render(),
                'pagination' => $compromisos->links()->render(),
            ]);
        }

        return view('compromisos.index', compact('compromisos'));
    }

    public function create()
    {
        return view('compromisos._form', $this->dateLimits());
    }

    public function show(Compromiso $compromiso)
    {
        abort_unless($compromiso->user_id === auth()->id(), 404);

        return view('compromisos.show', compact('compromiso'));
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['saldo_pendiente'] = $data['monto'];
        $compromiso = $request->user()->compromisos()->create($data);

        return $this->response($request, $compromiso, 'Compromiso creado correctamente.');
    }

    public function edit(Compromiso $compromiso)
    {
        abort_unless($compromiso->user_id === auth()->id(), 404);

        return view('compromisos._form', array_merge(compact('compromiso'), $this->dateLimits()));
    }

    public function update(Request $request, Compromiso $compromiso)
    {
        abort_unless($compromiso->user_id === auth()->id(), 404);
        $compromiso->update($this->validated($request));

        return $this->response($request, $compromiso, 'Compromiso actualizado correctamente.');
    }

    public function destroy(Request $request, Compromiso $compromiso)
    {
        abort_unless($compromiso->user_id === auth()->id(), 404);
        $compromiso->update(['estado' => 'cancelado']);

        return response()->json(['success' => true, 'message' => 'Compromiso cancelado correctamente.']);
    }

    public function export(Request $request)
    {
        abort_unless($request->user()->can('compromisos.export'), 403);

        return $this->handleExport(
            $request,
            $request->user()->compromisos()->latest('fecha_esperada')->get(),
            CompromisosExport::class,
            'Compromisos',
            ['id' => 'ID', 'descripcion' => 'Descripción', 'monto' => 'Monto', 'fecha_esperada' => 'Fecha esperada', 'medio_pago' => 'Medio de pago', 'estado' => 'Estado'],
            'compromisos'
        );
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'descripcion' => ['required', 'string', 'max:255'],
            'monto' => ['required', 'numeric', 'gt:0'],
            'fecha_esperada' => ['required_if:recurrencia_tipo,unica', 'nullable', 'date', 'after_or_equal:today'],
            'medio_pago' => ['required', Rule::in(['efectivo', 'tarjeta', 'transferencia', 'por_definir'])],
            'recurrencia_tipo' => ['required', Rule::in(['unica', 'recurrente'])],
            'recurrencia_intervalo' => ['nullable', 'integer', 'min:1', 'required_if:recurrencia_tipo,recurrente'],
            'recurrencia_unidad' => ['nullable', Rule::in(['dia', 'semana', 'mes', 'anio', 'semanal', 'quincenal', 'mensual']), 'required_if:recurrencia_tipo,recurrente'],
            'recurrencia_ocurrencias' => ['nullable', 'integer', 'min:1'],
            'recurrencia_hasta' => ['nullable', 'date'],
        ]);
    }

    private function dateLimits(): array
    {
        $today = Carbon::today(auth()->user()->zona_horaria ?: config('app.timezone'));

        // Un compromiso puede programarse para cualquier ciclo futuro; queda en el ciclo de su fecha.
        return ['dateMin' => $today, 'dateMax' => $today->copy()->addYears(10)];
    }

    private function response(Request $request, Compromiso $compromiso, string $message)
    {
        $data = ['success' => true, 'message' => $message, 'highlight_id' => $compromiso->id, 'redirect_to_page' => 1];

        return $request->wantsJson() ? response()->json($data) : redirect()->route('compromisos.index')->with('success', $message);
    }
}
