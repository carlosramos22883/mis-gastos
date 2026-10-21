<?php

namespace App\Models;

use App\Models\Catalogos\TipoEmisor;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['nombre', 'tipo_emisor_id', 'logo', 'activo'])]
class Banco extends Model
{
    protected $casts = [
        'activo' => 'boolean',
    ];

    /** Tipo (catálogo que mantiene el administrador). */
    public function tipoEmisor(): BelongsTo
    {
        return $this->belongsTo(TipoEmisor::class, 'tipo_emisor_id');
    }
}
