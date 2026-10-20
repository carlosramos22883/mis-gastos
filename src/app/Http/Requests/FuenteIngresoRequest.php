<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidaMiembroHogar;
use App\Models\FuenteIngreso;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

/** Fuente de ingreso con su plantilla de conceptos (web y API). user_id = quién lo recibe. */
class FuenteIngresoRequest extends FormRequest
{
    use ValidaMiembroHogar;

    public function authorize(): bool
    {
        return true; // los permisos se verifican en la ruta (can:ingresos.*)
    }

    public function rules(): array
    {
        $dinero = ['numeric', 'min:0', 'regex:/^\d+(?:\.\d{1,2})?$/'];

        return [
            'user_id' => $this->reglaMiembro(),
            'nombre' => ['required', 'string', 'max:100'],
            'tipo' => ['required', Rule::in(array_keys(FuenteIngreso::TIPOS))],
            'pagador' => ['nullable', 'string', 'max:100'],
            'frecuencia' => ['required', Rule::in(['mensual', 'quincenal'])],
            'dia_pago' => ['nullable', 'integer', 'between:1,31'],
            'dia_pago_2' => ['nullable', 'required_if:frecuencia,quincenal', 'integer', 'between:1,31'],
            'monto_bruto' => ['nullable', ...$dinero],
            'destino' => ['required', 'string', 'regex:/^(efectivo|cuenta:\d+)$/'],
            'color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'activo' => ['nullable', 'boolean'],
            'conceptos' => ['nullable', 'array', 'max:20'],
            'conceptos.*.nombre' => ['nullable', 'string', 'max:100'],
            'conceptos.*.tipo' => ['nullable', Rule::in(['descuento', 'adicion'])],
            'conceptos.*.monto' => ['nullable', ...$dinero],
            'conceptos.*.porcentaje' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'conceptos.*.tope' => ['nullable', ...$dinero],
            'conceptos.*.prestamo_id' => ['nullable', 'integer', Rule::exists('prestamos', 'id')->where('hogar_id', $this->user()->hogar_id)],
        ];
    }

    public function messages(): array
    {
        return ['regex' => 'El monto debe tener máximo dos decimales.', 'destino.regex' => 'Elige a dónde entra el dinero.'];
    }

    public function datos(): array
    {
        $data = Arr::except($this->validated(), ['conceptos']);
        if ($this->has('activo') || $this->isMethod('post')) {
            $data['activo'] = $this->boolean('activo', true);
        }

        return $data;
    }

    public function conceptos(): ?array
    {
        return $this->has('conceptos') ? array_values($this->validated('conceptos') ?? []) : null;
    }
}
