<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Attributes\Fillable;

#[Fillable(['nombre', 'descripcion', 'activo'])]
class TipoCuenta extends Model
{
    protected $casts = [
        'activo' => 'boolean',
    ];
}