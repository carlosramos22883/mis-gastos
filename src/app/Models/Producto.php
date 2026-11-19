<?php

namespace App\Models;

use App\Models\Catalogos\CategoriaDespensa;
use App\Models\Concerns\PerteneceAHogar;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Producto que el hogar compra siempre: «Atún en trocitos Sardimar 140 g». La presentación (cantidad + unidad) permite
 * comparar precios por unidad entre paquetes distintos. La foto va al disco privado. "activo" = falso: archivado.
 */
#[Fillable(['user_id', 'nombre', 'marca', 'cantidad', 'unidad', 'foto', 'codigo_barras', 'categoria_despensa_id', 'importancia',
    'duracion', 'acepta_otra_marca', 'cantidad_ideal', 'avisar_cuando', 'en_casa', 'por_acabarse', 'vence_el', 'despensa_actualizada_at', 'notas', 'activo'])]
class Producto extends Model
{
    use PerteneceAHogar;

    /** Valores fijos: los usa la lógica (prioridad en la lista, vencimiento y precio por unidad). */
    public const IMPORTANCIAS = ['basico' => 'Básico (no puede faltar)', 'ocasional' => 'De vez en cuando'];

    public const DURACIONES = ['perecedero' => 'Perecedero (se vence)', 'duradero' => 'Duradero'];

    public const UNIDADES = ['g' => 'g', 'kg' => 'kg', 'lb' => 'lb', 'oz' => 'oz', 'ml' => 'ml', 'l' => 'L', 'unidad' => 'unidades'];

    protected $table = 'productos';

    protected function casts(): array
    {
        return ['cantidad' => 'decimal:3', 'acepta_otra_marca' => 'boolean', 'activo' => 'boolean',
            'cantidad_ideal' => 'integer', 'avisar_cuando' => 'integer', 'en_casa' => 'integer', 'por_acabarse' => 'boolean',
            'vence_el' => 'date', 'despensa_actualizada_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(CategoriaDespensa::class, 'categoria_despensa_id');
    }

    /** «140 g», «1 L», «12 unidades» (o vacío). */
    public function presentacion(): string
    {
        if ($this->cantidad === null || $this->unidad === null) {
            return '';
        }
        $cantidad = rtrim(rtrim(number_format((float) $this->cantidad, 3, '.', ''), '0'), '.');
        if ($this->unidad === 'unidad') {
            return $cantidad.' '.((float) $this->cantidad === 1.0 ? 'unidad' : 'unidades');
        }

        return $cantidad.' '.self::UNIDADES[$this->unidad];
    }

    /** «Atún en trocitos Sardimar 140 g». */
    public function nombreCompleto(): string
    {
        return trim(implode(' ', array_filter([$this->nombre, $this->marca, $this->presentacion()])));
    }

    public function esBasico(): bool
    {
        return $this->importancia === 'basico';
    }

    /** URL de la foto (servida solo a miembros del hogar), o null. */
    public function urlFoto(bool $api = false): ?string
    {
        return $this->foto ? route($api ? 'api.productos.foto' : 'productos.foto', ['producto' => $this->id, 'v' => substr(md5($this->foto), 0, 8)]) : null;
    }

    /** Ligas con su producto en las tiendas en línea (una por fuente). */
    public function enLinea(): HasMany
    {
        return $this->hasMany(ProductoEnLinea::class);
    }
}
