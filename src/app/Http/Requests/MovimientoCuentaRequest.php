<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizaFechas;
use App\Http\Requests\Concerns\ValidaMiembroHogar;
use App\Services\CicloEfectivoService;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Ingreso o egreso propio de una cuenta (los de transferencias los crea TransferenciaService). */
class MovimientoCuentaRequest extends FormRequest
{
    use NormalizaFechas, ValidaMiembroHogar;

    public function authorize(): bool
    {
        return true; // los permisos se verifican en la ruta (can:cuentas.*)
    }

    protected function prepareForValidation(): void
    {
        $this->normalizarFechas('fecha');
    }

    public function rules(): array
    {
        $user = $this->user();
        [$inicio, $fin] = app(CicloEfectivoService::class)->cicloConsultado($user);
        $limite = min(Carbon::now($user->zona_horaria ?: config('app.timezone'))->toDateString(), $fin->toDateString());

        return [
            'user_id' => $this->reglaMiembro(),
            'descripcion' => ['required', 'string', 'max:255'],
            'monto' => ['required', 'numeric', 'gt:0', 'regex:/^\d+(?:\.\d{1,2})?$/'],
            'fecha' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$inicio->toDateString(), 'before_or_equal:'.$limite],
            'tipo' => ['required', Rule::in(['ingreso', 'egreso'])],
            'categoria_personal_id' => ['required', 'integer', Rule::exists('categorias_personales', 'id')->where(fn ($q) => $q
                ->where('hogar_id', $user->hogar_id)->where('activo', true)
                ->where('nombre', '!=', CicloEfectivoService::CATEGORIA_SALDO_INICIAL)
                ->where('tipo', $this->input('tipo')))],
        ];
    }

    public function messages(): array
    {
        return [
            'monto.regex' => 'El monto debe tener máximo dos decimales y ser positivo.',
            'fecha.after_or_equal' => 'La fecha debe estar dentro del ciclo actual.',
            'fecha.before_or_equal' => 'La fecha no puede ser futura ni posterior al corte del ciclo.',
        ];
    }
}
