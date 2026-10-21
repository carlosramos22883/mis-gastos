<?php

namespace App\Http\Resources;

use App\Models\MovimientoTarjeta;
use App\Services\TarjetaService;
use App\Support\MovimientoVinculado;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Movimiento de una tarjeta (compra, avance, cargo o crédito). Un avance se elimina desde su transferencia;
 * uno ya facturado (en un estado de cuenta) ya no se modifica.
 *
 * @mixin MovimientoTarjeta
 */
class MovimientoTarjetaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tarjeta_id' => $this->tarjeta_credito_id,
            'user_id' => $this->user_id,
            'contacto' => $this->contacto ? ['id' => $this->contacto->id, 'nombre' => $this->contacto->nombreCompleto(), 'foto_url' => $this->contacto->urlFoto(true)] : null,
            'tipo' => $this->tipo,
            'tipo_texto' => MovimientoTarjeta::TIPOS[$this->tipo] ?? $this->tipo,
            'descripcion' => $this->descripcion,
            'comentario' => $this->comentario,
            'monto' => number_format((float) $this->monto, 2, '.', ''),
            'fecha' => $this->fecha->toDateString(),
            'cuotas' => $this->cuotas,
            'categoria' => $this->categoria ? ['id' => $this->categoria->id, 'nombre' => $this->categoria->nombre, 'color' => $this->categoria->color] : null,
            'transferencia_id' => $this->transferencia_id,
            'pago_compromiso_id' => $this->pago_compromiso_id,
            'gestionado_en' => MovimientoVinculado::modulo($this->resource),
            'editable' => app(TarjetaService::class)->esEditable($this->resource),
        ];
    }
}
