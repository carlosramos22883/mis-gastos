<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Attributes\Fillable;

#[Fillable(['nombre', 'logo', 'activo'])]
class MarcaRed extends Model
{
    protected $casts = [
        'activo' => 'boolean',
    ];
}