<?php

namespace App\Http\Resources;

use App\Models\Evento;
use App\Models\MovimientoCuenta;
use App\Models\MovimientoTarjeta;
use App\Services\EventoService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Evento como una tabla: participantes (filas) y consumos/productos (columnas); en cada producto, "cantidades" = cuántos
 * pidió cada participante, en el orden de "participantes" (como se envían al guardar). Estados: pagador, festejado, hogar (pagó el hogar y es un miembro), pendiente, parcial, pagado.
 *
 * @mixin Evento
 */
class EventoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $resumen = $this->resource->getAttribute('resumen') ?? app(EventoService::class)->resumen($this->resource);
        $dinero = fn ($v) => number_format((float) $v, 2, '.', '');
        $movimiento = $this->movimiento();

        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'nombre' => $this->nombre,
            'fecha' => $this->fecha->toDateString(),
            'lugar' => $this->lugar,
            'comentario' => $this->comentario,
            'propina_tipo' => $this->propina_tipo,
            'propina_valor' => $dinero($this->propina_valor),
            'origen' => $this->origen, // efectivo | cuenta | tarjeta | ninguno | null (pagó otra persona)
            // Valor para el selector "pagado desde" al editar: "efectivo" | "cuenta:{id}" | "tarjeta:{id}" | "ninguno"
            'origen_valor' => match (true) {
                $movimiento instanceof MovimientoCuenta => 'cuenta:'.$movimiento->cuenta_id,
                $movimiento instanceof MovimientoTarjeta => 'tarjeta:'.$movimiento->tarjeta_credito_id,
                $movimiento !== null => 'efectivo',
                default => $this->origen ? 'ninguno' : null,
            },
            'categoria_personal_id' => $this->categoria_personal_id,
            'movimiento' => $movimiento ? ['clase' => match (true) {
                $movimiento instanceof MovimientoCuenta => 'cuenta', $movimiento instanceof MovimientoTarjeta => 'tarjeta', default => 'efectivo'
            }, 'id' => $movimiento->id] : null,
            'pago_el_hogar' => $resumen['pago_el_hogar'],
            'subtotal' => $dinero($resumen['subtotal']),
            'propina' => $dinero($resumen['propina']),
            'total' => $dinero($resumen['total']),
            'por_cobrar' => $dinero($resumen['por_cobrar']),
            'participantes' => $this->participantes->map(function ($p) use ($resumen, $dinero) {
                $r = $resumen['participantes'][$p->id];

                return [
                    'id' => $p->id,
                    'quien' => $p->quien(), // "miembro:{id}" o el id del contacto (para editar)
                    'nombre' => $p->nombre(),
                    'user_id' => $p->user_id,
                    'contacto' => $p->contacto ? ['id' => $p->contacto->id, 'nombre' => $p->contacto->nombreCompleto(), 'foto_url' => $p->contacto->urlFoto(true)] : null,
                    'es_festejado' => $p->es_festejado,
                    'es_pagador' => $p->es_pagador,
                    'consumo' => $dinero($r['consumo']),
                    'propina' => $dinero($r['propina']),
                    'festejo' => $dinero($r['festejo']),
                    'total' => $dinero($r['total']),
                    'pagado' => $dinero($r['pagado']),
                    'pendiente' => $dinero($r['pendiente']),
                    'estado' => $r['estado'],
                    'fecha_pago' => $p->fecha_pago?->toDateString(),
                    'deuda_id' => $p->deuda_id,
                    'cobros' => $p->deuda ? $p->deuda->pagos()->orderBy('fecha')->get()->map(fn ($pago) => ['id' => $pago->id, 'fecha' => $pago->fecha->toDateString(),
                        'monto' => $dinero($pago->monto), 'destino' => $pago->destino])->values() : [],
                    'detalle' => collect($r['detalle'])->map(fn ($d) => ['descripcion' => $d['descripcion'], 'cantidad' => $d['cantidad'], 'precio' => $dinero($d['precio']), 'monto' => $dinero($d['monto'])])->values(),
                ];
            })->values(),
            'consumos' => $this->consumos->map(function ($c) use ($dinero) {
                $porId = $c->participantes->pluck('pivot.cantidad', 'id');

                return ['id' => $c->id, 'descripcion' => $c->descripcion, 'precio' => $dinero($c->precio), 'cantidad' => $c->cantidad(), 'total' => $dinero($c->total()),
                    'cantidades' => $this->participantes->map(fn ($p) => (int) ($porId[$p->id] ?? 0))->values()];
            })->values(),
            'foto_url' => $this->foto ? route('api.eventos.foto', [$this->id, 'v' => $this->versionFoto()]) : null, // foto de la cuenta (con el token)
        ];
    }
}
