<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PrestamoPagoRequest;
use App\Http\Requests\PrestamoRequest;
use App\Http\Resources\PrestamoResource;
use App\Models\Prestamo;
use App\Models\PrestamoPago;
use App\Services\PrestamoService;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Préstamos', description: 'Lo que debe el hogar: saldo, pagos con desglose y proyecciones')]
class PrestamoController extends Controller
{
    public function __construct(private readonly PrestamoService $prestamos) {}

    #[OA\Get(path: '/api/prestamos', summary: 'Préstamos con saldo, cuota y fecha estimada de fin', tags: ['Préstamos'], security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Lista')])]
    public function index(Request $request)
    {
        return PrestamoResource::collection($request->user()->prestamos()->with('banco', 'cargos')->orderByDesc('activo')->orderBy('nombre')->get());
    }

    #[OA\Post(path: '/api/prestamos', summary: 'Registrar préstamo', tags: ['Préstamos'], security: [['bearerAuth' => []]],
        description: 'saldo_inicial = saldo de capital a fecha_saldo (normalmente el último pago). cargos: [{nombre, monto}] por cuota. crear_compromiso: genera el compromiso mensual (no aplica a planilla).',
        responses: [new OA\Response(response: 201, description: 'Creado'), new OA\Response(response: 422, description: 'Error de validación')])]
    public function store(PrestamoRequest $request)
    {
        $prestamo = $this->prestamos->crear($request->user(), $request->datos(), $request->cargos() ?? [], $request->boolean('crear_compromiso'));

        return (new PrestamoResource($this->detalle($request, $prestamo)))->response()->setStatusCode(201);
    }

    #[OA\Get(path: '/api/prestamos/{id}', summary: 'Préstamo con sus pagos y el desglose sugerido para el próximo pago', tags: ['Préstamos'], security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Detalle'), new OA\Response(response: 404, description: 'No existe o es de otro hogar')])]
    public function show(Request $request, Prestamo $prestamo)
    {
        return new PrestamoResource($this->detalle($request, $prestamo));
    }

    #[OA\Put(path: '/api/prestamos/{id}', summary: 'Actualizar préstamo', tags: ['Préstamos'], security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Actualizado')])]
    public function update(PrestamoRequest $request, Prestamo $prestamo)
    {
        $this->own($request, $prestamo);
        $this->prestamos->actualizar($prestamo, $request->datos(), $request->cargos());

        return new PrestamoResource($this->detalle($request, $prestamo));
    }

    #[OA\Delete(path: '/api/prestamos/{id}', summary: 'Eliminar préstamo (solo sin pagos)', tags: ['Préstamos'], security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 204, description: 'Eliminado'), new OA\Response(response: 422, description: 'Tiene pagos: desactivarlo')])]
    public function destroy(Request $request, Prestamo $prestamo)
    {
        $this->own($request, $prestamo);
        $this->prestamos->eliminar($prestamo);

        return response()->noContent();
    }

    #[OA\Get(path: '/api/prestamos/{id}/proyeccion', summary: 'Tabla de pagos y escenarios: pagar hoy, abono extra mensual, abono único', tags: ['Préstamos'], security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'extra_mensual', in: 'query', schema: new OA\Schema(type: 'number')),
            new OA\Parameter(name: 'abono', in: 'query', schema: new OA\Schema(type: 'number')),
            new OA\Parameter(name: 'modo', in: 'query', schema: new OA\Schema(type: 'string', enum: ['plazo', 'cuota'])),
            new OA\Parameter(name: 'cuota', in: 'query', description: 'Probar otra cuota (préstamos sin cuota fija)', schema: new OA\Schema(type: 'number')),
        ],
        responses: [new OA\Response(response: 200, description: 'Escenarios'), new OA\Response(response: 422, description: 'Datos inválidos')])]
    public function proyeccion(Request $request, Prestamo $prestamo)
    {
        $this->own($request, $prestamo);
        $op = $request->validate([
            'extra_mensual' => ['nullable', 'numeric', 'min:0'], 'abono' => ['nullable', 'numeric', 'min:0'],
            'modo' => ['nullable', 'in:plazo,cuota'], 'cuota' => ['nullable', 'numeric', 'min:0'],
        ]);

        return response()->json(['data' => $this->prestamos->escenarios($prestamo, array_filter($op, fn ($v) => $v !== null && $v !== ''))]);
    }

    #[OA\Post(path: '/api/prestamos/{id}/pagos', summary: 'Registrar un pago con su desglose', tags: ['Préstamos'], security: [['bearerAuth' => []]],
        description: 'origen: "efectivo" | "cuenta:{id}" (crea el egreso y abona el compromiso del mes) | "planilla" | "ninguno".',
        responses: [new OA\Response(response: 201, description: 'Préstamo actualizado'), new OA\Response(response: 422, description: 'Error de validación')])]
    public function guardarPago(PrestamoPagoRequest $request, Prestamo $prestamo)
    {
        $this->own($request, $prestamo);
        $this->prestamos->registrarPago($request->user(), $prestamo, $request->validated());

        return (new PrestamoResource($this->detalle($request, $prestamo)))->response()->setStatusCode(201);
    }

    #[OA\Delete(path: '/api/prestamo-pagos/{id}', summary: 'Eliminar el último pago (revierte su egreso y el compromiso)', tags: ['Préstamos'], security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 204, description: 'Eliminado'), new OA\Response(response: 422, description: 'No es el último pago')])]
    public function eliminarPago(Request $request, PrestamoPago $pago)
    {
        abort_unless($pago->esDelHogar($request->user()), 404);
        $this->prestamos->eliminarPago($pago);

        return response()->noContent();
    }

    private function detalle(Request $request, Prestamo $prestamo): Prestamo
    {
        $this->own($request, $prestamo);

        return $prestamo->fresh(['banco', 'cargos', 'pagos'])->setAttribute('con_detalle', true);
    }

    private function own(Request $request, Prestamo $prestamo): void
    {
        abort_unless($prestamo->esDelHogar($request->user()), 404);
    }
}
