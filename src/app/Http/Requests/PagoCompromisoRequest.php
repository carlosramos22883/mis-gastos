<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizaFechas;
use App\Services\CicloEfectivoService;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Pago (total o abono) de un compromiso desde el efectivo o desde una cuenta. */
class PagoCompromisoRequest extends FormRequest
{
    use NormalizaFechas;

    public function authorize(): bool
    {
        // Pagar desde efectivo exige crear en efectivo; desde una cuenta, crear en cuentas
        $permiso = $this->input('origen') === 'efectivo' ? 'efectivo.create' : 'cuentas.create';

        return $this->user()->can($permiso);
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
            'origen' => ['required', 'string', 'regex:/^(efectivo|cuenta:\d+)$/'],
            'descripcion' => ['required', 'string', 'max:255'],
            'monto' => ['required', 'numeric', 'gt:0', 'regex:/^\d+(?:\.\d{1,2})?$/'],
            'fecha' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$inicio->toDateString(), 'before_or_equal:'.$limite],
            'categoria_personal_id' => ['required', 'integer', Rule::exists('categorias_personales', 'id')->where(fn ($q) => $q
                ->where('user_id', $user->id)->where('activo', true)->where('tipo', 'egreso'))],
        ];
    }

    public function messages(): array
    {
        return [
            'monto.regex' => 'El monto debe tener máximo dos decimales y ser positivo.',
            'fecha.after_or_equal' => 'La fecha debe estar dentro del ciclo actual.',
            'fecha.before_or_equal' => 'La fecha no puede ser futura.',
        ];
    }

    public function attributes(): array
    {
        return ['categoria_personal_id' => 'categoría'];
    }
}
