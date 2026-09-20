<?php

namespace App\Http\Controllers;

use App\Exports\TarjetasCreditoExport;
use App\Models\Banco;
use App\Models\MarcaRed;
use App\Models\TarjetaCredito;
use App\Traits\Exportable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TarjetaCreditoController extends Controller
{
    use Exportable;

    public function index(Request $request)
    {
        $query = $request->user()->tarjetasCredito()->with(['banco', 'marcaRed']);
        $sort = in_array($request->get('sort'), ['nombre', 'limite_credito', 'saldo_actual', 'activo'], true) ? $request->get('sort') : 'nombre';
        $direction = $request->get('direction') === 'desc' ? 'desc' : 'asc';
        $query->orderBy($sort, $direction)->orderBy('id');
        $query->when($request->filled('search'), fn ($q) => $q->where('nombre', 'like', '%'.$request->search.'%'));
        $query->when($request->filled('activo'), fn ($q) => $q->where('activo', $request->activo === '1'));
        $tarjetas = $query->paginate($request->integer('per_page', 10))->withQueryString();

        if ($request->ajax() || $request->wantsJson()) {
            return response()->json([
                'html' => view('tarjetas._table', compact('tarjetas'))->render(),
                'pagination' => $tarjetas->links()->render(),
            ]);
        }

        return view('tarjetas.index', compact('tarjetas'));
    }

    public function create()
    {
        return view('tarjetas._form', $this->formData());
    }

    public function store(Request $request)
    {
        $tarjeta = $request->user()->tarjetasCredito()->create($this->validated($request));

        return $this->response($request, $tarjeta, 'Tarjeta creada correctamente.');
    }

    public function edit(Request $request, TarjetaCredito $tarjeta)
    {
        $this->own($request, $tarjeta);

        return view('tarjetas._form', array_merge(['tarjeta' => $tarjeta], $this->formData()));
    }

    public function update(Request $request, TarjetaCredito $tarjeta)
    {
        $this->own($request, $tarjeta);
        $tarjeta->update($this->validated($request));

        return $this->response($request, $tarjeta, 'Tarjeta actualizada correctamente.');
    }

    public function destroy(Request $request, TarjetaCredito $tarjeta)
    {
        $this->own($request, $tarjeta);
        $tarjeta->delete();

        return response()->json(['success' => true, 'message' => 'Tarjeta eliminada correctamente.']);
    }

    public function export(Request $request)
    {
        return $this->handleExport($request, $request->user()->tarjetasCredito()->with(['banco', 'marcaRed'])->orderBy('nombre')->get(), TarjetasCreditoExport::class, 'Tarjetas de crédito', [
            'nombre' => 'Nombre',
            'banco' => 'Banco',
            'marcaRed' => 'Marca',
            'ultimos_digitos' => 'Últimos dígitos',
            'limite_credito' => 'Límite de crédito',
            'saldo_actual' => 'Saldo actual',
            'activo' => 'Estado',
        ], 'tarjetas_credito');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'nombre' => ['required', 'string', 'max:100'],
            'banco_id' => ['required', 'integer', Rule::exists('bancos', 'id')->where('activo', true)],
            'marca_red_id' => ['required', 'integer', Rule::exists('marca_reds', 'id')->where('activo', true)],
            'ultimos_digitos' => ['nullable', 'digits:4', 'regex:/^\d{4}$/'],
            'limite_credito' => ['required', 'numeric', 'min:0', 'regex:/^\d+(?:\.\d{1,2})?$/'],
            'saldo_actual' => ['required', 'numeric', 'min:0', 'lte:limite_credito', 'regex:/^\d+(?:\.\d{1,2})?$/'],
            'dia_corte' => ['required', 'integer', 'between:1,31'],
            'dia_pago' => ['required', 'integer', 'between:1,31'],
            'activo' => ['required', 'boolean'],
        ]);
    }

    private function formData(): array
    {
        return [
            'bancos' => Banco::where('activo', true)->orderBy('nombre')->get()->mapWithKeys(fn ($banco) => [
                $banco->id => ['label' => $banco->nombre, 'logo' => $banco->logo ? asset('storage/'.$banco->logo) : null],
            ]),
            'marcas' => MarcaRed::where('activo', true)->orderBy('nombre')->get()->mapWithKeys(fn ($marca) => [
                $marca->id => ['label' => $marca->nombre, 'logo' => $marca->logo ? asset('storage/'.$marca->logo) : null],
            ]),
        ];
    }

    private function own(Request $request, TarjetaCredito $tarjeta): void
    {
        abort_unless($tarjeta->user_id === $request->user()->id, 404);
    }

    private function response(Request $request, TarjetaCredito $tarjeta, string $message)
    {
        $data = ['success' => true, 'message' => $message, 'highlight_id' => $tarjeta->id, 'redirect_to_page' => 1];

        return $request->wantsJson() ? response()->json($data) : redirect()->route('tarjetas.index')->with('success', $message);
    }
}
