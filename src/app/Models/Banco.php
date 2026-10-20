<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['nombre', 'tipo', 'logo', 'activo'])]
class Banco extends Model
{
    /** Emisores de cuentas, tarjetas y préstamos (el catálogo se llama "bancos" por historia). */
    public const TIPOS = ['banco' => 'Banco', 'tienda' => 'Tienda', 'financiera' => 'Financiera', 'cooperativa' => 'Cooperativa'];

    protected $casts = [
        'activo' => 'boolean',
    ];
}
