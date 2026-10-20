<?php

namespace App\Http\Requests;

use App\Models\Deudor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Datos de un deudor (web y API). */
class DeudorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // los permisos se verifican en la ruta (can:deudores.*)
    }

    public function rules(): array
    {
        $deudor = $this->route('deudor');

        return [
            'nombre' => ['required', 'string', 'max:100', Rule::unique('deudores')->where('hogar_id', $this->user()->hogar_id)->ignore($deudor?->id)],
            'tipo' => ['required', Rule::in(array_keys(Deudor::TIPOS))],
            'contacto' => ['nullable', 'string', 'max:150'],
            'notas' => ['nullable', 'string', 'max:1000'],
            'activo' => ['nullable', 'boolean'],
        ];
    }

    public function attributes(): array
    {
        return ['nombre' => 'nombre', 'tipo' => 'tipo', 'contacto' => 'contacto', 'notas' => 'notas'];
    }

    public function datos(): array
    {
        return $this->validated() + ['activo' => $this->boolean('activo', ! $this->route('deudor'))];
    }
}
