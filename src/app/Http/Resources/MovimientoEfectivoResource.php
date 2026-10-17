<?php

namespace App\Http\Resources;

use App\Models\MovimientoEfectivo;
use App\Services\CicloEfectivoService;
use App\Services\MovimientoEfectivoService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Los montos viajan como texto con dos decimales ("12.50") para no perder precisión en los clientes.
 *
 * @mixin MovimientoEfectivo
 */
class MovimientoEfectivoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'descripcion' => $this->descripcion,
            'monto' => number_format((float) $this->monto, 2, '.', ''),
            'fecha' => $this->fecha->toDateString(),
            'tipo' => $this->tipo,
            'categoria' => $this->whenLoaded('categoria', fn () => [
                'id' => $this->categoria->id,
                'nombre' => $this->categoria->nombre,
                'color' => $this->categoria->color,
            ]),
            'compromiso_id' => $this->compromiso_id,
            'es_saldo_arrastrado' => (bool) $this->es_saldo_arrastrado,
            'editable' => app(MovimientoEfectivoService::class)->esEditable($this->resource, $this->inicioCicloActual($request)),
        ];
    }

    /** Se calcula una vez por petición, no por cada movimiento de la lista. */
    private function inicioCicloActual(Request $request): Carbon
    {
        if (! $request->attributes->has('inicio_ciclo_actual')) {
            $request->attributes->set('inicio_ciclo_actual', app(CicloEfectivoService::class)->cicloConsultado($request->user())[0]);
        }

        return $request->attributes->get('inicio_ciclo_actual');
    }
}
