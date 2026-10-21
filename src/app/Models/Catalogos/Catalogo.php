<?php

namespace App\Models\Catalogos;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Catálogo simple que mantiene el administrador (nombre, descripción, orden, activo).
 * Se usa en los selectores con opciones(): los activos, más el valor actual aunque esté inactivo.
 */
abstract class Catalogo extends Model
{
    protected $fillable = ['nombre', 'descripcion', 'orden', 'activo'];

    protected $casts = ['activo' => 'boolean', 'orden' => 'integer'];

    public function scopeOrdenados(Builder $query): void
    {
        $query->orderBy('orden')->orderBy('nombre');
    }

    /** @return array<int, string> id => nombre */
    public static function opciones(?int $incluir = null): array
    {
        return static::query()->where(fn ($q) => $q->where('activo', true)->when($incluir, fn ($q) => $q->orWhere('id', $incluir)))
            ->ordenados()->pluck('nombre', 'id')->all();
    }

    /** El primero activo (para valores por defecto). */
    public static function predeterminado(): ?int
    {
        return static::query()->where('activo', true)->ordenados()->value('id');
    }
}
