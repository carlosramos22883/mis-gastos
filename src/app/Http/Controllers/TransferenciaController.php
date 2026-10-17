<?php

namespace App\Http\Controllers;

use App\Http\Requests\TransferenciaRequest;
use App\Models\Transferencia;
use App\Services\CicloEfectivoService;
use App\Services\TransferenciaService;
use Illuminate\Http\Request;

class TransferenciaController extends Controller
{
    public function __construct(private readonly TransferenciaService $transferencias) {}

    public function create(Request $request)
    {
        $user = $request->user();
        [$inicio, $fin] = app(CicloEfectivoService::class)->cicloConsultado($user);
        $cuentas = $user->cuentas()->where('activo', true)->orderBy('nombre')->get();
        $tarjetas = $user->tarjetasCredito()->where('activo', true)->orderBy('nombre')->get();

        $destinos = ['efectivo' => 'Efectivo'] + $cuentas->mapWithKeys(fn ($c) => ['cuenta:'.$c->id => $c->nombre])->all();
        $origenes = $destinos + $tarjetas->mapWithKeys(fn ($t) => ['tarjeta:'.$t->id => "Tarjeta {$t->nombre} (avance)"])->all();

        return view('transferencias._form', [
            'origenes' => $origenes,
            'destinos' => $destinos,
            'origen' => $request->query('origen', 'efectivo'),
            'destino' => $request->query('destino', array_key_first(array_diff_key($destinos, ['efectivo' => 1])) ?? 'efectivo'),
            'cycleStart' => $inicio,
            'cycleEnd' => $fin,
        ]);
    }

    public function store(TransferenciaRequest $request)
    {
        $transferencia = $this->transferencias->crear($request->user(), $request->validated());

        return response()->json(['success' => true, 'message' => "Transferencia registrada: {$transferencia->nombreOrigen()} → {$transferencia->nombreDestino()}."]);
    }

    public function destroy(Request $request, Transferencia $transferencia)
    {
        abort_unless($transferencia->user_id === $request->user()->id, 404);
        $this->transferencias->eliminar($transferencia);

        return response()->json(['success' => true, 'message' => 'Transferencia eliminada: se revirtieron sus dos movimientos.']);
    }
}
