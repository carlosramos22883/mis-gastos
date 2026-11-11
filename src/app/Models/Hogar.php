<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Grupo que comparte un solo fondo: efectivo, ciclo, categorías, compromisos, cuentas y tarjetas.
 * Todo usuario pertenece a uno (al registrarse se le crea el suyo).
 */
#[Fillable(['nombre', 'user_id', 'tope_gasto_ciclo'])]
class Hogar extends Model
{
    protected $table = 'hogares';

    protected function casts(): array
    {
        return ['tope_gasto_ciclo' => 'decimal:2'];
    }

    public function administrador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function miembros(): HasMany
    {
        return $this->hasMany(User::class)->orderBy('name');
    }

    public function escenariosProyeccion(): HasMany
    {
        return $this->hasMany(EscenarioProyeccion::class);
    }

    public function invitaciones(): HasMany
    {
        return $this->hasMany(HogarInvitacion::class);
    }

    public function esAdministrador(User $user): bool
    {
        return (int) $this->user_id === (int) $user->id;
    }
}
