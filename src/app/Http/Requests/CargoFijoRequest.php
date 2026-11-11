<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Cargo fijo de una tarjeta (seguro, Netflix…): un día del mes, desde una fecha y, si se quiere, hasta otra. */
class CargoFijoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // Sin día (apps anteriores), se cobra el mismo día del mes que la fecha de inicio
        if (! $this->filled('dia') && preg_match('/^\d{4}-\d{2}-(\d{2})$/', (string) $this->input('desde'), $m)) {
            $this->merge(['dia' => (int) $m[1]]);
        }
    }

    public function rules(): array
    {
        return [
            'descripcion' => ['required', 'string', 'max:100'],
            'monto' => ['required', 'numeric', 'gt:0', 'regex:/^\d+(?:\.\d{1,2})?$/'],
            'categoria_personal_id' => ['nullable', 'integer', Rule::exists('categorias_personales', 'id')->where(fn ($q) => $q
                ->where('hogar_id', $this->user()->hogar_id)->where('tipo', 'egreso')->where('activo', true))],
            'dia' => ['required', 'integer', 'between:1,31'],
            'desde' => ['required', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:desde'],
            'activo' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return ['monto.regex' => 'El monto debe tener máximo dos decimales.', 'hasta.after_or_equal' => 'La fecha de fin no puede ser antes de la de inicio.'];
    }

    public function datos(): array
    {
        return $this->validated() + ['activo' => $this->boolean('activo', ! $this->route('cargo'))];
    }
}
