<?php

namespace App\Http\Requests;

use App\Models\EstadoCuentaTarjeta;
use App\Models\TarjetaCredito;
use App\Services\ReporteService;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Período de un reporte (web y API): un ciclo (ciclo = cualquier fecha dentro de él; vacío = el actual)
 * o un rango libre desde/hasta de hasta 24 ciclos. Para el estado de cuenta, la tarjeta y el corte.
 */
class ReporteRequest extends FormRequest
{
    public const REPORTES = ['ejecucion', 'gastos', 'prestamos', 'estado-cuenta'];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $hogar = $this->user()->hogar_id;

        return [
            'ciclo' => ['nullable', 'date_format:Y-m-d'],
            'desde' => ['nullable', 'required_with:hasta', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'required_with:desde', 'date_format:Y-m-d', 'after_or_equal:desde'],
            'tarjeta' => ['nullable', 'integer', Rule::exists('tarjetas_credito', 'id')->where('hogar_id', $hogar)],
            'estado' => ['nullable', 'integer', Rule::exists('estados_cuenta_tarjeta', 'id')->where('tarjeta_credito_id', $this->integer('tarjeta'))],
            'formato' => ['nullable', Rule::in(['pdf', 'xlsx', 'csv'])],
        ];
    }

    public function messages(): array
    {
        return ['hasta.after_or_equal' => 'La fecha final debe ser igual o posterior a la inicial.'];
    }

    public function after(): array
    {
        return [function (Validator $v) {
            if ($this->filled(['desde', 'hasta']) && ! $v->errors()->has('hasta')
                && Carbon::parse($this->input('desde'))->diffInMonths(Carbon::parse($this->input('hasta'))) > ReporteService::MAX_CICLOS) {
                $v->errors()->add('hasta', 'El período puede abarcar como máximo '.ReporteService::MAX_CICLOS.' meses.');
            }
        }];
    }

    public function tarjeta(): ?TarjetaCredito
    {
        return $this->filled('tarjeta') ? TarjetaCredito::find($this->integer('tarjeta')) : null;
    }

    public function estadoCuenta(): ?EstadoCuentaTarjeta
    {
        return $this->filled('estado') ? EstadoCuentaTarjeta::find($this->integer('estado')) : null;
    }
}
