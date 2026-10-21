<?php

namespace App\Http\Requests;

use App\Models\Contacto;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

/** Datos de un contacto (web y API). La foto es opcional (máx. 2 MB); "quitar_foto" la elimina. */
class ContactoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // los permisos se verifican en la ruta (can:contactos.*)
    }

    public function rules(): array
    {
        $contacto = $this->route('contacto');

        return [
            'nombre' => ['required', 'string', 'max:100', function ($attr, $valor, $falla) use ($contacto) {
                $repetido = $this->user()->contactos()->whereRaw('LOWER(nombre) = ?', [mb_strtolower(trim($valor))])
                    ->whereRaw("LOWER(COALESCE(apellido, '')) = ?", [mb_strtolower(trim((string) $this->input('apellido')))])
                    ->when($contacto, fn ($q) => $q->whereKeyNot($contacto->id))->exists();
                if ($repetido) {
                    $falla('Ya tienes un contacto con ese nombre.');
                }
            }],
            'apellido' => ['nullable', 'string', 'max:100'],
            'tipo_contacto_id' => ['required', 'integer', Rule::exists('tipos_contacto', 'id')->where('activo', true)],
            'telefono' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:150'],
            'notas' => ['nullable', 'string', 'max:1000'],
            'activo' => ['nullable', 'boolean'],
            'foto' => ['nullable', 'image', 'max:2048'],
            'quitar_foto' => ['nullable', 'boolean'],
        ];
    }

    public function attributes(): array
    {
        return ['nombre' => 'nombre', 'apellido' => 'apellido', 'telefono' => 'teléfono', 'email' => 'correo', 'foto' => 'foto'];
    }

    public function datos(): array
    {
        $data = Arr::except($this->validated(), ['foto', 'quitar_foto']);
        if ($this->has('activo') || $this->isMethod('post')) {
            $data['activo'] = $this->boolean('activo', true);
        }

        return $data;
    }
}
