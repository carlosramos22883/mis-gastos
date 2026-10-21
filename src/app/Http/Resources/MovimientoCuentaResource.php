<?php

namespace App\Http\Resources;

use App\Models\MovimientoCuenta;
use App\Services\CicloEfectivoService;
use App\Services\CuentaService;
use App\Support\MovimientoVinculado;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Movimiento de una cuenta. "transferencia" indica que nació de una transferencia (se elimina completa desde
 * DELETE /api/transferencias/{id}); "gestionado_en" que lo maneja otro módulo (Deudores, Préstamos, Ingresos).
 *
 * @mixin MovimientoCuenta
 */
class MovimientoCuentaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $inicio = $this->inicioCicloActual($request);
        $t = $this->transferencia;

        return [
            'id' => $this->id,
            'cuenta_id' => $this->cuenta_id,
            'user_id' => $this->user_id,
            'contacto' => $this->contacto ? ['id' => $this->contacto->id, 'nombre' => $this->contacto->nombreCompleto(), 'foto_url' => $this->contacto->urlFoto(true)] : null,
            'descripcion' => $this->descripcion,
            'monto' => number_format((float) $this->monto, 2, '.', ''),
            'fecha' => $this->fecha->toDateString(),
            'tipo' => $this->tipo,
            'categoria' => $this->categoria ? ['id' => $this->categoria->id, 'nombre' => $this->categoria->nombre, 'color' => $this->categoria->color] : null,
            'compromiso_id' => $this->compromiso_id,
            'transferencia' => $t ? [
                'id' => $t->id,
                'origen' => $t->nombreOrigen(),
                'destino' => $t->nombreDestino(),
                'eliminable' => $this->fecha->toDateString() >= $inicio->toDateString(),
            ] : null,
            'gestionado_en' => MovimientoVinculado::modulo($this->resource),
            'editable' => app(CuentaService::class)->esEditable($this->resource, $inicio),
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
