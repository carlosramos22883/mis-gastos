<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Cargo fijo de una tarjeta (seguro, membresía…). */
class CargoFijoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'descripcion' => ['required', 'string', 'max:100'],
            'monto' => ['required', 'numeric', 'gt:0', 'regex:/^\d+(?:\.\d{1,2})?$/'],
            'categoria_personal_id' => ['nullable', 'integer', Rule::exists('categorias_personales', 'id')->where(fn ($q) => $q
                ->where('hogar_id', $this->user()->hogar_id)->where('tipo', 'egreso')->where('activo', true))],
            'desde' => ['required', 'date_format:Y-m-d'],
            'activo' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return ['monto.regex' => 'El monto debe tener máximo dos decimales.'];
    }

    public function datos(): array
    {
        return $this->validated() + ['activo' => $this->boolean('activo', ! $this->route('cargo'))];
    }
}
