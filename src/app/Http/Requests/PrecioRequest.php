<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Un precio anotado a mano (visto en la tienda o en el ticket). */
class PrecioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // los permisos se verifican en la ruta (can:despensa.edit)
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['precio' => trim(str_replace(['$', ','], ['', '.'], (string) $this->input('precio')))]);
    }

    public function rules(): array
    {
        return [
            'tienda_id' => ['required', 'integer'],
            'precio' => ['required', 'numeric', 'gt:0', 'max:99999', 'regex:/^\d+(?:\.\d{1,2})?$/'],
            'unidades' => ['nullable', 'integer', 'min:1', 'max:999'],
            'fecha' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:tomorrow'],
        ];
    }

    public function messages(): array
    {
        return ['precio.regex' => 'El precio lleva hasta 2 decimales.'];
    }

    public function attributes(): array
    {
        return ['tienda_id' => 'tienda', 'unidades' => 'unidades del paquete'];
    }
}
