<?php

namespace App\Http\Resources;

use App\Models\CompraPago;
use App\Models\ListaCompra;
use App\Models\ListaCompraItem;
use App\Services\CompraPagoService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ListaCompra (con "resumen"; con "con_detalle": "partes" de ListaCompraService, "pagos" y "comparacion")
 */
class ListaCompraResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $dinero = fn ($v) => $v === null ? null : number_format((float) $v, 2, '.', '');

        return [
            'id' => $this->id,
            'nombre' => $this->nombre,
            'abierta' => $this->abierta(),
            'terminada_at' => $this->terminada_at?->toIso8601String(),
            'tienda_actual_id' => $this->tienda_actual_id,
            'tienda_actual' => $this->tiendaActual?->nombre,
            'items_count' => $this->whenCounted('items'),
            'comprados_count' => $this->when(isset($this->comprados_count), fn () => (int) $this->comprados_count),
            'resumen' => $this->when(isset($this->resumen), fn () => [
                'total_items' => $this->resumen['total_items'], 'en_carrito' => $this->resumen['en_carrito'], 'total' => $dinero($this->resumen['total']),
                'sin_precio' => $this->resumen['sin_precio'], 'por_pagar' => $this->resumen['por_pagar'],
                'por_pagar_total' => $dinero($this->resumen['por_pagar_total']), 'pagado' => $dinero($this->resumen['pagado']),
            ]),
            'pendientes' => $this->when((bool) $this->con_detalle, fn () => $this->partes['pendientes']->map(fn ($i) => $this->item($i))->values()),
            'comprados' => $this->when((bool) $this->con_detalle, fn () => $this->partes['comprados']->map(fn ($i) => $this->item($i))->values()),
            'pagos' => $this->when((bool) $this->con_detalle, fn () => $this->pagosDetalle->map(fn (CompraPago $p) => [
                'id' => $p->id, 'tienda_id' => $p->tienda_id, 'tienda' => $p->tienda?->nombre, 'origen' => $p->origen,
                'origen_nombre' => app(CompraPagoService::class)->nombreOrigen($p), 'total' => $dinero($p->total), 'fecha' => $p->fecha->toDateString(),
                'user_id' => $p->user_id, 'gastos' => $p->movimientos->map(fn ($m) => ['categoria_personal_id' => $m->categoria_personal_id,
                    'categoria' => $m->categoria?->nombre, 'monto' => $dinero($m->monto)])->values(),
            ])->values()),
            'consultas_restantes' => $this->when(isset($this->consultas_restantes), fn () => $this->consultas_restantes),
        ];
    }

    private function item(ListaCompraItem $i): array
    {
        $dinero = fn ($v) => $v === null ? null : number_format((float) $v, 2, '.', '');
        $p = $i->producto;

        return [
            'id' => $i->id, 'producto_id' => $i->producto_id, 'descripcion' => $i->descripcion, 'cantidad' => $i->cantidad,
            'automatico' => (bool) $i->automatico, 'comprado' => (bool) $i->comprado, 'precio' => $dinero($i->precio), 'subtotal' => $dinero($i->subtotal()),
            'tienda_id' => $i->tienda_id, 'tienda' => $i->tienda?->nombre,
            'no_habia_tienda_id' => $i->no_habia_tienda_id, 'no_habia_el' => $i->no_habia_el?->toDateString(),
            'no_habia' => $i->noHabiaEn ? "No había en {$i->noHabiaEn->nombre} ({$i->no_habia_el->format('d/m')})" : null,
            'extra' => (bool) $i->extra, 'pagado' => $i->compra_pago_id !== null, 'compra_pago_id' => $i->compra_pago_id,
            'categoria' => $p?->categoria?->nombre, 'basico' => $p ? $p->esBasico() : false, 'foto_url' => $p?->urlFoto(true),
            'comparacion' => collect($this->comparacion[$i->id] ?? [])->map(fn ($c) => ['tienda_id' => $c['tienda_id'], 'tienda' => $c['tienda'],
                'por_unidad' => $dinero($c['por_unidad']), 'cuando' => $c['cuando'], 'viejo' => $c['viejo'], 'origen' => $c['origen']])->values(),
        ];
    }
}
