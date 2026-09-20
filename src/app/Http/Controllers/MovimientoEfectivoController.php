<?php

namespace App\Http\Controllers;

use App\Exports\MovimientosEfectivoExport;
use App\Models\CategoriaPersonal;
use App\Models\Compromiso;
use App\Models\MovimientoEfectivo;
use App\Models\User;
use App\Services\CicloEfectivoService;
use App\Traits\Exportable;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MovimientoEfectivoController extends Controller
{
    use Exportable;

    private function query(Request $request)
    {
        [$cycleStart, $cycleEnd] = $this->cycle($request->user(), $request->get('cycle'));
        $fromValue = $request->has('table_from') ? $request->input('table_from') : $request->input('from');
        $toValue = $request->has('table_to') ? $request->input('table_to') : $request->input('to');
        $tipo = $request->has('table_tipo') ? $request->input('table_tipo') : $request->input('tipo');
        $categoria = $request->has('table_categoria') ? $request->input('table_categoria') : $request->input('categoria');
        $search = $request->has('table_search') ? $request->input('table_search') : $request->input('search');
        $amount = $request->has('table_amount') ? $request->input('table_amount') : $request->input('amount');
        $from = $this->parseFilterDate($fromValue)?->max($cycleStart) ?? $cycleStart;
        $to = $this->parseFilterDate($toValue)?->min($cycleEnd) ?? $cycleEnd;

        return MovimientoEfectivo::with('categoria')->where('user_id', $request->user()->id)
            ->when($search !== null && $search !== '', function ($q) use ($search) {
                $q->where(function ($query) use ($search) {
                    $query->where('descripcion', 'like', "%{$search}%")
                        ->orWhere('monto', 'like', "%{$search}%");
                });
            })
            ->when($amount !== null && $amount !== '', fn ($q) => $q->where('monto', 'like', '%'.$amount.'%'))
            ->whereBetween('fecha', [$from->toDateString(), $to->toDateString()])
            ->when($tipo !== null && $tipo !== '', fn ($q) => $q->where('tipo', $tipo))
            ->when($categoria !== null && $categoria !== '', fn ($q) => $q->where('categoria_personal_id', $categoria))
            ->orderBy(in_array($request->get('sort'), ['descripcion', 'monto', 'fecha', 'tipo'], true) ? $request->get('sort') : 'fecha', $request->get('direction') === 'asc' ? 'asc' : 'desc');
    }

    public function index(Request $request)
    {
        app(CicloEfectivoService::class)->closeIfNeeded($request->user());
        [$cycleStart, $cycleEnd] = $this->cycle($request->user(), $request->get('cycle'));
        $movimientos = $this->query($request)->paginate((int) $request->get('per_page', 10))->withQueryString();
        $categorias = CategoriaPersonal::where('user_id', $request->user()->id)->where('activo', true)->where('nombre', '!=', 'Saldo inicial')->orderBy('nombre')->get();
        $totals = $request->user()->movimientosEfectivo()
            ->selectRaw("COALESCE(SUM(CASE WHEN tipo = 'ingreso' THEN monto ELSE 0 END), 0) ingresos")
            ->selectRaw("COALESCE(SUM(CASE WHEN tipo = 'egreso' THEN monto ELSE 0 END), 0) egresos")
            ->first();
        $balance = (float) $totals->ingresos - (float) $totals->egresos;
        if ($request->ajax() || $request->wantsJson()) {
            $isCurrentCycle = $cycleStart->equalTo($this->cycle($request->user())[0]);
            $simbolo = $request->user()->monedaPreferida?->simbolo ?? '$';

            return response()->json([
                'html' => view('efectivo._table', compact('movimientos', 'isCurrentCycle', 'simbolo'))->render(),
                'pagination' => $movimientos->links()->render(),
                'balance' => number_format($balance, 2, '.', ''),
                'ingresos' => number_format((float) $totals->ingresos, 2, '.', ''),
                'egresos' => number_format((float) $totals->egresos, 2, '.', ''),
            ]);
        }

        $ingresos = (float) $totals->ingresos;
        $egresos = (float) $totals->egresos;

        $currentCycle = $this->cycle($request->user());
        $isCurrentCycle = $cycleStart->equalTo($currentCycle[0]);
        $previousCycle = $cycleStart->copy()->subMonth()->format('Y-m-d');

        return view('efectivo.index', compact('movimientos', 'categorias', 'balance', 'ingresos', 'egresos', 'cycleStart', 'cycleEnd', 'isCurrentCycle', 'previousCycle'));
    }

    public function create(Request $request)
    {
        app(CicloEfectivoService::class)->closeIfNeeded($request->user());
        [$cycleStart, $cycleEnd] = $this->cycle($request->user());

        $compromiso = $request->filled('compromiso') ? $request->user()->compromisos()->whereKey($request->input('compromiso'))->where('estado', 'activo')->firstOrFail() : null;

        return view('efectivo._form', ['compromiso' => $compromiso, 'categorias' => CategoriaPersonal::where('user_id', $request->user()->id)->where('activo', true)->where('nombre', '!=', 'Saldo inicial')->orderBy('nombre')->get(), 'cycleStart' => $cycleStart, 'cycleEnd' => $cycleEnd]);
    }

    public function store(Request $request)
    {
        app(CicloEfectivoService::class)->closeIfNeeded($request->user());
        $data = $this->validated($request);
        $movimiento = null;
        DB::transaction(function () use ($request, $data, &$movimiento) {
            $this->ensureBalance($request, $data['tipo'], (float) $data['monto']);
            $movimiento = $request->user()->movimientosEfectivo()->create($data);
            if (! empty($data['compromiso_id'])) {
                $compromiso = Compromiso::whereKey($data['compromiso_id'])->lockForUpdate()->firstOrFail();
                $saldo = max(0, (float) ($compromiso->saldo_pendiente ?? $compromiso->monto) - (float) $data['monto']);
                $compromiso->update([
                    'saldo_pendiente' => $saldo,
                    'cuotas_pagadas' => $compromiso->cuotas_pagadas + 1,
                    'estado' => $saldo <= 0 && ! $compromiso->recurrencia_indefinida ? 'pagado' : 'activo',
                ]);
            }
        });

        return $this->success($request, 'Movimiento registrado correctamente.', $movimiento);
    }

    public function edit(Request $request, MovimientoEfectivo $movimiento)
    {
        $this->own($request, $movimiento);

        [$cycleStart] = $this->cycle($request->user());
        abort_unless($movimiento->fecha->greaterThanOrEqualTo($cycleStart), 403);

        return view('efectivo._form', ['movimiento' => $movimiento, 'categorias' => CategoriaPersonal::where('user_id', $request->user()->id)->where('activo', true)->where('nombre', '!=', 'Saldo inicial')->orderBy('nombre')->get(), 'cycleStart' => $cycleStart, 'cycleEnd' => $this->cycle($request->user())[1]]);
    }

    public function update(Request $request, MovimientoEfectivo $movimiento)
    {
        app(CicloEfectivoService::class)->closeIfNeeded($request->user());
        $this->own($request, $movimiento);
        abort_unless($movimiento->fecha->greaterThanOrEqualTo($this->cycle($request->user())[0]), 403);
        $data = $this->validated($request);
        DB::transaction(function () use ($request, $data, $movimiento) {
            $balanceWithout = $request->user()->movimientosEfectivo()->where('id', '!=', $movimiento->id)->selectRaw("COALESCE(SUM(CASE WHEN tipo = 'ingreso' THEN monto ELSE -monto END), 0) balance")->value('balance');
            if ($data['tipo'] === 'egreso' && (float) $balanceWithout < (float) $data['monto']) {
                throw ValidationException::withMessages(['monto' => 'El egreso supera el saldo disponible.']);
            }
            $movimiento->update($data);
        });

        return $this->success($request, 'Movimiento actualizado correctamente.', $movimiento);
    }

    public function destroy(Request $request, MovimientoEfectivo $movimiento)
    {
        $this->own($request, $movimiento);
        abort_unless($movimiento->fecha->greaterThanOrEqualTo($this->cycle($request->user())[0]), 403);
        $movimiento->delete();

        $currentPage = max(1, (int) $request->input('page', 1));
        $perPage = max(1, (int) $request->input('per_page', 10));
        $lastPage = max(1, (int) ceil($this->query($request)->count() / $perPage));

        return $this->success($request, 'Movimiento eliminado correctamente.', null, min($currentPage, $lastPage));
    }

    public function export(Request $request)
    {
        return $this->handleExport($request, $this->query($request)->get(), MovimientosEfectivoExport::class, 'Movimientos de efectivo', ['id' => 'ID', 'descripcion' => 'Descripción', 'monto' => 'Monto', 'fecha' => 'Fecha', 'tipo' => 'Tipo'], 'efectivo');
    }

    private function validated(Request $request): array
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->input('fecha'))) {
            $request->merge([
                'fecha' => Carbon::createFromFormat('Y-m-d', $request->input('fecha'))->format('d/m/Y'),
            ]);
        }

        [$cycleStart, $cycleEnd] = $this->cycle($request->user());
        $data = $request->validate([
            'descripcion' => ['required', 'string', 'max:255'],
            'monto' => ['required', 'numeric', 'gt:0', 'regex:/^\d+(?:\.\d{1,2})?$/'],
            'fecha' => ['required', 'date_format:d/m/Y', 'before_or_equal:today', 'after_or_equal:'.$cycleStart->format('d/m/Y'), 'before_or_equal:'.$cycleEnd->format('d/m/Y')],
            'tipo' => ['required', Rule::in(['ingreso', 'egreso'])],
            'categoria_personal_id' => ['required', 'integer', Rule::exists('categorias_personales', 'id')->where(fn ($q) => $q->where('user_id', $request->user()->id)->where('activo', true)->where('nombre', '!=', 'Saldo inicial')->where('tipo', $request->input('tipo')))],
            'compromiso_id' => ['nullable', 'integer', Rule::exists('compromisos', 'id')->where(fn ($q) => $q->where('user_id', $request->user()->id)->where('estado', 'activo'))],
        ], ['monto.regex' => 'El monto debe tener máximo dos decimales y ser positivo.']);

        $data['fecha'] = Carbon::createFromFormat('d/m/Y', $data['fecha'])->format('Y-m-d');

        return $data;
    }

    private function cycle(User $user, ?string $requestedStart = null): array
    {
        $timezone = $user->zona_horaria ?: config('app.timezone');
        $today = Carbon::now($timezone)->startOfDay();
        $cutoff = max(1, min((int) ($user->fecha_corte_dia ?: 31), 31));
        $end = $today->copy()->day(min($cutoff, $today->daysInMonth));

        if ($today->greaterThan($end)) {
            $start = $end->copy()->addDay();
            $end = $end->copy()->addMonthNoOverflow()->day(min($cutoff, $end->copy()->addMonthNoOverflow()->daysInMonth));
        } else {
            $previousEnd = $end->copy()->subMonthNoOverflow()->day(min($cutoff, $end->copy()->subMonthNoOverflow()->daysInMonth));
            $start = $previousEnd->addDay();
        }

        $initialStart = $user->movimientosEfectivo()
            ->whereHas('categoria', fn ($q) => $q->where('nombre', 'Saldo inicial'))
            ->oldest('fecha')->value('fecha');
        if ($initialStart && Carbon::parse($initialStart, $timezone)->greaterThan($start) && Carbon::parse($initialStart, $timezone)->lte($today)) {
            $start = Carbon::parse($initialStart, $timezone)->startOfDay();
        }

        if ($requestedStart && preg_match('/^\d{4}-\d{2}-\d{2}$/', $requestedStart)) {
            $start = Carbon::createFromFormat('Y-m-d', $requestedStart, $timezone)->startOfDay();
            abort_if($start->greaterThan($this->cycle($user)[0]), 404);
            $end = $start->copy()->addMonthNoOverflow()->subDay();
        }

        return [$start, $end];
    }

    private function parseFilterDate(?string $value): ?Carbon
    {
        if (! $value) {
            return null;
        }

        foreach (['d/m/Y', 'Y-m-d'] as $format) {
            try {
                return Carbon::createFromFormat($format, $value)->startOfDay();
            } catch (\Throwable) {
            }
        }

        return null;
    }

    private function ensureBalance(Request $request, string $type, float $amount): void
    {
        if ($type === 'egreso' && (float) $request->user()->movimientosEfectivo()->selectRaw("COALESCE(SUM(CASE WHEN tipo = 'ingreso' THEN monto ELSE -monto END), 0) balance")->value('balance') < $amount) {
            throw ValidationException::withMessages(['monto' => 'El egreso supera el saldo disponible.']);
        }
    }

    private function own(Request $request, MovimientoEfectivo $movimiento): void
    {
        abort_unless($movimiento->user_id === $request->user()->id, 404);
    }

    private function success(Request $request, string $message, ?MovimientoEfectivo $movimiento = null, ?int $page = null)
    {
        return $request->expectsJson()
            ? response()->json(array_filter([
                'success' => true,
                'message' => $message,
                'highlight_id' => $movimiento?->id,
                'redirect_to_page' => $page ?? ($movimiento ? $this->pageFor($request, $movimiento) : null),
            ], static fn ($value) => $value !== null))
            : redirect()->route('efectivo.index')->with('success', $message);
    }

    private function pageFor(Request $request, MovimientoEfectivo $movimiento): int
    {
        $perPage = max(1, (int) $request->input('per_page', 10));
        $ids = $this->query($request)->pluck('id')->map(static fn ($id) => (string) $id)->values();
        $position = $ids->search((string) $movimiento->getKey(), true);

        return $position === false ? 1 : (int) floor($position / $perPage) + 1;
    }
}
