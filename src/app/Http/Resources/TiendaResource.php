<?php

namespace App\Http\Resources;

use App\Models\Tienda;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Tienda */
class TiendaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nombre' => $this->nombre,
            'fuente' => $this->fuente, // walmart | donjuan | selectos | null (precio anotado)
            'en_linea' => $this->enLinea(),
            'notas' => $this->notas,
            'activo' => (bool) $this->activo,
            'precios_count' => $this->whenCounted('precios'),
        ];
    }
}
