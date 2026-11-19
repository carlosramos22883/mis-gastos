<?php

namespace App\Http\Requests;

use App\Models\Producto;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Datos de un producto de la despensa (web y API). La foto es opcional (máx. 4 MB); "quitar_foto" la elimina.
 * No se repite en el hogar el mismo nombre + marca + presentación, ni el mismo código de barras.
 */
class ProductoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // los permisos se verifican en la ruta (can:despensa.*)
    }

    protected function prepareForValidation(): void
    {
        $limpio = fn ($v) => ($v = trim((string) $v)) === '' ? null : $v;
        $this->merge([
            'marca' => $limpio($this->input('marca')),
            'codigo_barras' => $limpio(preg_replace('/\s+/', '', (string) $this->input('codigo_barras'))),
            'cantidad' => $limpio(str_replace(',', '.', (string) $this->input('cantidad'))),
            'unidad' => $limpio($this->input('unidad')),
        ]);
    }

    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:100'],
            'marca' => ['nullable', 'string', 'max:80'],
            'cantidad' => ['nullable', 'required_with:unidad', 'numeric', 'gt:0', 'max:999999'],
            'unidad' => ['nullable', 'required_with:cantidad', Rule::in(array_keys(Producto::UNIDADES))],
            'codigo_barras' => ['nullable', 'regex:/^\d{8,14}$/'],
            'categoria_despensa_id' => ['required', 'integer', Rule::exists('categorias_despensa', 'id')->where('activo', true)],
            'importancia' => ['required', Rule::in(array_keys(Producto::IMPORTANCIAS))],
            'duracion' => ['required', Rule::in(array_keys(Producto::DURACIONES))],
            'acepta_otra_marca' => ['nullable', 'boolean'],
            'cantidad_ideal' => ['nullable', 'integer', 'min:1', 'max:999'],
            'avisar_cuando' => ['nullable', 'integer', 'min:0', 'max:999', 'lt:cantidad_ideal'],
            'en_casa' => ['nullable', 'integer', 'min:0', 'max:999'],
            'vence_el' => ['nullable', 'date_format:Y-m-d'],
            'notas' => ['nullable', 'string', 'max:1000'],
            'activo' => ['nullable', 'boolean'],
            'foto' => ['nullable', 'image', 'max:4096'],
            'quitar_foto' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'codigo_barras.regex' => 'El código de barras lleva de 8 a 14 números.',
            'avisar_cuando.lt' => 'El aviso tiene que ser menor que la cantidad ideal.',
            'cantidad.required_with' => 'Escribe la cantidad de la presentación.',
            'unidad.required_with' => 'Elige la unidad de la presentación.',
        ];
    }

    public function attributes(): array
    {
        return ['nombre' => 'nombre', 'marca' => 'marca', 'cantidad' => 'cantidad', 'unidad' => 'unidad', 'codigo_barras' => 'código de barras',
            'categoria_despensa_id' => 'categoría', 'cantidad_ideal' => 'cantidad ideal', 'en_casa' => 'cuántos hay en casa', 'vence_el' => 'vence el', 'avisar_cuando' => 'avisar cuando queden', 'foto' => 'foto'];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $producto = $this->route('producto');
            $otros = fn () => $this->user()->productos()->when($producto, fn ($q) => $q->whereKeyNot($producto->id));

            if ($this->input('codigo_barras') && $otros()->where('codigo_barras', $this->input('codigo_barras'))->exists()) {
                $validator->errors()->add('codigo_barras', 'Ya tienes un producto con ese código de barras.');
            }
            $repetido = $otros()->whereRaw('LOWER(nombre) = ?', [mb_strtolower(trim($this->input('nombre')))])
                ->whereRaw("LOWER(COALESCE(marca, '')) = ?", [mb_strtolower((string) $this->input('marca'))])
                ->where(fn ($q) => $this->input('cantidad') === null ? $q->whereNull('cantidad') : $q->where('cantidad', $this->input('cantidad')))
                ->where(fn ($q) => $this->input('unidad') === null ? $q->whereNull('unidad') : $q->where('unidad', $this->input('unidad')))
                ->exists();
            if ($repetido) {
                $validator->errors()->add('nombre', 'Ya tienes ese producto con la misma marca y presentación.');
            }
        }];
    }

    public function datos(): array
    {
        $data = Arr::except($this->validated(), ['foto', 'quitar_foto']);
        $data['nombre'] = trim($data['nombre']);
        $data['acepta_otra_marca'] = $this->boolean('acepta_otra_marca');
        $data['en_casa'] = isset($data['en_casa']) ? (int) $data['en_casa'] : null;
        // Solo los perecederos con algo en casa llevan vencimiento
        if ($data['duracion'] !== 'perecedero' || $data['en_casa'] === 0) {
            $data['vence_el'] = null;
        }
        if ($this->has('activo') || $this->isMethod('post')) {
            $data['activo'] = $this->boolean('activo', true);
        }

        return $data;
    }
}
