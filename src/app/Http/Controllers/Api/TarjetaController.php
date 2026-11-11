<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\MovimientoTarjetaRequest;
use App\Http\Requests\TarjetaRequest;
use App\Http\Resources\CompromisoResource;
use App\Http\Resources\MovimientoTarjetaResource;
use App\Http\Resources\TarjetaResource;
use App\Models\Compromiso;
use App\Models\EstadoCuentaTarjeta;
use App\Models\MovimientoTarjeta;
use App\Models\TarjetaCredito;
use App\Services\TarjetaService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Tarjetas', description: 'Tarjetas de crédito: deuda, disponible, cortes, cuotas y movimientos')]
class TarjetaController extends Controller
{
    public function __construct(private readonly TarjetaService $tarjetas) {}

    #[OA\Get(path: '/api/tarjetas', summary: 'Tarjetas con deuda, disponible, próximo corte y próximo pago', tags: ['Tarjetas'], security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Tarjetas (activas primero)')])]
    public function index(Request $request)
    {
        $this->tarjetas->generarPendientes($request->user());

        return TarjetaResource::collection($this->conResumen(
            $request->user()->tarjetasCredito()->with('banco', 'marcaRed')->orderByDesc('activo')->orderBy('nombre')->get()
        ));
    }

    #[OA\Get(path: '/api/tarjetas/{id}', summary: 'Detalle: movimientos sin facturar, lo que entra al próximo corte, cuotas, estados de cuenta y pagos pendientes', tags: ['Tarjetas'], security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Detalle'), new OA\Response(response: 404, description: 'No encontrada')])]
    public function show(Request $request, TarjetaCredito $tarjeta)
    {
        $this->own($request, $tarjeta);
        $this->tarjetas->generarEstados($tarjeta);
        $tarjeta = $this->conResumen(collect([$tarjeta->load('banco', 'marcaRed')]))->first();
        $dinero = fn ($v) => number_format((float) $v, 2, '.', '');
        $inicio = $this->tarjetas->inicioPeriodoAbierto($tarjeta);

        $movimientos = $tarjeta->movimientos()->with('categoria', 'contacto')
            ->whereDate('fecha', '>=', $inicio->toDateString())->orderByDesc('fecha')->orderByDesc('id')->get();
        $enCuotas = $tarjeta->movimientos()->with('categoria', 'contacto')->where('tipo', 'compra')->where('cuotas', '>', 1)->withCount('items')->get()
            ->filter(fn ($m) => $m->items_count < $m->cuotas)->values();
        $porFacturar = $this->tarjetas->porFacturar($tarjeta);

        return response()->json(['data' => [
            'tarjeta' => new TarjetaResource($tarjeta),
            'periodo_abierto' => ['desde' => $inicio->toDateString(), 'corte' => $tarjeta->proximo_corte?->toDateString()],
            'por_facturar' => [
                // "programada": cuota con día de cobro que aún no llega (no suma al total hasta su fecha)
                'saldo_a_favor' => $dinero($saldoAFavor = $this->tarjetas->saldoAFavor($tarjeta)), // ≤ 0: el último corte quedó a favor; ya va restado en el total
                'total' => $dinero($porFacturar->reject(fn ($l) => $l['programada'])->sum('monto') + $saldoAFavor),
                'lineas' => $porFacturar->map(fn ($l) => ['movimiento_id' => $l['movimiento']->id, 'descripcion' => $l['movimiento']->descripcion,
                    'fecha' => $l['fecha']->toDateString(), 'cuota_numero' => $l['cuota_numero'], 'cuotas' => $l['movimiento']->cuotas, 'monto' => $dinero($l['monto']),
                    'programada' => $l['programada']])->values(),
            ],
            'movimientos' => MovimientoTarjetaResource::collection($movimientos),
            'en_cuotas' => $enCuotas->map(fn ($m) => ['movimiento' => new MovimientoTarjetaResource($m), 'cuotas_cobradas' => $m->items_count])->values(),
            'estados_cuenta' => $tarjeta->estadosCuenta()->with('compromiso')->orderByDesc('fecha_corte')->limit(12)->get()
                ->map(fn ($e) => ['id' => $e->id, 'fecha_inicio' => $e->fecha_inicio->toDateString(), 'fecha_corte' => $e->fecha_corte->toDateString(),
                    'fecha_limite_pago' => $e->fecha_limite_pago?->toDateString(), 'total' => $dinero($e->total), 'saldo_anterior' => $dinero($e->saldo_anterior), // ≤ 0: saldo a favor del corte anterior
                    'intereses_bonificables' => $dinero($e->intereses_bonificables), // los del último corte se apartan del disponible hasta el corte siguiente
                    'compromiso_id' => $e->compromiso?->id, 'estado' => $e->compromiso?->estado, 'saldo_pendiente' => $e->compromiso ? $dinero($e->compromiso->saldo_pendiente) : null])->values(),
            'cargos_fijos' => $tarjeta->cargosFijos()->orderByDesc('activo')->orderBy('descripcion')->get()
                ->map(fn ($c) => CargoFijoController::datos($c))->values(),
            'cargos_proximo_corte' => $dinero($this->tarjetas->cargosFijosDelProximoCorte($tarjeta)->sum('monto')),
            'pagos' => $this->tarjetas->pagos($tarjeta)->map(fn ($p) => ['clase' => $p['clase'], 'id' => $p['id'], 'fecha' => $p['fecha']->toDateString(),
                'descripcion' => $p['descripcion'], 'desde' => $p['desde'], 'monto' => $dinero($p['monto']), 'user_id' => $p['user_id']])->values(),
            'por_pagar' => $tarjeta->compromisos()->with('tarjeta')->where('estado', Compromiso::PENDIENTE)->orderBy('fecha_esperada')->get()
                ->map(fn ($c) => ['compromiso' => new CompromisoResource($c), 'costo_de_no_pagar' => $this->tarjetas->costoDeNoPagar($tarjeta, $c)])->values(),
        ]]);
    }

