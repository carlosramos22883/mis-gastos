<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizaFechas;
use App\Http\Requests\Concerns\ResuelveContacto;
use App\Http\Requests\Concerns\ValidaMiembroHogar;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Compra (al contado o en cuotas a tasa cero), cargo o crédito de una tarjeta. */
class MovimientoTarjetaRequest extends FormRequest
{
    use NormalizaFechas, ResuelveContacto, ValidaMiembroHogar;

    public function authorize(): bool
    {
        return true; // los permisos se verifican en la ruta (can:tarjetas.*)
    }

    protected function prepareForValidation(): void
    {
        $this->normalizarFechas('fecha');
        if ($this->input('tipo') !== 'compra' || (int) $this->input('cuotas') <= 1) {
            $this->merge(['cuotas' => null]);
        }
        if (! $this->filled('cuotas')) {
            $this->merge(['dia_cuota' => null]);
        }
        $this->merge(['confirmar' => $this->boolean('confirmar')]);
    }

    public function rules(): array
    {
        $user = $this->user();

        return [
            'user_id' => $this->reglaMiembro(),
            'contacto_id' => ['required_if_accepted:me_lo_debe', ...$this->reglaContacto()],
            'me_lo_debe' => ['sometimes', 'boolean'], // la compra la hizo el contacto: queda como deuda suya
            'tipo' => ['required', Rule::in(['compra', 'cargo', 'credito'])],
            'descripcion' => ['required', 'string', 'max:255'],
            'monto' => ['required', 'numeric', 'gt:0', 'regex:/^\d+(?:\.\d{1,2})?$/'],
            'fecha' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.Carbon::now($user->zona_horaria ?: config('app.timezone'))->toDateString()],
            'cuotas' => ['nullable', 'integer', 'between:2,60'],
            'dia_cuota' => ['nullable', 'integer', 'between:1,31'], // sin él (apps anteriores), una cuota por corte
            'categoria_personal_id' => ['nullable', 'integer', Rule::exists('categorias_personales', 'id')->where(fn ($q) => $q
                ->where('hogar_id', $user->hogar_id)->where('activo', true)->where('tipo', 'egreso'))],
            'comentario' => ['nullable', 'string', 'max:1000'],
            'confirmar' => ['boolean'], // true = acepta sobregirar la tarjeta
        ];
    }

    public function messages(): array
    {
        return [
            'monto.regex' => 'El monto debe tener máximo dos decimales y ser positivo.',
            'fecha.before_or_equal' => 'La fecha no puede ser futura.',
            'contacto_id.required_if_accepted' => 'Elige quién te debe la compra.',
        ];
    }
}
