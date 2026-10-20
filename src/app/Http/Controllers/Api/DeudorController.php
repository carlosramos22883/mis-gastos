<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DeudaPagoRequest;
use App\Http\Requests\DeudaRequest;
use App\Http\Requests\DeudorRequest;
use App\Http\Resources\DeudorResource;
use App\Models\Deuda;
use App\Models\DeudaPago;
use App\Models\Deudor;
use App\Services\DeudorService;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Deudores', description: 'Lo que le deben al hogar: deudores, préstamos y pagos')]
class DeudorController extends Controller
{
    public function __construct(private readonly DeudorService $deudores) {}

    #[OA\Get(path: '/api/deudores', summary: 'Deudores con prestado, pagado y pendiente', tags: ['Deudores'], security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Lista de deudores')])]
    public function index(Request $request)
    {
        return DeudorResource::collection($this->deudores->listar($request->user()));
    }

    #[OA\Post(path: '/api/deudores', summary: 'Crear deudor', tags: ['Deudores'], security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(content: new OA\JsonContent(properties: [
            new OA\Property(property: 'nombre', type: 'string'), new OA\Property(property: 'tipo', type: 'string', enum: ['persona', 'institucion', 'empresa']),
            new OA\Property(property: 'contacto', type: 'string'), new OA\Property(property: 'notas', type: 'string'),
        ])),
        responses: [new OA\Response(response: 201, description: 'Creado'), new OA\Response(response: 422, description: 'Error de validación')])]
    public function store(DeudorRequest $request)
    {
        $deudor = $request->user()->deudores()->create($request->datos());

        return (new DeudorResource($this->deudores->resumir($deudor)))->response()->setStatusCode(201);
    }

    #[OA\Get(path: '/api/deudores/{id}', summary: 'Deudor con sus deudas y pagos', tags: ['Deudores'], security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Detalle'), new OA\Response(response: 404, description: 'No existe o es de otro hogar')])]
    public function show(Request $request, Deudor $deudor)
    {
        return new DeudorResource($this->detalle($request, $deudor));
    }

    #[OA\Put(path: '/api/deudores/{id}', summary: 'Actualizar deudor', tags: ['Deudores'], security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Actualizado')])]
    public function update(DeudorRequest $request, Deudor $deudor)
    {
        $this->own($request, $deudor);
        $deudor->update($request->datos());

        return new DeudorResource($this->deudores->resumir($deudor));
    }

    #[OA\Delete(path: '/api/deudores/{id}', summary: 'Eliminar deudor (solo sin historial)', tags: ['Deudores'], security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 204, description: 'Eliminado'), new OA\Response(response: 422, description: 'Tiene historial: desactivarlo')])]
    public function destroy(Request $request, Deudor $deudor)
    {
        $this->own($request, $deudor);
        $this->deudores->eliminarDeudor($deudor);

        return response()->noContent();
    }

    #[OA\Post(path: '/api/deudores/{id}/deudas', summary: 'Prestar o anotar una deuda', tags: ['Deudores'], security: [['bearerAuth' => []]],
        description: 'origen: "efectivo" | "cuenta:{id}" | "tarjeta:{id}" (crea ese gasto) | "ninguno" (solo anotar; admite fechas anteriores al ciclo).',
        requestBody: new OA\RequestBody(content: new OA\JsonContent(properties: [
            new OA\Property(property: 'descripcion', type: 'string'), new OA\Property(property: 'monto', type: 'string', example: '15.00'),
            new OA\Property(property: 'fecha', type: 'string', format: 'date'), new OA\Property(property: 'origen', type: 'string', example: 'efectivo'),
            new OA\Property(property: 'comentario', type: 'string'), new OA\Property(property: 'user_id', type: 'integer'),
        ])),
        responses: [new OA\Response(response: 201, description: 'Deudor actualizado'), new OA\Response(response: 422, description: 'Error de validación')])]
    public function guardarDeuda(DeudaRequest $request, Deudor $deudor)
    {
        $this->own($request, $deudor);
        $this->deudores->registrarDeuda($request->user(), $deudor, $request->validated());

        return (new DeudorResource($this->detalle($request, $deudor)))->response()->setStatusCode(201);
    }

    #[OA\Delete(path: '/api/deudas/{id}', summary: 'Eliminar una deuda sin pagos (y el gasto que generó)', tags: ['Deudores'], security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 204, description: 'Eliminada'), new OA\Response(response: 422, description: 'Tiene pagos')])]
    public function eliminarDeuda(Request $request, Deuda $deuda)
    {
        abort_unless($deuda->esDelHogar($request->user()), 404);
        $this->deudores->eliminarDeuda($deuda);

        return response()->noContent();
    }

    #[OA\Post(path: '/api/deudores/{id}/pagos', summary: 'Registrar un pago del deudor', tags: ['Deudores'], security: [['bearerAuth' => []]],
        description: 'destino: "efectivo" | "cuenta:{id}" | "ninguno". Sin deuda_id se reparte de la deuda más antigua a la más reciente.',
        requestBody: new OA\RequestBody(content: new OA\JsonContent(properties: [
            new OA\Property(property: 'monto', type: 'string', example: '10.00'), new OA\Property(property: 'fecha', type: 'string', format: 'date'),
            new OA\Property(property: 'destino', type: 'string', example: 'efectivo'), new OA\Property(property: 'deuda_id', type: 'integer'),
            new OA\Property(property: 'comentario', type: 'string'), new OA\Property(property: 'user_id', type: 'integer'),
        ])),
        responses: [new OA\Response(response: 201, description: 'Deudor actualizado'), new OA\Response(response: 422, description: 'Supera lo pendiente')])]
    public function guardarPago(DeudaPagoRequest $request, Deudor $deudor)
    {
        $this->own($request, $deudor);
        $this->deudores->registrarPago($request->user(), $deudor, $request->validated());

        return (new DeudorResource($this->detalle($request, $deudor)))->response()->setStatusCode(201);
    }

    #[OA\Delete(path: '/api/deuda-pagos/{id}', summary: 'Eliminar un pago (y su ingreso)', tags: ['Deudores'], security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 204, description: 'Eliminado')])]
    public function eliminarPago(Request $request, DeudaPago $pago)
    {
        abort_unless($pago->esDelHogar($request->user()), 404);
        $this->deudores->eliminarPago($pago);

        return response()->noContent();
    }

    private function detalle(Request $request, Deudor $deudor): Deudor
    {
        $this->own($request, $deudor);
        $deudor = $deudor->fresh();
        $deudor->load(['deudas' => fn ($q) => $q->withSum('pagos', 'monto')->with('pagos')]);

        return $this->deudores->resumir($deudor)->setAttribute('con_detalle', true);
    }

    private function own(Request $request, Deudor $deudor): void
    {
        abort_unless($deudor->esDelHogar($request->user()), 404);
    }
}
