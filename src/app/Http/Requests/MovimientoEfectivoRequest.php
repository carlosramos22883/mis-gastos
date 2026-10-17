<?php

namespace App\Http\Requests;

use App\Models\Compromiso;
use App\Services\CicloEfectivoService;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Validación de movimientos de efectivo para web y API. Acepta la fecha como d/m/Y o Y-m-d. */
class MovimientoEfectivoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // los permisos se verifican en la ruta (can:efectivo.*)
    }

    protected function prepareForValidation(): void
    {
        $fecha = (string) $this->input('fecha');
        if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $fecha)) {
            try {
                $this->merge(['fecha' => Carbon::createFromFormat('!d/m/Y', $fecha)->format('Y-m-d')]);
            } catch (\Throwable) {
                // se queda como vino y la regla de formato lo rechaza
            }
        }
    }

    public function rules(): array
    {
        $user = $this->user();
        [$inicio, $fin] = app(CicloEfectivoService::class)->cicloConsultado($user);
        $hoy = Carbon::now($user->zona_horaria ?: config('app.timezone'))->toDateString();
        $limite = min($hoy, $fin->toDateString());

        return [
            'descripcion' => ['required', 'string', 'max:255'],
            'monto' => ['required', 'numeric', 'gt:0', 'regex:/^\d+(?:\.\d{1,2})?$/'],
            'fecha' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$inicio->toDateString(), 'before_or_equal:'.$limite],
            'tipo' => ['required', Rule::in(['ingreso', 'egreso'])],
            'categoria_personal_id' => ['required', 'integer', Rule::exists('categorias_personales', 'id')->where(fn ($q) => $q
                ->where('user_id', $user->id)->where('activo', true)
                ->where('nombre', '!=', CicloEfectivoService::CATEGORIA_SALDO_INICIAL)
                ->where('tipo', $this->input('tipo')))],
            'compromiso_id' => ['nullable', 'integer', Rule::exists('compromisos', 'id')->where(fn ($q) => $q
                ->where('user_id', $user->id)->where('estado', Compromiso::PENDIENTE))],
        ];
    }

    public function messages(): array
    {
        [$inicio] = app(CicloEfectivoService::class)->cicloConsultado($this->user());

        return [
            'monto.regex' => 'El monto debe tener máximo dos decimales y ser positivo.',
            'fecha.after_or_equal' => 'La fecha debe estar dentro del ciclo actual (desde el '.$inicio->format('d/m/Y').').',
            'fecha.before_or_equal' => 'La fecha no puede ser futura ni posterior al corte del ciclo.',
            'fecha.date_format' => 'La fecha no es válida.',
        ];
    }
}
