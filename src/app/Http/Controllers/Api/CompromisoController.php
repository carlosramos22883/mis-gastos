<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CompromisoRequest;
use App\Http\Requests\PagoCompromisoRequest;
use App\Http\Requests\SerieCompromisoRequest;
use App\Http\Resources\CompromisoResource;
use App\Models\Compromiso;
use App\Services\CompromisoService;
use App\Services\PagoCompromisoService;
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
                ->with('tarjeta')->paginate(min(100, max(1, $request->integer('per_page', 20))))->withQueryString()
        )->additional(['ciclo' => ['inicio' => $inicio->toDateString(), 'fin' => $fin->toDateString()]]);
    }

    #[OA\Get(path: '/api/compromisos/{id}', summary: 'Detalle con historial de abonos', tags: ['Compromisos'], security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Compromiso'), new OA\Response(response: 404, description: 'No encontrado')])]
    public function show(Request $request, Compromiso $compromiso)
    {
        $this->own($request, $compromiso);

        return new CompromisoResource($compromiso->load(['categoria', 'recurrente', 'tarjeta', 'movimientos' => fn ($q) => $q->where('tipo', 'egreso')->latest('fecha')]));
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

    #[OA\Put(path: '/api/compromisos/{id}/serie', summary: 'Editar la regla de la serie de este pago', description: 'Mismos campos que al crear una serie, sin fecha_inicio. Se aplica a los pagos de hoy en adelante sin abonos; si cambia el calendario se regeneran.', tags: ['Compromisos'], security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Serie actualizada (data = este pago, o el que lo reemplaza si cambió el calendario: puede traer otro id)'), new OA\Response(response: 404, description: 'No pertenece a una serie'), new OA\Response(response: 422, description: 'Validación')])]
    public function actualizarSerie(SerieCompromisoRequest $request, Compromiso $compromiso)
    {
        $this->own($request, $compromiso);
        abort_unless($compromiso->recurrente, 404, 'Este compromiso no pertenece a una serie.');
        $actualizados = $this->compromisos->actualizarSerie($compromiso->recurrente, $request->datosSerie());

        // Si cambió el calendario este pago se regeneró: se devuelve el que lo reemplaza (puede tener otro id)
        $vigente = $this->compromisos->pagoVigente($compromiso);
        abort_unless($vigente, 404, 'La serie ya no tiene pagos.');

        return (new CompromisoResource($vigente->load('categoria', 'recurrente', 'tarjeta')))->additional(['pagos_actualizados' => $actualizados]);
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

    #[OA\Get(path: '/api/compromisos/{id}/origenes-pago', summary: 'Desde dónde se puede pagar (efectivo, cuentas y tarjetas con su saldo)', tags: ['Compromisos'], security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Opciones {valor: etiqueta}')])]
    public function origenesPago(Request $request, Compromiso $compromiso)
    {
        $this->own($request, $compromiso);

        return response()->json(['data' => app(PagoCompromisoService::class)->origenes($request->user(), $compromiso)]);
    }

    #[OA\Post(path: '/api/compromisos/{id}/pagar', summary: 'Pagar (completo o abono) desde efectivo, una cuenta o una tarjeta', tags: ['Compromisos'], security: [['bearerAuth' => []]],
        description: 'origen: "efectivo" | "cuenta:{id}" | "tarjeta:{id}" (ej. pagar el estado de cuenta de otra tarjeta con su NPE). El pago de una tarjeta no lleva categoría y puede superar el pendiente: el excedente queda como crédito en la tarjeta (campo "excedente" de la respuesta).',
        requestBody: new OA\RequestBody(content: new OA\JsonContent(properties: [
            new OA\Property(property: 'origen', type: 'string', example: 'tarjeta:5'), new OA\Property(property: 'monto', type: 'string', example: '96.62'),
            new OA\Property(property: 'fecha', type: 'string', format: 'date'), new OA\Property(property: 'descripcion', type: 'string'),
            new OA\Property(property: 'categoria_personal_id', type: 'integer'), new OA\Property(property: 'user_id', type: 'integer'),
        ])),
        responses: [new OA\Response(response: 200, description: 'Compromiso actualizado'), new OA\Response(response: 422, description: 'Supera el saldo (salvo en una tarjeta) o el disponible')])]
    public function pagar(PagoCompromisoRequest $request, Compromiso $compromiso)
    {
        $this->own($request, $compromiso);
        abort_unless($compromiso->estado === Compromiso::PENDIENTE, 422, 'Este compromiso ya no está pendiente.');

        return new CompromisoResource(app(PagoCompromisoService::class)->pagar($request->user(), $compromiso, $request->validated()));
    }

    private function own(Request $request, Compromiso $compromiso): void
    {
        abort_unless($compromiso->esDelHogar($request->user()), 404);
    }
}
