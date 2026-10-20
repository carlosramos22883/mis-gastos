<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\FuenteIngresoRequest;
use App\Http\Requests\IngresoRequest;
use App\Http\Resources\FuenteIngresoResource;
use App\Models\FuenteIngreso;
use App\Models\Ingreso;
use App\Services\IngresoService;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Ingresos', description: 'Fuentes de ingreso (sueldos, servicios…) y pagos recibidos con desglose')]
class IngresoController extends Controller
{
    public function __construct(private readonly IngresoService $ingresos) {}

    #[OA\Get(path: '/api/ingresos', summary: 'Fuentes de ingreso con su plantilla', tags: ['Ingresos'], security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Lista')])]
    public function index(Request $request)
    {
        return FuenteIngresoResource::collection($request->user()->fuentesIngreso()->with('conceptos')->orderByDesc('activo')->orderBy('nombre')->get());
    }

    #[OA\Post(path: '/api/ingresos', summary: 'Crear fuente de ingreso', tags: ['Ingresos'], security: [['bearerAuth' => []]],
        description: 'destino: "efectivo" | "cuenta:{id}". conceptos: [{nombre, tipo: descuento|adicion, monto?, porcentaje?, tope?, prestamo_id?}].',
        responses: [new OA\Response(response: 201, description: 'Creada'), new OA\Response(response: 422, description: 'Error de validación')])]
    public function store(FuenteIngresoRequest $request)
    {
        $fuente = $this->ingresos->crearFuente($request->user(), $request->datos(), $request->conceptos() ?? []);

        return (new FuenteIngresoResource($this->detalle($request, $fuente)))->response()->setStatusCode(201);
    }

    #[OA\Get(path: '/api/ingresos/{id}', summary: 'Fuente con sus pagos recibidos', tags: ['Ingresos'], security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Detalle'), new OA\Response(response: 404, description: 'No existe o es de otro hogar')])]
    public function show(Request $request, FuenteIngreso $fuente)
    {
        return new FuenteIngresoResource($this->detalle($request, $fuente));
    }

    #[OA\Put(path: '/api/ingresos/{id}', summary: 'Actualizar fuente (sin "conceptos" no se toca la plantilla)', tags: ['Ingresos'], security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Actualizada')])]
    public function update(FuenteIngresoRequest $request, FuenteIngreso $fuente)
    {
        $this->own($request, $fuente);
        $this->ingresos->actualizarFuente($fuente, $request->datos(), $request->conceptos());

        return new FuenteIngresoResource($this->detalle($request, $fuente));
    }

    #[OA\Delete(path: '/api/ingresos/{id}', summary: 'Eliminar fuente (solo sin pagos)', tags: ['Ingresos'], security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 204, description: 'Eliminada'), new OA\Response(response: 422, description: 'Tiene pagos: desactivarla')])]
    public function destroy(Request $request, FuenteIngreso $fuente)
    {
        $this->own($request, $fuente);
        $this->ingresos->eliminarFuente($fuente);

        return response()->noContent();
    }

    #[OA\Get(path: '/api/ingresos/{id}/propuesta', summary: 'Líneas y neto propuestos para un bruto', tags: ['Ingresos'], security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'bruto', in: 'query', schema: new OA\Schema(type: 'number'))],
        responses: [new OA\Response(response: 200, description: 'Propuesta')])]
    public function propuesta(Request $request, FuenteIngreso $fuente)
    {
        $this->own($request, $fuente);
        $bruto = $request->validate(['bruto' => ['nullable', 'numeric', 'min:0']])['bruto'] ?? null;

        return response()->json(['data' => $this->ingresos->propuesta($fuente->load('conceptos'), $bruto !== null ? (float) $bruto : null)]);
    }

    #[OA\Post(path: '/api/ingresos/{id}/recibos', summary: 'Registrar un pago recibido con su desglose', tags: ['Ingresos'], security: [['bearerAuth' => []]],
        description: 'destino: "efectivo" | "cuenta:{id}" | "ninguno". lineas: [{nombre, tipo, monto, prestamo_id?}]. El neto entra como ingreso; los préstamos 100 % de planilla se pagan solos.',
        responses: [new OA\Response(response: 201, description: 'Fuente actualizada'), new OA\Response(response: 422, description: 'Error de validación')])]
    public function guardarRecibo(IngresoRequest $request, FuenteIngreso $fuente)
    {
        $this->own($request, $fuente);
        $this->ingresos->registrar($request->user(), $fuente, $request->validated());

        return (new FuenteIngresoResource($this->detalle($request, $fuente)))->response()->setStatusCode(201);
    }

    #[OA\Delete(path: '/api/recibos/{id}', summary: 'Eliminar un pago recibido (y lo que registró)', tags: ['Ingresos'], security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 204, description: 'Eliminado')])]
    public function eliminarRecibo(Request $request, Ingreso $ingreso)
    {
        abort_unless($ingreso->esDelHogar($request->user()), 404);
        $this->ingresos->eliminar($ingreso);

        return response()->noContent();
    }

    private function detalle(Request $request, FuenteIngreso $fuente): FuenteIngreso
    {
        $this->own($request, $fuente);

        return $fuente->fresh(['conceptos', 'ingresos.lineas'])->setAttribute('con_detalle', true);
    }

    private function own(Request $request, FuenteIngreso $fuente): void
    {
        abort_unless($fuente->esDelHogar($request->user()), 404);
    }
}
