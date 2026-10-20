<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Nombre del hogar (web y API). */
class HogarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permiso en la ruta (can:hogar.edit); el servicio exige ser administrador
    }

    public function rules(): array
    {
        return ['nombre' => ['required', 'string', 'max:100']];
    }

    public function attributes(): array
    {
        return ['nombre' => 'nombre del hogar'];
    }
}
