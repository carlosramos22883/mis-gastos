<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAHogar;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/** Persona, institución o empresa que le debe dinero al hogar. Lo pendiente se calcula en DeudorService. */
#[Fillable(['user_id', 'nombre', 'tipo', 'contacto', 'notas', 'activo'])]
class Deudor extends Model
{
    use PerteneceAHogar;

    public const TIPOS = ['persona' => 'Persona', 'institucion' => 'Institución', 'empresa' => 'Empresa'];

    protected $table = 'deudores';

    protected $casts = ['activo' => 'boolean'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function deudas(): HasMany
    {
        return $this->hasMany(Deuda::class)->orderBy('fecha')->orderBy('id');
    }

    public function pagos(): HasManyThrough
    {
        return $this->hasManyThrough(DeudaPago::class, Deuda::class);
    }
}
