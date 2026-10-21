<?php

namespace App\Models;

use App\Models\Catalogos\TipoIngreso;
use App\Models\Concerns\PerteneceAHogar;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Fuente de ingreso (sueldo, servicios profesionales…) con su plantilla de descuentos y adiciones. */
#[Fillable(['user_id', 'nombre', 'tipo_ingreso_id', 'pagador', 'frecuencia', 'dia_pago', 'dia_pago_2', 'monto_bruto', 'destino', 'color', 'activo'])]
class FuenteIngreso extends Model
{
    use PerteneceAHogar;

    protected $table = 'fuentes_ingreso';

    protected $casts = ['monto_bruto' => 'decimal:2', 'dia_pago' => 'integer', 'dia_pago_2' => 'integer', 'activo' => 'boolean'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function conceptos(): HasMany
    {
        return $this->hasMany(FuenteIngresoConcepto::class)->orderBy('orden')->orderBy('id');
    }

    public function ingresos(): HasMany
    {
        return $this->hasMany(Ingreso::class); // sin orden: se usa para sumar
    }

    /** Tipo (catálogo que mantiene el administrador). */
    public function tipoIngreso(): BelongsTo
    {
        return $this->belongsTo(TipoIngreso::class, 'tipo_ingreso_id');
    }
}
