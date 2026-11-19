<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Agregar algo a la lista (POST: un producto del catálogo o algo anotado) o cambiar una cosa de la lista
 * (PATCH: cantidad o «en el carrito»). Web y API.
 */
class ListaCompraItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // los permisos se verifican en la ruta (can:despensa.*)
    }

    public function rules(): array
    {
        if ($this->isMethod('patch')) {
            return [
                'cantidad' => ['sometimes', 'integer', 'min:1', 'max:999'],
                'comprado' => ['sometimes', 'boolean'],
                'no_habia' => ['sometimes', 'boolean'],
                'precio' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:99999', 'regex:/^\d+(?:\.\d{1,2})?$/'],
            ];
        }

        return [
            'producto_id' => ['nullable', 'integer', 'required_without:descripcion'],
            'descripcion' => ['nullable', 'string', 'max:200', 'required_without:producto_id'],
            'cantidad' => ['nullable', 'integer', 'min:1', 'max:999'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('precio')) {
            $precio = trim(str_replace(['$', ','], ['', '.'], (string) $this->input('precio')));
            $this->merge(['precio' => $precio === '' ? null : $precio]);
        }
    }

    public function messages(): array
    {
        return [
            'precio.regex' => 'El precio lleva hasta 2 decimales.',
            'producto_id.required_without' => 'Elige un producto o escribe lo que quieres anotar.',
            'descripcion.required_without' => 'Elige un producto o escribe lo que quieres anotar.',
        ];
    }

    public function attributes(): array
    {
        return ['producto_id' => 'producto', 'descripcion' => 'lo que quieres anotar', 'cantidad' => 'cantidad'];
    }

    public function datos(): array
    {
        $data = $this->validated();
        foreach (['comprado', 'no_habia'] as $campo) {
            if (array_key_exists($campo, $data)) {
                $data[$campo] = $this->boolean($campo);
            }
        }

        return $data;
    }
}
