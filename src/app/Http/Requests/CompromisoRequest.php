<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ResuelveContacto;
use App\Http\Requests\Concerns\ValidaMiembroHogar;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

/**
 * Validación de compromisos para web y API. Al crear, "recurrencia_tipo = recurrente"
 * crea una regla que se repite; editar siempre modifica un solo pago.
 */
class CompromisoRequest extends FormRequest
{
    use ResuelveContacto, ValidaMiembroHogar;

    private const COMUNES = ['user_id', 'contacto_id', 'descripcion', 'comentario', 'monto', 'medio_pago', 'categoria_personal_id'];

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
            'user_id' => $this->reglaMiembro(),
            'contacto_id' => $this->reglaContacto(),
            'descripcion' => ['required', 'string', 'max:255'],
            'comentario' => ['nullable', 'string', 'max:1000'],
            'monto' => ['required', 'numeric', 'gt:0', 'regex:/^\d+(?:\.\d{1,2})?$/'],
            'medio_pago' => ['required', Rule::in(['efectivo', 'tarjeta', 'transferencia', 'por_definir'])],
            // Presupuesto (súper, gasolina…): no se paga, se descuenta con los egresos de su categoría
            'es_presupuesto' => ['sometimes', 'boolean'],
            'categoria_personal_id' => ['nullable', 'required_if_accepted:es_presupuesto', 'integer', Rule::exists('categorias_personales', 'id')->where(fn ($q) => $q
                ->where('hogar_id', $this->user()->hogar_id)->where('tipo', 'egreso')->where('activo', true))],
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
            'categoria_personal_id.required_if_accepted' => 'Un presupuesto necesita la categoría de los gastos que lo van descontando.',
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
            'es_presupuesto' => (bool) ($data['es_presupuesto'] ?? false),
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
