<?php

namespace App\Models;

use App\Models\Catalogos\TipoContacto;
use App\Models\Concerns\PerteneceAHogar;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Persona, tienda, empresa o institución con la que el hogar tiene algo: le debe, nos debe,
 * se le paga un compromiso o aparece en un movimiento. La foto se guarda en el disco privado.
 */
#[Fillable(['user_id', 'nombre', 'apellido', 'tipo_contacto_id', 'telefono', 'email', 'foto', 'notas', 'activo'])]
class Contacto extends Model
{
    use PerteneceAHogar;

    protected $table = 'contactos';

    protected $casts = ['activo' => 'boolean'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function deudas(): HasMany
    {
        return $this->hasMany(Deuda::class); // sin orden: se usa para sumar
    }

    public function prestamos(): HasMany
    {
        return $this->hasMany(Prestamo::class);
    }

    public function compromisos(): HasMany
    {
        return $this->hasMany(Compromiso::class);
    }

    public function nombreCompleto(): string
    {
        return trim($this->nombre.' '.$this->apellido);
    }

    public function iniciales(): string
    {
        return mb_strtoupper(mb_substr($this->nombre, 0, 1).mb_substr((string) $this->apellido, 0, 1));
    }

    /** URL de la foto (servida solo a miembros del hogar; $api: la ruta con token para los móviles), o null. */
    public function urlFoto(bool $api = false): ?string
    {
        return $this->foto ? route($api ? 'api.contactos.foto' : 'contactos.foto', ['contacto' => $this->id, 'v' => substr(md5($this->foto), 0, 8)]) : null;
    }

    /** Tipo (catálogo que mantiene el administrador). */
    public function tipoContacto(): BelongsTo
    {
        return $this->belongsTo(TipoContacto::class, 'tipo_contacto_id');
    }
}
