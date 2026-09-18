<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'categoria_personal_id', 'descripcion', 'monto', 'fecha', 'tipo'])]
class MovimientoEfectivo extends Model
{
    use HasFactory;

    protected $table = 'movimientos_efectivo';

    protected $casts = [
        'monto' => 'decimal:2',
        'fecha' => 'date',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(CategoriaPersonal::class, 'categoria_personal_id');
    }

    public function signedAmount(): float
    {
        return $this->tipo === 'ingreso' ? (float) $this->monto : -(float) $this->monto;
    }
}
