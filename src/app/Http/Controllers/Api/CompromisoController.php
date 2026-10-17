<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CompromisoRequest;
use App\Http\Resources\CompromisoResource;
use App\Models\Compromiso;
use App\Services\CompromisoService;
use App\Services\TarjetaService;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Compromisos', description: 'Pagos programados: de una sola vez o recurrentes')]
class CompromisoController extends Controller
{
    public function __construct(private readonly CompromisoService $compromisos) {}

    #[OA\Get(path: '/api/compromisos', summary: 'Listar compromisos', tags: ['Compromisos'], security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'periodo', in: 'query', schema: new OA\Schema(type: 'string', enum: ['actual', 'proximos', 'todos'], default: 'actual')),
            new OA\Parameter(name: 'estado', in: 'query', schema: new OA\Schema(type: 'string', enum: ['pendiente', 'parcial', 'vencido', 'antiguo', 'pagado', 'cancelado'])),
            new OA\Parameter(name: 'search', in: 'query', schema: new OA\Schema(type: 'string')),
        ],
        responses: [new OA\Response(response: 200, description: 'Lista paginada')])]
    public function index(Request $request)
    {
        $user = $request->user();
        app(TarjetaService::class)->generarPendientes($user);
        $this->compromisos->sincronizar($user);
        [, $inicio, $fin] = $this->compromisos->fechas($user);

        return CompromisoResource::collection(
            $this->compromisos->consultar($user, $request->only(['search', 'estado', 'periodo', 'sort', 'direction']))
                ->paginate(min(100, max(1, $request->integer('per_page', 20))))->withQueryString()
        )->additional(['ciclo' => ['inicio' => $inicio->toDateString(), 'fin' => $fin->toDateString()]]);
    }

    #[OA\Get(path: '/api/compromisos/{id}', summary: 'Detalle con historial de abonos', tags: ['Compromisos'], security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Compromiso'), new OA\Response(response: 404, description: 'No encontrado')])]
    public function show(Request $request, Compromiso $compromiso)
    {
        $this->own($request, $compromiso);

        return new CompromisoResource($compromiso->load(['categoria', 'recurrente', 'movimientos' => fn ($q) => $q->where('tipo', 'egreso')->latest('fecha')]));
    }

    #[OA\Post(path: '/api/compromisos', summary: 'Crear compromiso', description: 'recurrencia_tipo=recurrente crea una serie (frecuencia, dia_pago, dia_pago_2, dia_semana, fecha_inicio, finalizacion, fecha_fin, total_cuotas). dia_pago=31 significa "último día del mes".', tags: ['Compromisos'], security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 201, description: 'Creado: el compromiso o, si es recurrente, su primer pago (o null si aún no corresponde)'), new OA\Response(response: 422, description: 'Error de validación')])]
    public function store(CompromisoRequest $request)
    {
        $user = $request->user();
        if ($request->esRecurrente()) {
            $recurrente = $this->compromisos->crearRecurrente($user, $request->datosRecurrente());
            $primero = $recurrente->compromisos()->with('categoria', 'recurrente')->orderBy('fecha_esperada')->first();

            return response()->json([
                'data' => $primero ? new CompromisoResource($primero) : null,
                'recurrente_id' => $recurrente->id,
                'pagos_generados' => $recurrente->compromisos()->count(),
            ], 201);
        }

        $compromiso = $this->compromisos->crearUnico($user, $request->datosPago());

        return (new CompromisoResource($compromiso->load('categoria', 'recurrente')))->response()->setStatusCode(201);
    }

    #[OA\Put(path: '/api/compromisos/{id}', summary: 'Editar un pago pendiente (no su serie)', tags: ['Compromisos'], security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Actualizado'), new OA\Response(response: 403, description: 'No está pendiente')])]
    public function update(CompromisoRequest $request, Compromiso $compromiso)
    {
        $this->own($request, $compromiso);
        $this->compromisos->actualizarPago($compromiso, $request->datosPago());

        return new CompromisoResource($compromiso->load('categoria', 'recurrente'));
    }

    #[OA\Delete(path: '/api/compromisos/{id}', summary: 'Cancelar compromiso', description: 'alcance=serie cancela también los pagos siguientes sin abonos y desactiva la regla.', tags: ['Compromisos'], security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'alcance', in: 'query', schema: new OA\Schema(type: 'string', enum: ['pago', 'serie'], default: 'pago'))],
        responses: [new OA\Response(response: 204, description: 'Cancelado')])]
    public function destroy(Request $request, Compromiso $compromiso)
    {
        $this->own($request, $compromiso);
        $this->compromisos->cancelar($compromiso, $request->query('alcance') === 'serie');

        return response()->noContent();
    }

    private function own(Request $request, Compromiso $compromiso): void
    {
        abort_unless($compromiso->user_id === $request->user()->id, 404);
    }
}
