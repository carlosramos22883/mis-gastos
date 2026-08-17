<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Attributes\Fillable;

#[Fillable(['nombre', 'codigo', 'simbolo', 'activo'])]
class Moneda extends Model
{
    protected $casts = [
        'activo' => 'boolean',
    ];
}