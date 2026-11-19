<?php

namespace App\Http\Requests;

use App\Models\Tienda;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Datos de una tienda del hogar (web y API). */
class TiendaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // los permisos se verifican en la ruta (can:despensa.*)
    }

    public function rules(): array
    {
        $tienda = $this->route('tienda');

        return [
            'nombre' => ['required', 'string', 'max:80', function ($attr, $valor, $falla) use ($tienda) {
                $repetida = $this->user()->tiendas()->whereRaw('LOWER(nombre) = ?', [mb_strtolower(trim($valor))])
                    ->when($tienda, fn ($q) => $q->whereKeyNot($tienda->id))->exists();
                if ($repetida) {
                    $falla('Ya tienes una tienda con ese nombre.');
                }
            }],
            'fuente' => ['nullable', Rule::in(array_keys(Tienda::FUENTES))],
            'notas' => ['nullable', 'string', 'max:1000'],
            'activo' => ['nullable', 'boolean'],
        ];
    }

    public function attributes(): array
    {
        return ['nombre' => 'nombre', 'fuente' => 'precios en línea'];
    }

    public function datos(): array
    {
        $data = $this->validated();
        $data['nombre'] = trim($data['nombre']);
        $data['fuente'] = $data['fuente'] ?? null;
        if ($this->has('activo') || $this->isMethod('post')) {
            $data['activo'] = $this->boolean('activo', true);
        }

        return $data;
    }
}
