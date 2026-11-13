<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Cubrir a mano un presupuesto que se pasó: cuánto se le quita a cada categoría (deben sumar justo lo que se pasó). */
class CubrirPresupuestoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // los permisos se verifican en la ruta
    }

    public function rules(): array
    {
        return [
            'traspasos' => ['required', 'array', 'min:1', 'max:20'],
            'traspasos.*.presupuesto_id' => ['required', 'integer'],
            'traspasos.*.monto' => ['nullable', 'numeric', 'min:0', 'regex:/^\d+(?:\.\d{1,2})?$/'],
        ];
    }

    public function messages(): array
    {
        return ['traspasos.required' => 'Elige al menos una categoría.'];
    }
}
