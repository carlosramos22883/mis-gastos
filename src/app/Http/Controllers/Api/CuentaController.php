<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CuentaRequest;
use App\Http\Requests\MovimientoCuentaRequest;
use App\Http\Resources\CuentaResource;
use App\Http\Resources\MovimientoCuentaResource;
use App\Models\Cuenta;
use App\Models\MovimientoCuenta;
use App\Services\CicloEfectivoService;
use App\Services\CuentaService;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Cuentas', description: 'Cuentas bancarias y billeteras digitales del hogar, con sus movimientos por ciclo')]
class CuentaController extends Controller
{
    public function __construct(private readonly CuentaService $cuentas, private readonly CicloEfectivoService $ciclos) {}

    #[OA\Get(path: '/api/cuentas', summary: 'Cuentas con su saldo actual', tags: ['Cuentas'], security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Cuentas (activas primero)')])]
    public function index(Request $request)
    {
        $cuentas = $request->user()->cuentas()->with('banco', 'tipoCuenta')->orderByDesc('activo')->orderBy('nombre')->get()
            ->each(fn (Cuenta $c) => $c->setAttribute('saldo_actual', $this->cuentas->saldo($c)));

        return CuentaResource::collection($cuentas);
    }

    #[OA\Get(path: '/api/cuentas/{id}', summary: 'Cuenta con los movimientos de un ciclo y su resumen', description: '"ciclo" acepta cualquier fecha (Y-m-d) dentro del ciclo deseado; sin él, el actual.', tags: ['Cuentas'], security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'ciclo', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'tipo', in: 'query', schema: new OA\Schema(type: 'string', enum: ['ingreso', 'egreso'])),
            new OA\Parameter(name: 'search', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 20)),
        ],
        responses: [new OA\Response(response: 200, description: 'data (movimientos) + cuenta + ciclo + resumen + meta'), new OA\Response(response: 404, description: 'No encontrada o ciclo no válido')])]
    public function show(Request $request, Cuenta $cuenta)
    {
        $this->own($request, $cuenta);
        $user = $request->user();
        [$inicio, $fin] = $this->ciclos->cicloConsultado($user, $request->query('ciclo')) ?? abort(404, 'Ciclo no válido.');
        [$inicioActual] = $this->ciclos->cicloConsultado($user);
        $esActual = $inicio->toDateString() === $inicioActual->toDateString();
        $primera = max($cuenta->fecha_inicial->toDateString(), ($this->ciclos->primeraFecha($user) ?? $cuenta->fecha_inicial)->toDateString());

        $movimientos = $this->cuentas->consultarMovimientos($cuenta, $inicio, $fin, $request->only(['search', 'tipo', 'categoria']))
            ->paginate(min(100, max(1, $request->integer('per_page', 20))))->withQueryString();
        $cuenta->load('banco', 'tipoCuenta')->setAttribute('saldo_actual', $this->cuentas->saldo($cuenta));

        return MovimientoCuentaResource::collection($movimientos)->additional([
            'cuenta' => new CuentaResource($cuenta),
            'ciclo' => [
                'inicio' => $inicio->toDateString(),
                'fin' => $fin->toDateString(),
                'es_actual' => $esActual,
                'anterior' => $inicio->toDateString() <= $primera ? null : $inicio->copy()->subDay()->toDateString(),
                'siguiente' => $esActual ? null : $fin->copy()->addDay()->toDateString(),
            ],
            'resumen' => array_map(fn (float $v) => number_format($v, 2, '.', ''), $this->cuentas->resumen($cuenta, $inicio, $fin)),
        ]);
    }

    #[OA\Post(path: '/api/cuentas', summary: 'Crear cuenta', description: 'saldo_inicial y fecha_inicial solo se fijan al crear.', tags: ['Cuentas'], security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 201, description: 'Creada'), new OA\Response(response: 422, description: 'Validación')])]
    public function store(CuentaRequest $request)
    {
        $cuenta = $request->user()->cuentas()->create($request->validated());

        return $this->recurso($cuenta)->response()->setStatusCode(201);
    }

    #[OA\Put(path: '/api/cuentas/{id}', summary: 'Editar cuenta', tags: ['Cuentas'], security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Actualizada'), new OA\Response(response: 422, description: 'Validación')])]
    public function update(CuentaRequest $request, Cuenta $cuenta)
    {
        $this->own($request, $cuenta);
        $cuenta->update($request->validated());

        return $this->recurso($cuenta);
    }

    #[OA\Delete(path: '/api/cuentas/{id}', summary: 'Eliminar cuenta sin movimientos', tags: ['Cuentas'], security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 204, description: 'Eliminada'), new OA\Response(response: 422, description: 'Tiene movimientos: desactívala')])]
    public function destroy(Request $request, Cuenta $cuenta)
    {
        $this->own($request, $cuenta);
        $this->cuentas->eliminar($cuenta);

        return response()->noContent();
    }

    #[OA\Post(path: '/api/cuentas/{id}/movimientos', summary: 'Registrar ingreso o egreso en la cuenta', tags: ['Cuentas'], security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 201, description: 'Registrado'), new OA\Response(response: 422, description: 'Validación (ej. saldo insuficiente)')])]
    public function guardarMovimiento(MovimientoCuentaRequest $request, Cuenta $cuenta)
    {
        $this->own($request, $cuenta);
        abort_unless($cuenta->activo, 403, 'La cuenta está inactiva.');
        $movimiento = $this->cuentas->registrarMovimiento($cuenta, $request->validated());

        return (new MovimientoCuentaResource($movimiento->load('categoria', 'contacto')))->response()->setStatusCode(201);
    }

    #[OA\Put(path: '/api/cuentas/{id}/movimientos/{movimiento}', summary: 'Editar movimiento del ciclo actual', tags: ['Cuentas'], security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Actualizado'), new OA\Response(response: 403, description: 'De otro ciclo, de una transferencia o de otro módulo')])]
    public function actualizarMovimiento(MovimientoCuentaRequest $request, Cuenta $cuenta, MovimientoCuenta $movimiento)
    {
        $this->own($request, $cuenta, $movimiento);
        $this->cuentas->actualizarMovimiento($movimiento, $request->validated());

        return new MovimientoCuentaResource($movimiento->fresh(['categoria', 'contacto']));
    }

    #[OA\Delete(path: '/api/cuentas/{id}/movimientos/{movimiento}', summary: 'Eliminar movimiento del ciclo actual', tags: ['Cuentas'], security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 204, description: 'Eliminado'), new OA\Response(response: 403, description: 'No editable')])]
    public function eliminarMovimiento(Request $request, Cuenta $cuenta, MovimientoCuenta $movimiento)
    {
        $this->own($request, $cuenta, $movimiento);
        $this->cuentas->eliminarMovimiento($movimiento);

        return response()->noContent();
    }

    private function recurso(Cuenta $cuenta): CuentaResource
    {
        return new CuentaResource($cuenta->fresh(['banco', 'tipoCuenta'])->setAttribute('saldo_actual', $this->cuentas->saldo($cuenta)));
    }

    private function own(Request $request, Cuenta $cuenta, ?MovimientoCuenta $movimiento = null): void
    {
        abort_unless($cuenta->esDelHogar($request->user()), 404);
        abort_if($movimiento && $movimiento->cuenta_id !== $cuenta->id, 404);
    }
}
