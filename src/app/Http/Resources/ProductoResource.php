<?php

namespace App\Http\Resources;

use App\Models\Precio;
use App\Models\Producto;
use App\Models\ProductoEnLinea;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Producto (con "estado" de DespensaService::estado; con "con_detalle": precios, historial y ligas en línea)
 */
class ProductoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nombre' => $this->nombre,
            'marca' => $this->marca,
            'cantidad' => $this->cantidad !== null ? rtrim(rtrim((string) $this->cantidad, '0'), '.') : null,
            'unidad' => $this->unidad,
            'presentacion' => $this->presentacion(),
            'nombre_completo' => $this->nombreCompleto(),
            'codigo_barras' => $this->codigo_barras,
            'categoria_despensa_id' => $this->categoria_despensa_id,
            'categoria' => $this->categoria?->nombre,
            'importancia' => $this->importancia,
            'duracion' => $this->duracion,
            'acepta_otra_marca' => (bool) $this->acepta_otra_marca,
            'cantidad_ideal' => $this->cantidad_ideal,
            'avisar_cuando' => $this->avisar_cuando,
            'notas' => $this->notas,
            'activo' => (bool) $this->activo,
            'foto_url' => $this->urlFoto(true), // requiere el token
            'estado' => $this->estado, // en_casa, por_acabarse, vence_el, vencimiento{texto, aviso}, falta, por_comprar
            'precios' => $this->when((bool) $this->con_detalle, fn () => $this->ultimos->map(fn (Precio $p) => self::precio($p))->values()),
            'historial' => $this->when((bool) $this->con_detalle, fn () => $this->historial->map(fn (Precio $p) => self::precio($p))->values()),
            'en_linea' => $this->when((bool) $this->con_detalle, fn () => $this->enlaces->map(fn (ProductoEnLinea $e) => [
                'fuente' => $e->fuente, 'codigo' => $e->codigo, 'nombre' => $e->nombre, 'url' => $e->url, 'imagen' => $e->imagen,
                'unidades' => $e->unidades, 'consultado_at' => $e->consultado_at?->toIso8601String(), 'error' => $e->error,
            ])->values()),
        ];
    }

    public static function precio(Precio $p): array
    {
        return ['id' => $p->id, 'tienda_id' => $p->tienda_id, 'tienda' => $p->tienda?->nombre, 'precio' => number_format((float) $p->precio, 2, '.', ''),
            'unidades' => $p->unidades, 'por_unidad' => number_format($p->porUnidad(), 2, '.', ''), 'origen' => $p->origen, 'fecha' => $p->fecha->toDateString()];
    }
}
