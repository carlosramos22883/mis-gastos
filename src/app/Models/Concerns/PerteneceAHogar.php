<?php

namespace App\Models\Concerns;

use App\Models\Cuenta;
use App\Models\Deudor;
use App\Models\FuenteIngreso;
use App\Models\Hogar;
use App\Models\Prestamo;
use App\Models\TarjetaCredito;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Registro compartido por los miembros de un hogar. user_id indica quién lo hizo (o el titular,
 * en cuentas y tarjetas); hogar_id es lo que define quién lo ve.
 */
trait PerteneceAHogar
{
    /** Columnas que apuntan a un registro padre del que se hereda el hogar. */
    private const PADRES = [
        'tarjeta_credito_id' => TarjetaCredito::class,
        'cuenta_id' => Cuenta::class,
        'deudor_id' => Deudor::class,
        'prestamo_id' => Prestamo::class,
        'fuente_ingreso_id' => FuenteIngreso::class,
    ];

    public static function bootPerteneceAHogar(): void
    {
        static::creating(function (self $modelo) {
            $modelo->user_id ??= auth()->id();
            // Sin sesión (comandos, procesos): el hogar y el usuario salen del registro padre
            foreach (self::PADRES as $columna => $clase) {
                if (! $modelo->hogar_id && ! $modelo->user_id && $modelo->{$columna}) {
                    $padre = $clase::query()->find($modelo->{$columna}, ['hogar_id', 'user_id']);
                    $modelo->hogar_id = $padre?->hogar_id;
                    $modelo->user_id = $padre?->user_id;
                }
            }
            if (! $modelo->hogar_id && $modelo->user_id) {
                $modelo->hogar_id = User::whereKey($modelo->user_id)->value('hogar_id');
            }
            $modelo->user_id ??= Hogar::whereKey($modelo->hogar_id)->value('user_id');
        });
    }

    public function hogar(): BelongsTo
    {
        return $this->belongsTo(Hogar::class);
    }

    public function scopeDelHogar(Builder $query, User $user): void
    {
        $query->where($this->qualifyColumn('hogar_id'), $user->hogar_id);
    }

    public function esDelHogar(User $user): bool
    {
        return $this->hogar_id !== null && (int) $this->hogar_id === (int) $user->hogar_id;
    }
}
