<?php

namespace App\Http\Controllers;

use App\Exports\MovimientosEfectivoExport;
use App\Http\Requests\MovimientoEfectivoRequest;
use App\Models\CategoriaPersonal;
use App\Models\Compromiso;
use App\Models\MovimientoEfectivo;
use App\Models\User;
use App\Services\CicloEfectivoService;
use App\Services\MovimientoEfectivoService;
use App\Traits\Exportable;
use Carbon\Carbon;
use Illuminate\Http\Request;

class MovimientoEfectivoController extends Controller
{
    use Exportable;

    private function query(Request $request)
    {
        // El formulario de la tabla manda los filtros con prefijo "table_" cuando viene de un modal
        $filtro = fn (string $nombre) => $request->has('table_'.$nombre) ? $request->input('table_'.$nombre) : $request->input($nombre);
        [$cycleStart, $cycleEnd] = $this->cycle($request->user(), $request->get('cycle'));

        return $this->movimientos()->consultar($request->user(), $cycleStart, $cycleEnd, [
            'search' => $filtro('search'),
            'monto' => $filtro('amount'),
            'desde' => $this->parseFilterDate($filtro('from')),
            'hasta' => $this->parseFilterDate($filtro('to')),
            'tipo' => $filtro('tipo'),
            'categoria' => $filtro('categoria'),
            'sort' => $request->get('sort'),
            'direction' => $request->get('direction'),
        ]);
    }

    public function index(Request $request)
    {
        app(CicloEfectivoService::class)->closeIfNeeded($request->user());
        [$cycleStart, $cycleEnd] = $this->cycle($request->user(), $request->get('cycle'));
        $movimientos = $this->query($request)->paginate((int) $request->get('per_page', 10))->withQueryString();
        $categorias = $this->categorias($request);
        ['ingresos' => $ingresos, 'egresos' => $egresos, 'saldo' => $balance] = $this->movimientos()->resumen($request->user(), $cycleStart, $cycleEnd);
        if ($request->ajax() || $request->wantsJson()) {
            $isCurrentCycle = $cycleStart->equalTo($this->cycle($request->user())[0]);
            $simbolo = $request->user()->monedaPreferida?->simbolo ?? '$';

            return response()->json([
                'html' => view('efectivo._table', compact('movimientos', 'isCurrentCycle', 'simbolo'))->render(),
                'pagination' => $movimientos->links()->render(),
                'balance' => number_format($balance, 2, '.', ''),
                'ingresos' => number_format($ingresos, 2, '.', ''),
                'egresos' => number_format($egresos, 2, '.', ''),
            ]);
        }

        $currentCycle = $this->cycle($request->user());
        $isCurrentCycle = $cycleStart->equalTo($currentCycle[0]);
        $firstDate = $this->ciclos()->primeraFecha($request->user());
        $previousCycle = $firstDate && $cycleStart->lte($firstDate) ? null : $cycleStart->copy()->subDay()->format('Y-m-d');
        $nextCycle = $isCurrentCycle ? null : $cycleEnd->copy()->addDay()->format('Y-m-d');

        return view('efectivo.index', compact('movimientos', 'categorias', 'balance', 'ingresos', 'egresos', 'cycleStart', 'cycleEnd', 'isCurrentCycle', 'previousCycle', 'nextCycle'));
    }

    public function create(Request $request)
    {
        app(CicloEfectivoService::class)->closeIfNeeded($request->user());
        [$cycleStart, $cycleEnd] = $this->cycle($request->user());

        $compromiso = $request->filled('compromiso') ? $request->user()->compromisos()->whereKey($request->input('compromiso'))->where('estado', Compromiso::PENDIENTE)->firstOrFail() : null;

        return view('efectivo._form', ['compromiso' => $compromiso, 'categorias' => $this->categorias($request), 'cycleStart' => $cycleStart, 'cycleEnd' => $cycleEnd]);
    }

    public function store(MovimientoEfectivoRequest $request)
    {
        $movimiento = $this->movimientos()->registrar($request->user(), $request->validated());

        return $this->success($request, 'Movimiento registrado correctamente.', $movimiento);
    }

    public function edit(Request $request, MovimientoEfectivo $movimiento)
    {
        $this->own($request, $movimiento);
        abort_unless($this->movimientos()->esEditable($movimiento), 403);
        [$cycleStart, $cycleEnd] = $this->cycle($request->user());

        return view('efectivo._form', ['movimiento' => $movimiento, 'categorias' => $this->categorias($request), 'cycleStart' => $cycleStart, 'cycleEnd' => $cycleEnd]);
    }

    public function update(MovimientoEfectivoRequest $request, MovimientoEfectivo $movimiento)
    {
        $this->own($request, $movimiento);
        $this->movimientos()->actualizar($movimiento, $request->validated());

        return $this->success($request, 'Movimiento actualizado correctamente.', $movimiento);
    }

    public function destroy(Request $request, MovimientoEfectivo $movimiento)
    {
        $this->own($request, $movimiento);
        $this->movimientos()->eliminar($movimiento);

        $currentPage = max(1, (int) $request->input('page', 1));
        $perPage = max(1, (int) $request->input('per_page', 10));
        $lastPage = max(1, (int) ceil($this->query($request)->count() / $perPage));

        return $this->success($request, 'Movimiento eliminado correctamente.', null, min($currentPage, $lastPage));
    }

    public function export(Request $request)
    {
        return $this->handleExport($request, $this->query($request)->get(), MovimientosEfectivoExport::class, 'Movimientos de efectivo', ['id' => 'ID', 'descripcion' => 'Descripción', 'monto' => 'Monto', 'fecha' => 'Fecha', 'tipo' => 'Tipo'], 'efectivo');
    }

    private function cycle(User $user, ?string $requestedStart = null): array
    {
        return $this->ciclos()->cicloConsultado($user, $requestedStart) ?? abort(404);
    }

    private function categorias(Request $request)
    {
        return CategoriaPersonal::where('hogar_id', $request->user()->hogar_id)->where('activo', true)
            ->where('nombre', '!=', CicloEfectivoService::CATEGORIA_SALDO_INICIAL)->orderBy('nombre')->get();
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

    private function ciclos(): CicloEfectivoService
    {
        return app(CicloEfectivoService::class);
    }

    private function movimientos(): MovimientoEfectivoService
    {
        return app(MovimientoEfectivoService::class);
    }

    private function own(Request $request, MovimientoEfectivo $movimiento): void
    {
        abort_unless($movimiento->esDelHogar($request->user()), 404);
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
