<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Invitación a un hogar (web y API). */
class HogarInvitacionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permiso en la ruta (can:hogar.edit); el servicio exige ser administrador
    }

    public function rules(): array
    {
        return ['email' => ['required', 'email', 'max:255']];
    }

    public function attributes(): array
    {
        return ['email' => 'correo electrónico'];
    }
}
