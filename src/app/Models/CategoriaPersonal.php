<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['user_id', 'nombre', 'tipo', 'color', 'activo'])]
class CategoriaPersonal extends Model
{
    use HasFactory;

    protected $table = 'categorias_personales';

    protected $casts = ['activo' => 'boolean'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function movimientos(): HasMany
    {
        return $this->hasMany(MovimientoEfectivo::class, 'categoria_personal_id');
    }

    public function textColor(): string
    {
        $hex = ltrim($this->color ?: '#64748B', '#');
        $rgb = array_map('hexdec', str_split($hex, 2));
        $luminance = (0.299 * $rgb[0]) + (0.587 * $rgb[1]) + (0.114 * $rgb[2]);

        return $luminance > 186 ? '#111827' : '#FFFFFF';
    }
}
