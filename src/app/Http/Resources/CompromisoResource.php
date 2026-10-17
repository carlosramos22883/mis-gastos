<?php

namespace App\Http\Resources;

use App\Models\Compromiso;
use App\Services\CompromisoService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Compromiso */
class CompromisoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        [$hoy, $inicio] = app(CompromisoService::class)->fechas($request->user());
        $estado = $this->estadoVisual($hoy, $inicio);

        return [
            'id' => $this->id,
            'descripcion' => $this->descripcion,
            'comentario' => $this->comentario,
            'monto' => number_format((float) $this->monto, 2, '.', ''),
            'saldo_pendiente' => number_format((float) $this->saldo_pendiente, 2, '.', ''),
            'monto_pagado' => number_format($this->montoPagado(), 2, '.', ''),
            'fecha_esperada' => $this->fecha_esperada?->toDateString(),
            'ciclo_inicio' => $this->ciclo_inicio?->toDateString(),
            'numero_cuota' => $this->numero_cuota,
            'total_cuotas' => $this->total_cuotas,
            'medio_pago' => $this->medio_pago,
            'estado' => $this->estado,
            'estado_visual' => $estado,
            'estado_texto' => Compromiso::ESTADOS_VISUALES[$estado] ?? $estado,
            'categoria' => $this->whenLoaded('categoria', fn () => $this->categoria ? [
                'id' => $this->categoria->id,
                'nombre' => $this->categoria->nombre,
                'color' => $this->categoria->color,
            ] : null),
            'recurrente' => $this->whenLoaded('recurrente', fn () => $this->recurrente ? [
                'id' => $this->recurrente->id,
                'frecuencia' => $this->recurrente->frecuencia,
                'descripcion_frecuencia' => $this->recurrente->descripcionFrecuencia(),
                'activo' => (bool) $this->recurrente->activo,
            ] : null),
            'abonos' => MovimientoEfectivoResource::collection($this->whenLoaded('movimientos')),
        ];
    }
}
