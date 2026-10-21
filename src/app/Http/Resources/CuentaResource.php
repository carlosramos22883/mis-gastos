<?php

namespace App\Http\Resources;

use App\Models\Cuenta;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Cuenta (con saldo_actual de CuentaService::saldo) */
class CuentaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id, // titular (miembro del hogar)
            'nombre' => $this->nombre,
            'color' => $this->color,
            'tipo_cuenta' => $this->tipoCuenta ? ['id' => $this->tipoCuenta->id, 'nombre' => $this->tipoCuenta->nombre] : null,
            'banco' => $this->banco ? ['id' => $this->banco->id, 'nombre' => $this->banco->nombre, 'logo_url' => $this->banco->logo ? asset('storage/'.$this->banco->logo) : null] : null,
            'saldo_inicial' => number_format((float) $this->saldo_inicial, 2, '.', ''),
            'fecha_inicial' => $this->fecha_inicial?->toDateString(),
            'saldo' => number_format((float) $this->saldo_actual, 2, '.', ''),
            'activo' => (bool) $this->activo,
        ];
    }
}