    #[OA\Post(path: '/api/tarjetas', summary: 'Crear tarjeta', description: 'disponible (requerido) fija la deuda inicial; tiene_pendiente + pendiente_monto + pendiente_fecha registran un estado de cuenta por pagar.', tags: ['Tarjetas'], security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 201, description: 'Creada'), new OA\Response(response: 422, description: 'Validación')])]
    public function store(TarjetaRequest $request)
    {
        $tarjeta = $this->tarjetas->crear($request->user(), $request->datos());

        return $this->recurso($tarjeta)->response()->setStatusCode(201);
    }

    #[OA\Put(path: '/api/tarjetas/{id}', summary: 'Editar tarjeta', tags: ['Tarjetas'], security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Actualizada'), new OA\Response(response: 422, description: 'Validación')])]
    public function update(TarjetaRequest $request, TarjetaCredito $tarjeta)
    {
        $this->own($request, $tarjeta);
        $tarjeta->update($request->datos());

        return $this->recurso($tarjeta);
    }

    #[OA\Delete(path: '/api/tarjetas/{id}', summary: 'Eliminar tarjeta sin historial', tags: ['Tarjetas'], security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 204, description: 'Eliminada'), new OA\Response(response: 422, description: 'Tiene historial: desactívala')])]
    public function destroy(Request $request, TarjetaCredito $tarjeta)
    {
        $this->own($request, $tarjeta);
        $this->tarjetas->eliminar($tarjeta);

        return response()->noContent();
    }

    #[OA\Post(path: '/api/tarjetas/{id}/movimientos', summary: 'Registrar compra (cuotas > 1 = a plazos), cargo o crédito', description: 'Los avances se registran como transferencia (POST /api/transferencias con origen "tarjeta:{id}"). Con ?estado={id} se agrega a ese corte ya facturado (de contado, con fecha dentro de su período): su total y su pago pendiente suben. Una compra que supera el disponible responde 422 con el error "confirmar" (mensaje para preguntar al usuario); reenviarla con confirmar = true la registra y sobregira la tarjeta.', tags: ['Tarjetas'], security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'estado', in: 'query', required: false, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 201, description: 'Registrado'), new OA\Response(response: 422, description: 'Validación; error "confirmar" = supera el disponible y falta confirmar el sobregiro')])]
    public function guardarMovimiento(MovimientoTarjetaRequest $request, TarjetaCredito $tarjeta)
    {
        $this->own($request, $tarjeta);
        abort_unless($tarjeta->activo, 403, 'La tarjeta está inactiva.');
        $estado = $request->filled('estado') ? $tarjeta->estadosCuenta()->findOrFail((int) $request->query('estado')) : null;
        $movimiento = $this->tarjetas->registrarMovimiento($tarjeta, $request->validated(), $estado);

        return (new MovimientoTarjetaResource($movimiento->load('categoria', 'contacto')))->response()->setStatusCode(201);
    }

