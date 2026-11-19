<?php

namespace App\Http\Requests;

use App\Services\DespensaService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Un cambio en lo que hay en casa de un producto (web y API). */
class DespensaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // los permisos se verifican en la ruta (can:despensa.edit)
    }

    public function rules(): array
    {
        return [
            'accion' => ['required', Rule::in(DespensaService::ACCIONES)],
            'valor' => match ($this->input('accion')) {
                'cantidad' => ['required', 'integer', 'min:0', 'max:999'],
                'por_acabarse' => ['required', 'boolean'],
                'vence' => ['nullable', 'date_format:Y-m-d'],
                default => ['prohibited'],
            },
        ];
    }

    public function messages(): array
    {
        return ['valor.date_format' => 'Escribe la fecha de vencimiento como dd/mm/aaaa.'];
    }
}
