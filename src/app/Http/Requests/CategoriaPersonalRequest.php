<?php

namespace App\Http\Requests;

use App\Services\CicloEfectivoService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Validación de categorías personales para web y API. */
class CategoriaPersonalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // los permisos se verifican en la ruta (can:categorias.*)
    }

    public function rules(): array
    {
        $categoria = $this->route('categoria');

        return [
            'nombre' => ['required', 'string', 'max:100', Rule::notIn([CicloEfectivoService::CATEGORIA_SALDO_INICIAL]),
                Rule::unique('categorias_personales')->where(fn ($q) => $q->where('hogar_id', $this->user()->hogar_id)->where('tipo', $this->input('tipo', 'egreso')))->ignore($categoria?->id)],
            'tipo' => ['required', Rule::in(['ingreso', 'egreso'])],
            'color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'activo' => ['nullable', 'boolean'],
        ];
    }

    public function attributes(): array
    {
        return ['nombre' => 'Nombre', 'tipo' => 'Tipo', 'color' => 'Color'];
    }
}
