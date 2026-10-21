<?php

namespace App\Models\Catalogos;

/** Atajo de la plantilla de ingresos (ISSS 3 % con tope, AFP 7.25 %…): valores de ley que mantiene el administrador. */
class ConceptoPlanilla extends Catalogo
{
    protected $table = 'conceptos_planilla';

    protected $fillable = ['nombre', 'tipo', 'porcentaje', 'tope', 'monto', 'descripcion', 'orden', 'activo'];

    protected $casts = ['activo' => 'boolean', 'orden' => 'integer', 'porcentaje' => 'decimal:3', 'tope' => 'decimal:2', 'monto' => 'decimal:2'];

    /** Para los botones de la plantilla: {nombre, tipo, porcentaje, tope, monto}. */
    public static function atajos(): array
    {
        return static::query()->where('activo', true)->ordenados()->get()->map(fn ($c) => [
            'nombre' => $c->nombre, 'tipo' => $c->tipo,
            'porcentaje' => $c->porcentaje !== null ? (float) $c->porcentaje : '',
            'tope' => $c->tope !== null ? number_format((float) $c->tope, 2, '.', '') : '',
            'monto' => $c->monto !== null ? number_format((float) $c->monto, 2, '.', '') : '',
        ])->all();
    }
}
