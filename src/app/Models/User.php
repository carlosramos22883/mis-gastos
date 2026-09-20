<?php

namespace App\Models;

use App\Notifications\CustomResetPasswordNotification;
use App\Notifications\CustomVerifyEmailNotification;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password', 'google_id', 'avatar', 'moneda_preferida', 'fecha_corte_dia', 'zona_horaria', 'onboarding_completed', 'onboarding_data'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

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

    public function categoriasPersonales()
    {
        return $this->hasMany(CategoriaPersonal::class);
    }

    public function compromisos()
    {
        return $this->hasMany(Compromiso::class);
    }

    public function movimientosEfectivo()
    {
        return $this->hasMany(MovimientoEfectivo::class);
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
        ];
    }
}
