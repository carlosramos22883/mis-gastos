<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAHogar;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Tienda donde compra el hogar. "fuente" = de dónde se leen sus precios en línea; nula = precio anotado. */
#[Fillable(['user_id', 'nombre', 'fuente', 'notas', 'activo'])]
class Tienda extends Model
{
    use PerteneceAHogar;

    /** Valores fijos: cada fuente tiene su conector para leer precios. Walmart y Don Juan comparten catálogo. */
    public const FUENTES = [
        'walmart' => 'Walmart (walmart.com.sv)',
        'donjuan' => 'La Despensa de Don Juan (ladespensadedonjuan.com.sv)',
        'selectos' => 'Súper Selectos (superselectos.com)',
    ];

    /** Las de siempre en El Salvador, para agregarlas de un toque (nombre => fuente). */
    public const COMUNES = [
        'Súper Selectos' => 'selectos', 'Walmart' => 'walmart', 'La Despensa de Don Juan' => 'donjuan', 'PriceSmart' => null,
        'Maxi Despensa' => null, 'Despensa Familiar' => null, 'Dollarcity' => null,
    ];

    protected $table = 'tiendas';

    protected function casts(): array
    {
        return ['activo' => 'boolean'];
    }

    public function precios(): HasMany
    {
        return $this->hasMany(Precio::class);
    }

    public function enLinea(): bool
    {
        return $this->fuente !== null;
    }

    /** @return array<int, string> id => nombre, activas (más la indicada aunque esté inactiva) */
    public static function opciones(User $user, ?int $incluir = null): array
    {
        return static::delHogar($user)->where(fn ($q) => $q->where('activo', true)->when($incluir, fn ($q) => $q->orWhere('id', $incluir)))
            ->orderBy('nombre')->pluck('nombre', 'id')->all();
    }
}
