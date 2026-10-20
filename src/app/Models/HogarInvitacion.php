<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['hogar_id', 'invitado_por', 'email', 'estado', 'respondida_en'])]
class HogarInvitacion extends Model
{
    public const PENDIENTE = 'pendiente';

    public const ACEPTADA = 'aceptada';

    public const RECHAZADA = 'rechazada';

    public const CANCELADA = 'cancelada';

    protected $table = 'hogar_invitaciones';

    protected $casts = ['respondida_en' => 'datetime'];

    public function hogar(): BelongsTo
    {
        return $this->belongsTo(Hogar::class);
    }

    public function invitadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invitado_por');
    }
}
