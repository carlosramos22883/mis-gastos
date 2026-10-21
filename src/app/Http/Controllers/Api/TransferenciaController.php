<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\TransferenciaRequest;
use App\Models\Transferencia;
use App\Services\CicloEfectivoService;
use App\Services\CuentaService;
use App\Services\TarjetaService;
use App\Services\TransferenciaService;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Transferencias', description: 'Mover dinero entre efectivo y cuentas; desde una tarjeta es un avance')]
class TransferenciaController extends Controller
{
    public function __construct(private readonly TransferenciaService $transferencias) {}

    #[OA\Get(path: '/api/transferencias/opciones', summary: 'Orígenes y destinos posibles', description: 'origen: "efectivo" | "cuenta:{id}" | "tarjeta:{id}" (avance) · destino: "efectivo" | "cuenta:{id}"', tags: ['Transferencias'], security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'origenes y destinos: [{valor, nombre, saldo}]')])]
    public function opciones(Request $request)
    {
        $user = $request->user();
        $cuentas = app(CuentaService::class);
        $tarjetas = app(TarjetaService::class);
        $dinero = fn ($v) => number_format((float) $v, 2, '.', '');

        $destinos = collect([['valor' => 'efectivo', 'nombre' => 'Efectivo', 'saldo' => $dinero(app(CicloEfectivoService::class)->balance($user))]])
            ->concat($user->cuentas()->where('activo', true)->orderBy('nombre')->get()
                ->map(fn ($c) => ['valor' => 'cuenta:'.$c->id, 'nombre' => $c->nombre, 'saldo' => $dinero($cuentas->saldo($c))]));
        $avances = $user->tarjetasCredito()->where('activo', true)->where('permite_avances', true)->orderBy('nombre')->get()
            ->map(fn ($t) => ['valor' => 'tarjeta:'.$t->id, 'nombre' => "Tarjeta {$t->nombre} (avance)", 'saldo' => $dinero($tarjetas->disponible($t))]);

        return response()->json(['data' => ['origenes' => $destinos->concat($avances)->values(), 'destinos' => $destinos->values()]]);
    }

    #[OA\Post(path: '/api/transferencias', summary: 'Registrar transferencia o avance', tags: ['Transferencias'], security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 201, description: 'Registrada'), new OA\Response(response: 422, description: 'Validación (ej. saldo insuficiente)')])]
    public function store(TransferenciaRequest $request)
    {
        $t = $this->transferencias->crear($request->user(), $request->validated());

        return response()->json(['data' => $this->datos($t), 'message' => "Transferencia registrada: {$t->nombreOrigen()} → {$t->nombreDestino()}."], 201);
    }

    #[OA\Delete(path: '/api/transferencias/{id}', summary: 'Eliminar transferencia (revierte sus dos movimientos)', tags: ['Transferencias'], security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 204, description: 'Eliminada'), new OA\Response(response: 422, description: 'No se puede (ej. de un ciclo cerrado)')])]
    public function destroy(Request $request, Transferencia $transferencia)
    {
        abort_unless($transferencia->esDelHogar($request->user()), 404);
        $this->transferencias->eliminar($transferencia);

        return response()->noContent();
    }

    private function datos(Transferencia $t): array
    {
        return ['id' => $t->id, 'origen' => $t->nombreOrigen(), 'destino' => $t->nombreDestino(),
            'monto' => number_format((float) $t->monto, 2, '.', ''), 'fecha' => $t->fecha?->toDateString(), 'descripcion' => $t->descripcion];
    }
}
