<?php

namespace App\Http\Resources;

use App\Models\CategoriaPersonal;
use App\Services\CicloEfectivoService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CategoriaPersonal */
class CategoriaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nombre' => $this->nombre,
            'tipo' => $this->tipo,
            'color' => $this->color,
            'activo' => (bool) $this->activo,
            'editable' => $this->nombre !== CicloEfectivoService::CATEGORIA_SALDO_INICIAL,
        ];
    }
}
