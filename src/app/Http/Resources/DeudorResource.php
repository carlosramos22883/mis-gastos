<?php

namespace App\Http\Resources;

use App\Models\Deuda;
use App\Models\Deudor;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Deudor (con prestado, pagado y pendiente de DeudorService::resumir) */
class DeudorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $dinero = fn ($v) => number_format((float) $v, 2, '.', '');

        return [
            'id' => $this->id,
            'nombre' => $this->nombre,
            'tipo' => $this->tipo,
            'contacto' => $this->contacto,
            'notas' => $this->notas,
            'activo' => (bool) $this->activo,
            'prestado' => $dinero($this->prestado),
            'pagado' => $dinero($this->pagado),
            'pendiente' => $dinero($this->pendiente),
            'deudas_abiertas' => (int) $this->deudas_abiertas,
            'deudas' => $this->when((bool) $this->con_detalle, fn () => $this->deudas->map(fn (Deuda $d) => [
                'id' => $d->id,
                'user_id' => $d->user_id,
                'descripcion' => $d->descripcion,
                'comentario' => $d->comentario,
                'fecha' => $d->fecha->toDateString(),
                'origen' => $d->origen,
                'monto' => $dinero($d->monto),
                'pagado' => $dinero($d->pagado()),
                'pendiente' => $dinero($d->pendiente()),
                'estado' => $d->estado(),
                'pagos' => $d->pagos->map(fn ($p) => [
                    'id' => $p->id, 'user_id' => $p->user_id, 'fecha' => $p->fecha->toDateString(), 'monto' => $dinero($p->monto),
                    'destino' => $p->destino, 'comentario' => $p->comentario,
                ])->values(),
            ])->values()),
        ];
    }
}
