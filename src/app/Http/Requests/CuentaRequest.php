<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizaFechas;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CuentaRequest extends FormRequest
{
    use NormalizaFechas;

    public function authorize(): bool
    {
        return true; // los permisos se verifican en la ruta (can:cuentas.*)
    }

    protected function prepareForValidation(): void
    {
        $this->normalizarFechas('fecha_inicial');
        $this->merge(['activo' => $this->boolean('activo', ! $this->isMethod('put'))]);
    }

    public function rules(): array
    {
        $editando = $this->route('cuenta') !== null;

        return [
            'nombre' => ['required', 'string', 'max:100'],
            'tipo_cuenta_id' => ['required', 'integer', Rule::exists('tipo_cuentas', 'id')->where('activo', true)],
            'banco_id' => ['nullable', 'integer', Rule::exists('bancos', 'id')->where('activo', true)],
            'numero_cuenta' => ['nullable', 'string', 'max:34'],
            'color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            // El saldo y la fecha inicial definen el historial: solo se fijan al crear la cuenta
            'saldo_inicial' => [$editando ? 'prohibited' : 'required', 'numeric', 'min:0', 'regex:/^\d+(?:\.\d{1,2})?$/'],
            'fecha_inicial' => [$editando ? 'prohibited' : 'required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'activo' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'saldo_inicial.regex' => 'El saldo debe tener máximo dos decimales.',
            'saldo_inicial.prohibited' => 'El saldo inicial no se puede cambiar; registra un ingreso o egreso de ajuste.',
            'fecha_inicial.prohibited' => 'La fecha inicial no se puede cambiar.',
        ];
    }

    public function attributes(): array
    {
        return ['tipo_cuenta_id' => 'tipo de cuenta', 'banco_id' => 'banco', 'numero_cuenta' => 'número de cuenta'];
    }
}
