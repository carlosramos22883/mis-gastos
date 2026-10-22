<?php

namespace App\Http\Resources;

use App\Models\Prestamo;
use App\Services\PrestamoService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Prestamo */
class PrestamoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $servicio = app(PrestamoService::class);
        $r = $servicio->resumen($this->resource);
        $dinero = fn ($v) => $v === null ? null : number_format((float) $v, 2, '.', '');

        return [
            'id' => $this->id,
            'user_id' => $this->user_id, // titular
            'nombre' => $this->nombre,
            'tipo_prestamo_id' => $this->tipo_prestamo_id,
            'tipo' => $this->tipoPrestamo?->nombre,
            'banco_id' => $this->banco_id,
            'acreedor' => $this->nombreAcreedor(),
            // Logo del banco o, si es de una persona, la foto del contacto (requiere el token)
            'banco' => $this->banco ? ['id' => $this->banco->id, 'nombre' => $this->banco->nombre, 'logo_url' => $this->banco->logo ? asset('storage/'.$this->banco->logo) : null] : null,
            'contacto' => $this->contacto ? ['id' => $this->contacto->id, 'nombre' => $this->contacto->nombreCompleto(), 'foto_url' => $this->contacto->urlFoto(true)] : null,
            'monto_original' => $dinero($this->monto_original),
            'tasa_anual' => (string) (float) $this->tasa_anual,
            'plazo_meses' => $this->plazo_meses,
            'fecha_otorgamiento' => $this->fecha_otorgamiento?->toDateString(),
            'dia_pago' => $this->dia_pago,
            'medio_pago' => $this->medio_pago,
            'monto_planilla' => $dinero($this->monto_planilla),
            'color' => $this->color,
            'activo' => (bool) $this->activo,
            'cargos' => $this->cargos->map(fn ($c) => ['nombre' => $c->nombre, 'monto' => $dinero($c->monto)])->values(),
            'saldo' => $dinero($r['saldo']),
            'cuota' => $dinero($r['cuota']),
            'cuota_total' => $dinero($r['cuota_total']),
            'avance' => $r['avance'],
            'proxima_fecha' => $r['proxima_fecha'],
            'fecha_fin' => $r['fecha_fin'],
            'meses_restantes' => $r['meses_restantes'],
            'intereses_restantes' => $dinero($r['intereses_restantes']),
            'compromiso_recurrente_id' => $this->compromiso_recurrente_id,
            // Datos tal como se guardaron (para editar el préstamo desde las apps)
            'datos' => [
                'contacto_id' => $this->contacto_id,
                'acreedor' => $this->acreedor,
                'tasa_mora' => $this->tasa_mora !== null ? (string) (float) $this->tasa_mora : null,
                'cuota' => $dinero($this->cuota),
                'saldo_inicial' => $dinero($this->saldo_inicial),
                'fecha_saldo' => $this->fecha_saldo?->toDateString(),
                'notas' => $this->notas,
            ],
            'pagos' => $this->when((bool) $this->con_detalle, fn () => $this->pagos->sortByDesc('fecha')->map(fn ($p) => [
                'id' => $p->id, 'user_id' => $p->user_id, 'fecha' => $p->fecha->toDateString(), 'origen' => $p->origen,
                'planilla' => $dinero($p->planilla), 'capital' => $dinero($p->capital), 'interes' => $dinero($p->interes), 'cargos' => $dinero($p->cargos), 'mora' => $dinero($p->mora),
                'total' => $dinero($p->total()), 'comentario' => $p->comentario,
            ])->values()),
            'sugerencia_pago' => $this->when((bool) $this->con_detalle && $r['saldo'] > 0, fn () => collect($servicio->sugerencia($this->resource))
                ->map(fn ($v, $k) => $k === 'fecha' ? $v : $dinero($v))),
        ];
    }
}
