<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\MovimientoEfectivoRequest;
use App\Http\Resources\MovimientoEfectivoResource;
use App\Models\MovimientoEfectivo;
use App\Services\CicloEfectivoService;
use App\Services\MovimientoEfectivoService;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Efectivo', description: 'Ingresos y egresos de efectivo por ciclo')]
class EfectivoController extends Controller
{
    public function __construct(private readonly MovimientoEfectivoService $movimientos, private readonly CicloEfectivoService $ciclos) {}

    #[OA\Get(path: '/api/efectivo', summary: 'Movimientos de un ciclo con su resumen', description: 'Sin "ciclo" devuelve el ciclo actual. "ciclo" acepta cualquier fecha (Y-m-d) dentro del ciclo deseado.', tags: ['Efectivo'], security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'ciclo', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'tipo', in: 'query', schema: new OA\Schema(type: 'string', enum: ['ingreso', 'egreso'])),
            new OA\Parameter(name: 'categoria', in: 'query', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'search', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 20)),
        ],
        responses: [new OA\Response(response: 200, description: 'data + ciclo + resumen + meta de paginación'), new OA\Response(response: 404, description: 'Ciclo futuro o inválido')])]
    public function index(Request $request)
    {
        $user = $request->user();
        $this->ciclos->closeIfNeeded($user);
        [$inicio, $fin] = $this->ciclos->cicloConsultado($user, $request->query('ciclo')) ?? abort(404, 'Ciclo no válido.');
        [$inicioActual] = $this->ciclos->cicloConsultado($user);
        $primera = $this->ciclos->primeraFecha($user);
        $esActual = $inicio->toDateString() === $inicioActual->toDateString();

        $movimientos = $this->movimientos->consultar($user, $inicio, $fin, $request->only(['search', 'tipo', 'categoria', 'sort', 'direction']))
            ->paginate(min(100, max(1, $request->integer('per_page', 20))))->withQueryString();
        $resumen = $this->movimientos->resumen($user, $inicio, $fin);

        return MovimientoEfectivoResource::collection($movimientos)->additional([
            'ciclo' => [
                'inicio' => $inicio->toDateString(),
                'fin' => $fin->toDateString(),
                'es_actual' => $esActual,
                'anterior' => $primera && $inicio->lte($primera) ? null : $inicio->copy()->subDay()->toDateString(),
                'siguiente' => $esActual ? null : $fin->copy()->addDay()->toDateString(),
            ],
            'resumen' => array_map(fn (float $v) => number_format($v, 2, '.', ''), $resumen),
        ]);
    }

    #[OA\Post(path: '/api/efectivo', summary: 'Registrar ingreso o egreso', description: 'Con "compromiso_id" el egreso se aplica como pago (total o parcial) de ese compromiso.', tags: ['Efectivo'], security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 201, description: 'Registrado'), new OA\Response(response: 422, description: 'Validación (ej. el egreso supera el saldo)')])]
    public function store(MovimientoEfectivoRequest $request)
    {
        $movimiento = $this->movimientos->registrar($request->user(), $request->validated());

        return (new MovimientoEfectivoResource($movimiento->load('categoria')))->response()->setStatusCode(201);
    }

    #[OA\Put(path: '/api/efectivo/{id}', summary: 'Actualizar movimiento del ciclo actual', tags: ['Efectivo'], security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Actualizado'), new OA\Response(response: 403, description: 'De un ciclo cerrado')])]
    public function update(MovimientoEfectivoRequest $request, MovimientoEfectivo $movimiento)
    {
        $this->own($request, $movimiento);
        $this->movimientos->actualizar($movimiento, $request->validated());

        return new MovimientoEfectivoResource($movimiento->load('categoria'));
    }

    #[OA\Delete(path: '/api/efectivo/{id}', summary: 'Eliminar movimiento del ciclo actual', tags: ['Efectivo'], security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 204, description: 'Eliminado'), new OA\Response(response: 422, description: 'El efectivo quedaría en negativo')])]
    public function destroy(Request $request, MovimientoEfectivo $movimiento)
    {
        $this->own($request, $movimiento);
        $this->movimientos->eliminar($movimiento);

        return response()->noContent();
    }

    private function own(Request $request, MovimientoEfectivo $movimiento): void
    {
        abort_unless($movimiento->esDelHogar($request->user()), 404);
    }
}
