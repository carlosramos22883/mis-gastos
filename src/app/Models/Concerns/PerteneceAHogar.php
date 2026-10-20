<?php

namespace App\Models\Concerns;

use App\Models\Hogar;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Registro compartido por los miembros de un hogar. user_id indica quién lo hizo (o el titular,
 * en cuentas y tarjetas); hogar_id es lo que define quién lo ve.
 */
trait PerteneceAHogar
{
    public static function bootPerteneceAHogar(): void
    {
        static::creating(function (self $modelo) {
            $modelo->user_id ??= auth()->id();
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
