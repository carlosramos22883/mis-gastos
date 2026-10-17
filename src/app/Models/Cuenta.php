<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Cuenta bancaria o billetera digital. Su saldo = saldo inicial + ingresos - egresos (CuentaService::saldo). */
#[Fillable(['user_id', 'banco_id', 'tipo_cuenta_id', 'nombre', 'numero_cuenta', 'color', 'saldo_inicial', 'fecha_inicial', 'activo'])]
class Cuenta extends Model
{
    protected $table = 'cuentas';

    protected $hidden = ['numero_cuenta'];

    protected function casts(): array
    {
        return [
            'numero_cuenta' => 'encrypted',
            'saldo_inicial' => 'decimal:2',
            'fecha_inicial' => 'date',
            'activo' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function banco(): BelongsTo
    {
        return $this->belongsTo(Banco::class);
    }

    public function tipoCuenta(): BelongsTo
    {
        return $this->belongsTo(TipoCuenta::class);
    }

    public function movimientos(): HasMany
    {
        return $this->hasMany(MovimientoCuenta::class);
    }

    /** Últimos 4 dígitos para mostrar sin exponer el número completo. */
    public function numeroEnmascarado(): ?string
    {
        return $this->numero_cuenta ? '•••• '.substr($this->numero_cuenta, -4) : null;
    }
}
