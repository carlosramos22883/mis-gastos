<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ResuelveContacto;
use Illuminate\Foundation\Http\FormRequest;

/** "Me lo debe": liga un gasto ya registrado (efectivo, cuenta o tarjeta) a una deuda nueva de un contacto. */
class DeudaVinculoRequest extends FormRequest
{
    use ResuelveContacto;

    public function authorize(): bool
    {
        return true; // los permisos se verifican en la ruta (can:deudores.create)
    }

    public function rules(): array
    {
        return [
            'contacto_id' => ['required', ...$this->reglaContacto()],
            'descripcion' => ['required', 'string', 'max:200'],
            'monto' => ['required', 'numeric', 'gt:0', 'regex:/^\d+(?:\.\d{1,2})?$/'],
            'comentario' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'contacto_id.required' => 'Elige quién te debe.',
            'monto.regex' => 'El monto debe tener máximo dos decimales y ser positivo.',
        ];
    }
}
