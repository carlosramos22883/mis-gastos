<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizaFechas;
use App\Http\Requests\Concerns\ResuelveContacto;
use App\Http\Requests\Concerns\ValidaMiembroHogar;
use App\Models\Prestamo;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

/** Datos de un préstamo (web y API). user_id = titular. */
class PrestamoRequest extends FormRequest
{
    use NormalizaFechas, ResuelveContacto, ValidaMiembroHogar;

    public function authorize(): bool
    {
        return true; // los permisos se verifican en la ruta (can:prestamos.*)
    }

    protected function prepareForValidation(): void
    {
        $this->normalizarFechas('fecha_otorgamiento', 'fecha_saldo');
    }

    public function rules(): array
    {
        $hoy = Carbon::now($this->user()->zona_horaria ?: config('app.timezone'))->toDateString();
        $dinero = ['numeric', 'min:0', 'regex:/^\d+(?:\.\d{1,2})?$/'];

        return [
            'user_id' => $this->reglaMiembro(),
            'contacto_id' => $this->reglaContacto(),
            'nombre' => ['required', 'string', 'max:100'],
            'tipo_prestamo_id' => ['required', 'integer', Rule::exists('tipos_prestamo', 'id')->where('activo', true)],
            'banco_id' => ['nullable', 'integer', Rule::exists('bancos', 'id')],
            'acreedor' => ['nullable', 'string', 'max:100'],
            'monto_original' => ['required', ...$dinero, 'gt:0'],
            'tasa_anual' => ['required', 'numeric', 'min:0', 'max:100'],
            'tasa_mora' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'plazo_meses' => ['nullable', 'integer', 'between:1,600'],
            'fecha_otorgamiento' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.$hoy],
            'dia_pago' => ['nullable', 'integer', 'between:1,31'],
            'cuota' => ['nullable', ...$dinero],
            'saldo_inicial' => ['required', ...$dinero],
            'fecha_saldo' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$hoy],
            'medio_pago' => ['required', Rule::in(array_keys(Prestamo::MEDIOS))],
            'monto_planilla' => ['nullable', ...$dinero],
            'color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'notas' => ['nullable', 'string', 'max:1000'],
            'activo' => ['nullable', 'boolean'],
            'cargos' => ['nullable', 'array', 'max:10'],
            'cargos.*.nombre' => ['nullable', 'string', 'max:100'],
            'cargos.*.monto' => ['nullable', ...$dinero],
            'crear_compromiso' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'saldo_inicial.required' => 'Indica cuánto debes hoy (saldo de capital).',
            'fecha_saldo.required' => 'Indica a qué fecha corresponde ese saldo (normalmente el último pago).',
            'regex' => 'El monto debe tener máximo dos decimales.',
        ];
    }

    public function attributes(): array
    {
        return ['monto_original' => 'monto original', 'tasa_anual' => 'tasa de interés', 'plazo_meses' => 'plazo', 'dia_pago' => 'día de pago',
            'saldo_inicial' => 'saldo', 'fecha_saldo' => 'fecha del saldo', 'fecha_otorgamiento' => 'fecha de otorgamiento'];
    }

    public function datos(): array
    {
        $data = Arr::except($this->validated(), ['cargos', 'crear_compromiso']);
        if ($this->has('activo') || $this->isMethod('post')) {
            $data['activo'] = $this->boolean('activo', true);
        }

        return $data;
    }

    /** null si no vinieron (ej. una edición desde la API que no los toca): entonces no se modifican. */
    public function cargos(): ?array
    {
        return $this->has('cargos') ? array_values($this->validated('cargos') ?? []) : null;
    }
}
