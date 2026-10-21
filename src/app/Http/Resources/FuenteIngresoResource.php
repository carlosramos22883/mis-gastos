<?php

namespace App\Http\Resources;

use App\Models\FuenteIngreso;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin FuenteIngreso */
class FuenteIngresoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $dinero = fn ($v) => $v === null ? null : number_format((float) $v, 2, '.', '');

        return [
            'id' => $this->id,
            'user_id' => $this->user_id, // quién lo recibe
            'nombre' => $this->nombre,
            'tipo_ingreso_id' => $this->tipo_ingreso_id,
            'tipo' => $this->tipoIngreso?->nombre,
            'pagador' => $this->pagador,
            'frecuencia' => $this->frecuencia,
            'dia_pago' => $this->dia_pago,
            'dia_pago_2' => $this->dia_pago_2,
            'monto_bruto' => $dinero($this->monto_bruto),
            'destino' => $this->destino,
            'color' => $this->color,
            'activo' => (bool) $this->activo,
            'conceptos' => $this->conceptos->map(fn ($c) => [
                'nombre' => $c->nombre, 'tipo' => $c->tipo, 'monto' => $dinero($c->monto),
                'porcentaje' => $c->porcentaje !== null ? (string) (float) $c->porcentaje : null, 'tope' => $dinero($c->tope), 'prestamo_id' => $c->prestamo_id,
            ])->values(),
            'recibos' => $this->when((bool) $this->con_detalle, fn () => $this->ingresos->sortByDesc('fecha')->map(fn ($i) => [
                'id' => $i->id, 'user_id' => $i->user_id, 'fecha' => $i->fecha->toDateString(), 'bruto' => $dinero($i->bruto), 'neto' => $dinero($i->neto),
                'destino' => $i->destino, 'comentario' => $i->comentario,
                'lineas' => $i->lineas->map(fn ($l) => ['nombre' => $l->nombre, 'tipo' => $l->tipo, 'monto' => $dinero($l->monto), 'prestamo_pago_id' => $l->prestamo_pago_id])->values(),
            ])->values()),
        ];
    }
}
