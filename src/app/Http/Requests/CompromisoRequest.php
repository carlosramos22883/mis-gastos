<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

/**
 * Validación de compromisos para web y API. Al crear, "recurrencia_tipo = recurrente"
 * crea una regla que se repite; editar siempre modifica un solo pago.
 */
class CompromisoRequest extends FormRequest
{
    private const COMUNES = ['descripcion', 'comentario', 'monto', 'medio_pago', 'categoria_personal_id'];

    public function authorize(): bool
    {
        return true; // los permisos se verifican en la ruta (can:compromisos.*)
    }

    public function esRecurrente(): bool
    {
        return $this->isMethod('post') && $this->input('recurrencia_tipo') === 'recurrente';
    }

    public function rules(): array
    {
        $rules = [
            'descripcion' => ['required', 'string', 'max:255'],
            'comentario' => ['nullable', 'string', 'max:1000'],
            'monto' => ['required', 'numeric', 'gt:0', 'regex:/^\d+(?:\.\d{1,2})?$/'],
            'medio_pago' => ['required', Rule::in(['efectivo', 'tarjeta', 'transferencia', 'por_definir'])],
            'categoria_personal_id' => ['nullable', 'integer', Rule::exists('categorias_personales', 'id')->where(fn ($q) => $q
                ->where('user_id', $this->user()->id)->where('tipo', 'egreso')->where('activo', true))],
            'recurrencia_tipo' => ['nullable', Rule::in(['unica', 'recurrente'])],
            'fecha_esperada' => ['nullable', 'date'],
        ];

        if ($this->esRecurrente()) {
            $rules += [
                'frecuencia' => ['required', Rule::in(['semanal', 'quincenal', 'mensual'])],
                'dia_semana' => ['nullable', 'required_if:frecuencia,semanal', 'integer', 'between:0,6'],
                'dia_pago' => ['nullable', 'required_unless:frecuencia,semanal', 'integer', 'between:1,31'],
                'dia_pago_2' => ['nullable', 'required_if:frecuencia,quincenal', 'integer', 'between:1,31', 'different:dia_pago'],
                'fecha_inicio' => ['required', 'date'],
                'finalizacion' => ['required', Rule::in(['indefinido', 'fecha', 'cuotas'])],
                'fecha_fin' => ['nullable', 'required_if:finalizacion,fecha', 'date', 'after_or_equal:fecha_inicio'],
                'total_cuotas' => ['nullable', 'required_if:finalizacion,cuotas', 'integer', 'min:1', 'max:600'],
            ];
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'monto.regex' => 'El monto debe tener máximo dos decimales y ser positivo.',
            'dia_pago_2.different' => 'Los dos días de pago deben ser distintos.',
        ];
    }

    /** Datos de un pago (compromiso de una sola vez, o edición de un pago). */
    public function datosPago(): array
    {
        return Arr::only($this->validated(), [...self::COMUNES, 'fecha_esperada']);
    }

    /** Datos de la regla recurrente, sin los campos que no aplican a su frecuencia o finalización. */
    public function datosRecurrente(): array
    {
        $data = $this->validated();
        $frecuencia = $data['frecuencia'];

        return Arr::only($data, self::COMUNES) + [
            'frecuencia' => $frecuencia,
            'dia_semana' => $frecuencia === 'semanal' ? $data['dia_semana'] : null,
            'dia_pago' => $frecuencia === 'semanal' ? null : $data['dia_pago'],
            'dia_pago_2' => $frecuencia === 'quincenal' ? $data['dia_pago_2'] : null,
            'fecha_inicio' => $data['fecha_inicio'],
            'fecha_fin' => $data['finalizacion'] === 'fecha' ? $data['fecha_fin'] : null,
            'total_cuotas' => $data['finalizacion'] === 'cuotas' ? $data['total_cuotas'] : null,
        ];
    }
}