    #[OA\Put(path: '/api/tarjetas/{id}/movimientos/{movimiento}', summary: 'Editar movimiento', description: 'Uno ya facturado se corrige dentro del período de su corte (de contado): el total del corte y su pago pendiente se ajustan, sin bajar de lo ya pagado. Si supera el disponible, igual que al registrar: error "confirmar" y se reenvía con confirmar = true.', tags: ['Tarjetas'], security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Actualizado'), new OA\Response(response: 403, description: 'Avance, mora, compra en cuotas ya cobradas o de otro módulo')])]
    public function actualizarMovimiento(MovimientoTarjetaRequest $request, TarjetaCredito $tarjeta, MovimientoTarjeta $movimiento)
    {
        $this->own($request, $tarjeta, $movimiento);
        $this->tarjetas->actualizarMovimiento($movimiento, $request->validated());

        return new MovimientoTarjetaResource($movimiento->fresh(['categoria', 'contacto']));
    }

    #[OA\Delete(path: '/api/tarjetas/{id}/movimientos/{movimiento}', summary: 'Eliminar movimiento (si ya se facturó, sale de su corte)', tags: ['Tarjetas'], security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 204, description: 'Eliminado'), new OA\Response(response: 403, description: 'No editable')])]
    public function eliminarMovimiento(Request $request, TarjetaCredito $tarjeta, MovimientoTarjeta $movimiento)
    {
        $this->own($request, $tarjeta, $movimiento);
        $this->tarjetas->eliminarMovimiento($movimiento);

        return response()->noContent();
    }

    #[OA\Patch(path: '/api/tarjetas/{id}/estados-cuenta/{estado}/intereses', summary: 'Intereses bonificables de un corte', description: 'El monto que trae el estado de cuenta del banco. Los del último corte se restan del disponible hasta el corte siguiente, aunque ya se haya pagado (igual que en la app del banco). Responde la tarjeta con el disponible nuevo.', tags: ['Tarjetas'], security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['intereses_bonificables'], properties: [new OA\Property(property: 'intereses_bonificables', type: 'string', example: '14.54')])),
        responses: [new OA\Response(response: 200, description: 'Tarjeta actualizada'), new OA\Response(response: 404, description: 'No encontrado'), new OA\Response(response: 422, description: 'Validación')])]
    public function intereses(Request $request, TarjetaCredito $tarjeta, EstadoCuentaTarjeta $estado)
    {
        $this->own($request, $tarjeta);
        abort_if($estado->tarjeta_credito_id !== $tarjeta->id, 404);
        $data = $request->validate(['intereses_bonificables' => ['required', 'numeric', 'min:0', 'regex:/^\d+(?:\.\d{1,2})?$/']]);
        $this->tarjetas->actualizarInteresesBonificables($estado, (float) $data['intereses_bonificables']);

        return $this->recurso($tarjeta);
    }

    private function recurso(TarjetaCredito $tarjeta): TarjetaResource
    {
        return new TarjetaResource($this->conResumen(collect([$tarjeta->fresh(['banco', 'marcaRed'])]))->first());
    }

    /** Agrega deuda, disponible, el próximo corte y el pago pendiente más próximo (igual que la web). */
    private function conResumen(Collection $tarjetas): Collection
    {
        return $tarjetas->each(function (TarjetaCredito $t) {
            $t->setAttribute('deuda', $this->tarjetas->deuda($t));
            $t->setAttribute('disponible', $this->tarjetas->disponible($t));
            $t->setAttribute('intereses_retenidos', $this->tarjetas->interesesRetenidos($t));
            $t->setAttribute('cargos_sin_restar', $this->tarjetas->cargosSinRestar($t));
            $t->setAttribute('proximo_corte', $this->tarjetas->proximoCorte($t, $this->tarjetas->inicioPeriodoAbierto($t)));
            $t->setAttribute('por_pagar', $t->compromisos()->where('estado', Compromiso::PENDIENTE)->orderBy('fecha_esperada')->first());
        });
    }

    private function own(Request $request, TarjetaCredito $tarjeta, ?MovimientoTarjeta $movimiento = null): void
    {
        abort_unless($tarjeta->esDelHogar($request->user()), 404);
        abort_if($movimiento && $movimiento->tarjeta_credito_id !== $tarjeta->id, 404);
    }
}
