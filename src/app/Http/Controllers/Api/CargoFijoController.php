<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CargoFijoRequest;
use App\Models\CargoFijoTarjeta;
use App\Models\TarjetaCredito;
use App\Services\TarjetaService;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/** Cargos fijos de una tarjeta (seguro, Netflix…): cada mes, el día indicado, se crea solo su movimiento. */
class CargoFijoController extends Controller
{
    public function __construct(private readonly TarjetaService $tarjetas) {}

    #[OA\Post(path: '/api/tarjetas/{id}/cargos-fijos', summary: 'Agregar un cargo fijo', description: 'Cada mes, el día "dia" (1-31; 31 = último día del mes), se crea un movimiento "cargo" en la tarjeta, desde "desde" y hasta "hasta" (opcional). Sin "dia" se usa el día de "desde".', tags: ['Tarjetas'], security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 201, description: 'Agregado'), new OA\Response(response: 422, description: 'Validación')])]
    public function store(CargoFijoRequest $request, TarjetaCredito $tarjeta)
    {
        $this->own($request, $tarjeta);
        $cargo = $tarjeta->cargosFijos()->create($request->datos() + ['user_id' => $request->user()->id]);
        $this->tarjetas->generarEstados($tarjeta); // si ya tocaba cobrarlo, su movimiento se crea de una vez

        return response()->json(['data' => self::datos($cargo->fresh())], 201);
    }

    #[OA\Put(path: '/api/tarjetas/{id}/cargos-fijos/{cargo}', summary: 'Editar un cargo fijo (aplica desde su próximo cobro)', tags: ['Tarjetas'], security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Actualizado'), new OA\Response(response: 404, description: 'No es de esta tarjeta')])]
    public function update(CargoFijoRequest $request, TarjetaCredito $tarjeta, CargoFijoTarjeta $cargo)
    {
        $this->own($request, $tarjeta, $cargo);
        $cargo->update($request->datos());
        $this->tarjetas->generarEstados($tarjeta);

        return response()->json(['data' => self::datos($cargo->fresh())]);
    }

    #[OA\Delete(path: '/api/tarjetas/{id}/cargos-fijos/{cargo}', summary: 'Eliminar un cargo fijo (lo ya cobrado queda como movimiento de la tarjeta)', tags: ['Tarjetas'], security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 204, description: 'Eliminado')])]
    public function destroy(Request $request, TarjetaCredito $tarjeta, CargoFijoTarjeta $cargo)
    {
        $this->own($request, $tarjeta, $cargo);
        $cargo->delete();

        return response()->noContent();
    }

    /** Mismo formato que "cargos_fijos" en GET /api/tarjetas/{id}. */
    public static function datos(CargoFijoTarjeta $c): array
    {
        return [
            'id' => $c->id, 'descripcion' => $c->descripcion, 'monto' => number_format((float) $c->monto, 2, '.', ''),
            'categoria_personal_id' => $c->categoria_personal_id, 'dia' => $c->dia, 'desde' => $c->desde->toDateString(),
            'hasta' => $c->hasta?->toDateString(), 'ultimo_cobro' => $c->ultimo_cobro?->toDateString(),
            'proximo_cobro' => $c->activo ? app(TarjetaService::class)->proximoCobro($c)?->toDateString() : null, 'activo' => (bool) $c->activo,
        ];
    }

    private function own(Request $request, TarjetaCredito $tarjeta, ?CargoFijoTarjeta $cargo = null): void
    {
        abort_unless($tarjeta->esDelHogar($request->user()), 404);
        abort_if($cargo && $cargo->tarjeta_credito_id !== $tarjeta->id, 404);
    }
}
