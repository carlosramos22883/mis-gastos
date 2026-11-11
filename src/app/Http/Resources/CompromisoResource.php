<?php

namespace App\Http\Resources;

use App\Models\Compromiso;
use App\Services\CompromisoService;
use App\Services\PresupuestoService;
use App\Services\TarjetaService;
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
            'user_id' => $this->user_id, // quién lo hizo (miembro del hogar)
            'contacto' => $this->contacto ? ['id' => $this->contacto->id, 'nombre' => $this->contacto->nombreCompleto(), 'foto_url' => $this->contacto->urlFoto(true)] : null,
            'descripcion' => $this->descripcion,
            'comentario' => $this->comentario,
            'monto' => number_format((float) $this->monto, 2, '.', ''),
            'saldo_pendiente' => number_format((float) $this->saldo_pendiente, 2, '.', ''),
            // Presupuesto (súper, gasolina…): no se paga; saldo_pendiente = lo que queda tras los gastos de su categoría
            'es_presupuesto' => (bool) $this->es_presupuesto,
            // Solo en presupuestos: monto, gastado, comprometido (compromisos de su categoría por pagar) y queda (< 0 = se pasó);
            // en el detalle, también los gastos que lo descuentan
            'presupuesto' => $this->es_presupuesto ? $this->presupuesto() : null,
            'monto_pagado' => number_format($this->montoPagado(), 2, '.', ''),
            'fecha_esperada' => $this->fecha_esperada?->toDateString(),
            'ciclo_inicio' => $this->ciclo_inicio?->toDateString(),
            'numero_cuota' => $this->numero_cuota,
            'total_cuotas' => $this->total_cuotas,
            'medio_pago' => $this->medio_pago,
            // Estado de cuenta de una tarjeta: se paga sin categoría (cada compra ya tiene la suya)
            'tarjeta' => $this->tarjeta_credito_id ? ['id' => $this->tarjeta_credito_id, 'nombre' => $this->tarjeta?->nombre, 'color' => $this->tarjeta?->color] : null,
            // Lo pagado de más a una tarjeta: quedó como crédito en ella
            'excedente' => number_format($this->tarjeta_credito_id ? app(TarjetaService::class)->excedente($this->resource) : 0, 2, '.', ''),
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
                'es_presupuesto' => (bool) $this->recurrente->es_presupuesto,
                'descripcion_frecuencia' => $this->recurrente->descripcionFrecuencia(),
                'activo' => (bool) $this->recurrente->activo,
                // La regla completa (para editar la serie desde las apps)
                'descripcion' => $this->recurrente->descripcion,
                'monto' => number_format((float) $this->recurrente->monto, 2, '.', ''),
                'dia_semana' => $this->recurrente->dia_semana,
                'dia_pago' => $this->recurrente->dia_pago,
                'dia_pago_2' => $this->recurrente->dia_pago_2,
                'fecha_inicio' => $this->recurrente->fecha_inicio?->toDateString(),
                'fecha_fin' => $this->recurrente->fecha_fin?->toDateString(),
                'total_cuotas' => $this->recurrente->total_cuotas,
                'categoria_personal_id' => $this->recurrente->categoria_personal_id,
                'medio_pago' => $this->recurrente->medio_pago,
            ] : null),
            'abonos' => MovimientoEfectivoResource::collection($this->whenLoaded('movimientos')),
        ];
    }

    private function presupuesto(): array
    {
        $servicio = app(PresupuestoService::class);
        $dinero = fn ($v) => number_format((float) $v, 2, '.', '');
        $resumen = array_map($dinero, $servicio->resumen($this->resource));
        if ($this->relationLoaded('movimientos')) { // detalle
            $resumen['movimientos'] = $servicio->movimientos($this->resource)->map(fn ($m) => ['clase' => $m->clase, 'id' => $m->id,
                'fecha' => $m->fecha->toDateString(), 'descripcion' => $m->descripcion, 'origen' => $m->origen, 'monto' => $dinero($m->monto)])->all();
        }

        return $resumen;
    }
}
