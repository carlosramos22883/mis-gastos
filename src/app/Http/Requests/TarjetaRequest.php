<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizaFechas;
use App\Http\Requests\Concerns\ValidaMiembroHogar;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TarjetaRequest extends FormRequest
{
    use NormalizaFechas, ValidaMiembroHogar;

    private const MONTO = 'regex:/^\d+(?:\.\d{1,2})?$/';

    public function authorize(): bool
    {
        return true; // los permisos se verifican en la ruta (can:tarjetas.*)
    }

    protected function prepareForValidation(): void
    {
        $this->normalizarFechas('pendiente_fecha');
        $this->merge(['activo' => $this->boolean('activo', ! $this->isMethod('put')),
            'permite_avances' => $this->boolean('permite_avances', ! $this->isMethod('put'))]);
        // Al editar, si no viene (apps anteriores) se deja como está
        if ($this->has('cargos_restan_disponible') || ! $this->isMethod('put')) {
            $this->merge(['cargos_restan_disponible' => $this->boolean('cargos_restan_disponible', true)]);
        }
    }

    public function rules(): array
    {
        $creando = $this->route('tarjeta') === null;
        $soloAlCrear = $creando ? 'nullable' : 'prohibited';

        return [
            'user_id' => $this->reglaMiembro(),
            'nombre' => ['required', 'string', 'max:100'],
            // Opcionales: una tarjeta de tienda puede no tener banco del catálogo ni red
            'banco_id' => ['nullable', 'integer', Rule::exists('bancos', 'id')->where('activo', true)],
            'marca_red_id' => ['nullable', 'integer', Rule::exists('marca_reds', 'id')->where('activo', true)],
            'ultimos_digitos' => ['nullable', 'digits:4'],
            'color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'limite_credito' => ['required', 'numeric', 'gt:0', self::MONTO],
            'tasa_interes' => ['nullable', 'numeric', 'min:0', 'max:999'],
            'dia_corte' => ['required', 'integer', 'between:1,31'],
            'dia_pago' => ['required', 'integer', 'between:1,31'],
            'activo' => ['boolean'],
            'permite_avances' => ['boolean'],
            'cargos_restan_disponible' => ['sometimes', 'boolean'],
            // Situación de la tarjeta al registrarla
            'disponible' => [$creando ? 'required' : 'prohibited', 'numeric', 'min:0', 'lte:limite_credito', self::MONTO],
            'pendiente_monto' => [$soloAlCrear, 'numeric', 'min:0', self::MONTO],
            'pendiente_fecha' => [$soloAlCrear, 'required_if_accepted:tiene_pendiente', 'date_format:Y-m-d'],
            'tiene_pendiente' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'disponible.lte' => 'El disponible no puede ser mayor al límite.',
            'pendiente_fecha.required_if_accepted' => 'Indica la fecha límite de pago del estado de cuenta pendiente.',
        ];
    }

    public function attributes(): array
    {
        return ['banco_id' => 'banco', 'marca_red_id' => 'marca', 'limite_credito' => 'límite', 'dia_corte' => 'día de corte', 'dia_pago' => 'día de pago', 'pendiente_monto' => 'saldo pendiente', 'pendiente_fecha' => 'fecha límite'];
    }

    /** Datos para TarjetaService::crear() o para actualizar la tarjeta. */
    public function datos(): array
    {
        $data = collect($this->validated())->except('tiene_pendiente')->all();
        if (! $this->boolean('tiene_pendiente')) {
            unset($data['pendiente_monto'], $data['pendiente_fecha']);
        }

        return $data;
    }
}
