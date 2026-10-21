<?php

namespace App\Http\Resources;

use App\Models\TarjetaCredito;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TarjetaCredito (con deuda, disponible, proximo_corte y por_pagar calculados en el controlador) */
class TarjetaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $dinero = fn ($v) => number_format((float) $v, 2, '.', '');
        $logo = fn ($m) => $m?->logo ? asset('storage/'.$m->logo) : null;
        $porPagar = $this->por_pagar; // compromiso pendiente más próximo

        return [
            'id' => $this->id,
            'user_id' => $this->user_id, // titular
            'nombre' => $this->nombre,
            'ultimos_digitos' => $this->ultimos_digitos,
            'color' => $this->color,
            'color_texto' => $this->textColor(),
            'banco' => $this->banco ? ['id' => $this->banco->id, 'nombre' => $this->banco->nombre, 'logo_url' => $logo($this->banco)] : null,
            'marca' => $this->marcaRed ? ['id' => $this->marcaRed->id, 'nombre' => $this->marcaRed->nombre, 'logo_url' => $logo($this->marcaRed)] : null,
            'limite_credito' => $dinero($this->limite_credito),
            'tasa_interes' => $this->tasa_interes !== null ? $dinero($this->tasa_interes) : null,
            'permite_avances' => (bool) $this->permite_avances,
            'dia_corte' => $this->dia_corte,
            'dia_pago' => $this->dia_pago,
            'deuda' => $dinero($this->deuda),
            'disponible' => $dinero($this->disponible),
            'proximo_corte' => $this->proximo_corte?->toDateString(),
            'por_pagar' => $porPagar ? ['compromiso_id' => $porPagar->id, 'monto' => $dinero($porPagar->saldo_pendiente), 'fecha' => $porPagar->fecha_esperada?->toDateString()] : null,
            'activo' => (bool) $this->activo,
        ];
    }
}
