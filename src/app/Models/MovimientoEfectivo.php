<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAHogar;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'contacto_id', 'categoria_personal_id', 'compromiso_id', 'compromiso_cuota', 'descripcion', 'monto', 'fecha', 'tipo', 'es_saldo_arrastrado', 'transferencia_id'])]
class MovimientoEfectivo extends Model
{
    use HasFactory, PerteneceAHogar;

    protected $table = 'movimientos_efectivo';

    protected $casts = [
        'monto' => 'decimal:2',
        'fecha' => 'date',
        'es_saldo_arrastrado' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(CategoriaPersonal::class, 'categoria_personal_id');
    }

    public function compromiso(): BelongsTo
    {
        return $this->belongsTo(Compromiso::class);
    }

    public function signedAmount(): float
    {
        return $this->tipo === 'ingreso' ? (float) $this->monto : -(float) $this->monto;
    }

    public function transferencia(): BelongsTo
    {
        return $this->belongsTo(Transferencia::class);
    }

    /** Con quién (persona, tienda, empresa…). */
    public function contacto(): BelongsTo
    {
        return $this->belongsTo(Contacto::class);
    }
}
