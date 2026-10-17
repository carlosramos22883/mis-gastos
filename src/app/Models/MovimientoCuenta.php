<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'cuenta_id', 'categoria_personal_id', 'compromiso_id', 'transferencia_id', 'descripcion', 'monto', 'fecha', 'tipo'])]
class MovimientoCuenta extends Model
{
    protected $table = 'movimientos_cuenta';

    protected function casts(): array
    {
        return ['monto' => 'decimal:2', 'fecha' => 'date'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function cuenta(): BelongsTo
    {
        return $this->belongsTo(Cuenta::class);
    }

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(CategoriaPersonal::class, 'categoria_personal_id');
    }

    public function compromiso(): BelongsTo
    {
        return $this->belongsTo(Compromiso::class);
    }

    public function transferencia(): BelongsTo
    {
        return $this->belongsTo(Transferencia::class);
    }
}
