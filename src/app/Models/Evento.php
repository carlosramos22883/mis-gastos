<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAHogar;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Una comida o celebración en la que se divide la cuenta, como una tabla: participantes × productos (consumos) = cantidades.
 * Propina: ninguna, porcentaje del consumo de cada uno o un monto fijo dividido entre todos. "foto" = foto de la cuenta.
 * Si pagó el hogar, "origen" dice de dónde salió (efectivo, cuenta, tarjeta o solo anotado) y el movimiento queda ligado.
 */
#[Fillable(['user_id', 'nombre', 'fecha', 'lugar', 'comentario', 'foto', 'propina_tipo', 'propina_valor', 'origen', 'categoria_personal_id', 'movimiento_efectivo_id', 'movimiento_cuenta_id', 'movimiento_tarjeta_id'])]
class Evento extends Model
{
    use PerteneceAHogar;

    public const PROPINAS = ['ninguna' => 'Sin propina', 'porcentaje' => 'Porcentaje', 'monto' => 'Monto fijo'];

    protected function casts(): array
    {
        return ['fecha' => 'date', 'propina_valor' => 'decimal:2'];
    }

    public function participantes(): HasMany
    {
        return $this->hasMany(EventoParticipante::class)->orderBy('orden')->orderBy('id');
    }

    public function consumos(): HasMany
    {
        return $this->hasMany(EventoConsumo::class)->orderBy('orden')->orderBy('id');
    }

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(CategoriaPersonal::class, 'categoria_personal_id');
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

    /** El movimiento con que el hogar pagó la cuenta (si lo hay). */
    public function movimiento(): ?Model
    {
        return $this->movimientoEfectivo ?? $this->movimientoCuenta ?? $this->movimientoTarjeta;
    }

    public function pagador(): ?EventoParticipante
    {
        return $this->participantes->firstWhere('es_pagador', true);
    }

    /** Parámetro para la URL de la foto: cambia al reemplazarla (así no se ve la anterior desde la caché). */
    public function versionFoto(): ?string
    {
        return $this->foto ? substr(md5($this->foto), 0, 8) : null;
    }

    /** Pagó un miembro del hogar (entonces los contactos nos deben su parte). */
    public function pagoElHogar(): bool
    {
        return (bool) $this->pagador()?->user_id;
    }
}
