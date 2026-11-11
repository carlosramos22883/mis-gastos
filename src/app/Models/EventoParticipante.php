<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Quien estuvo en un evento: un miembro del hogar (user_id) o un contacto. Un festejado no paga: lo suyo se reparte.
 * Si pagó el hogar, lo de un contacto es una deuda (deuda_id). Si pagó otra persona, fecha_pago marca que ya se le pagó
 * (y un miembro del hogar guarda el movimiento con que lo hizo).
 */
#[Fillable(['user_id', 'contacto_id', 'orden', 'es_festejado', 'es_pagador', 'deuda_id', 'fecha_pago', 'movimiento_efectivo_id', 'movimiento_cuenta_id', 'movimiento_tarjeta_id'])]
class EventoParticipante extends Model
{
    protected $table = 'evento_participantes';

    protected function casts(): array
    {
        return ['es_festejado' => 'boolean', 'es_pagador' => 'boolean', 'fecha_pago' => 'date'];
    }

    public function evento(): BelongsTo
    {
        return $this->belongsTo(Evento::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function contacto(): BelongsTo
    {
        return $this->belongsTo(Contacto::class);
    }

    public function deuda(): BelongsTo
    {
        return $this->belongsTo(Deuda::class);
    }

    public function consumos(): BelongsToMany
    {
        return $this->belongsToMany(EventoConsumo::class, 'evento_consumo_participante')->withPivot('cantidad');
    }

    public function movimientoEfectivo(): BelongsTo
    {
        return $this->belongsTo(MovimientoEfectivo::class);
    }

    public function movimientoCuenta(): BelongsTo
    {
        return $this->belongsTo(MovimientoCuenta::class);
    }

    public function movimientoTarjeta(): BelongsTo
    {
        return $this->belongsTo(MovimientoTarjeta::class);
    }

    public function movimiento(): ?Model
    {
        return $this->movimientoEfectivo ?? $this->movimientoCuenta ?? $this->movimientoTarjeta;
    }

    public function nombre(): string
    {
        return $this->user_id ? ($this->user?->name ?? 'Miembro del hogar') : ($this->contacto?->nombreCompleto() ?? 'Contacto');
    }

    /** Valor del selector del formulario: "miembro:{id}" o el id del contacto. */
    public function quien(): string
    {
        return $this->user_id ? 'miembro:'.$this->user_id : (string) $this->contacto_id;
    }
}
