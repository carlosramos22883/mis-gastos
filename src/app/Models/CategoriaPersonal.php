<?php

namespace App\Models;

use App\Support\Color;
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

    public function isInitialBalance(): bool
    {
        return $this->nombre === 'Saldo inicial';
    }

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
        return Color::textoSobre($this->color);
    }
}
