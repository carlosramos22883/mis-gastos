<?php

namespace App\Http\Requests;

use Illuminate\Support\Arr;

/**
 * Editar la regla de una serie de compromisos (monto, descripción, calendario, fin…).
 * Mismas reglas que al crearla, sin la fecha de inicio (la serie ya empezó).
 */
class SerieCompromisoRequest extends CompromisoRequest
{
    public function esRecurrente(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = Arr::except(parent::rules(), ['recurrencia_tipo', 'fecha_esperada', 'fecha_inicio']);
        $rules['fecha_fin'] = ['nullable', 'required_if:finalizacion,fecha', 'date'];

        return $rules;
    }

    /** Datos de la regla, sin los campos que no aplican a su frecuencia o finalización. */
    public function datosSerie(): array
    {
        return Arr::except($this->datosRecurrente(), ['fecha_inicio']);
    }

    public function datosRecurrente(): array
    {
        $data = $this->validated() + ['fecha_inicio' => null];
        $frecuencia = $data['frecuencia'];

        // Si no viene (apps anteriores) no se cambia
        return Arr::only($data, ['user_id', 'contacto_id', 'descripcion', 'comentario', 'monto', 'medio_pago', 'categoria_personal_id', 'es_presupuesto']) + [
            'frecuencia' => $frecuencia,
            'dia_semana' => $frecuencia === 'semanal' ? $data['dia_semana'] : null,
            'dia_pago' => $frecuencia === 'semanal' ? null : $data['dia_pago'],
            'dia_pago_2' => $frecuencia === 'quincenal' ? $data['dia_pago_2'] : null,
            'fecha_fin' => $data['finalizacion'] === 'fecha' ? $data['fecha_fin'] : null,
            'total_cuotas' => $data['finalizacion'] === 'cuotas' ? $data['total_cuotas'] : null,
        ];
    }

    public function messages(): array
    {
        return parent::messages() + ['fecha_fin.required_if' => 'Indica hasta qué fecha se repite.'];
    }
}
