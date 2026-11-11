<?php

namespace App\Models;

use App\Notifications\CustomResetPasswordNotification;
use App\Notifications\CustomVerifyEmailNotification;
use App\Services\HogarService;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password', 'google_id', 'avatar', 'moneda_preferida', 'fecha_corte_dia', 'zona_horaria', 'onboarding_completed', 'onboarding_data'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    protected static function booted(): void
    {
        // Todo usuario pertenece a un hogar: al registrarse se le crea el suyo.
        static::created(fn (User $user) => $user->hogar_id ?: app(HogarService::class)->crearPara($user));

        // El ciclo es del hogar: el corte y la zona horaria se mantienen iguales en todos sus miembros.
        static::updated(function (User $user) {
            if ($user->hogar_id && $user->wasChanged(['fecha_corte_dia', 'zona_horaria'])) {
                User::where('hogar_id', $user->hogar_id)->whereKeyNot($user->id)
                    ->update($user->only(['fecha_corte_dia', 'zona_horaria']));
            }
        });

        static::deleting(fn (User $user) => app(HogarService::class)->antesDeEliminarUsuario($user));
    }

    /**
     * Send the password reset notification.
     * Sobrescribe la notificación por defecto de Laravel
     */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new CustomResetPasswordNotification($token));
    }

    /**
     * Send the email verification notification.
     */
    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new CustomVerifyEmailNotification);
    }

    /**
     * Relación con la moneda preferida del usuario
     */
    public function monedaPreferida()
    {
        return $this->belongsTo(Moneda::class, 'moneda_preferida');
    }

    public function hogar(): BelongsTo
    {
        return $this->belongsTo(Hogar::class);
    }

    /** Miembros del hogar (incluye al propio usuario). */
    public function miembrosHogar(): HasMany
    {
        return $this->hasMany(User::class, 'hogar_id', 'hogar_id')->orderBy('name');
    }

    // Los registros son del hogar: cada relación devuelve los de todos sus miembros.

    public function categoriasPersonales(): HasMany
    {
        return $this->hasMany(CategoriaPersonal::class, 'hogar_id', 'hogar_id');
    }

    public function compromisos(): HasMany
    {
        return $this->hasMany(Compromiso::class, 'hogar_id', 'hogar_id');
    }

    public function compromisosRecurrentes(): HasMany
    {
        return $this->hasMany(CompromisoRecurrente::class, 'hogar_id', 'hogar_id');
    }

    public function cuentas(): HasMany
    {
        return $this->hasMany(Cuenta::class, 'hogar_id', 'hogar_id');
    }

    public function movimientosCuenta(): HasMany
    {
        return $this->hasMany(MovimientoCuenta::class, 'hogar_id', 'hogar_id');
    }

    public function transferencias(): HasMany
    {
        return $this->hasMany(Transferencia::class, 'hogar_id', 'hogar_id');
    }

    public function tarjetasCredito(): HasMany
    {
        return $this->hasMany(TarjetaCredito::class, 'hogar_id', 'hogar_id');
    }

    public function contactos(): HasMany
    {
        return $this->hasMany(Contacto::class, 'hogar_id', 'hogar_id');
    }

    public function fuentesIngreso(): HasMany
    {
        return $this->hasMany(FuenteIngreso::class, 'hogar_id', 'hogar_id');
    }

    public function prestamos(): HasMany
    {
        return $this->hasMany(Prestamo::class, 'hogar_id', 'hogar_id');
    }

    public function eventos(): HasMany
    {
        return $this->hasMany(Evento::class, 'hogar_id', 'hogar_id');
    }

    public function movimientosEfectivo(): HasMany
    {
        return $this->hasMany(MovimientoEfectivo::class, 'hogar_id', 'hogar_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'onboarding_data' => 'array',
            'avisos_vistos' => 'array',
        ];
    }
}
