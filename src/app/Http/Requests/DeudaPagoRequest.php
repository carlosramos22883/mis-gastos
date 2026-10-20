<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizaFechas;
use App\Http\Requests\Concerns\ValidaMiembroHogar;
use App\Services\CicloEfectivoService;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Pago de un deudor (web y API). Si entra al efectivo o a una cuenta, la fecha debe ser del ciclo actual. */
class DeudaPagoRequest extends FormRequest
{
    use NormalizaFechas, ValidaMiembroHogar;

    public function authorize(): bool
    {
        return true; // los permisos se verifican en la ruta (can:deudores.create)
    }

    protected function prepareForValidation(): void
    {
        $this->normalizarFechas('fecha');
    }

    public function rules(): array
    {
        $user = $this->user();
        $hoy = Carbon::now($user->zona_horaria ?: config('app.timezone'))->toDateString();
        $fecha = ['required', 'date_format:Y-m-d', 'before_or_equal:'.$hoy];
        if ($this->input('destino') !== 'ninguno') {
            $fecha[] = 'after_or_equal:'.app(CicloEfectivoService::class)->cicloConsultado($user)[0]->toDateString();
        }

        return [
            'user_id' => $this->reglaMiembro(),
            'monto' => ['required', 'numeric', 'gt:0', 'regex:/^\d+(?:\.\d{1,2})?$/'],
            'fecha' => $fecha,
            'destino' => ['required', 'string', 'regex:/^(efectivo|cuenta:\d+|ninguno)$/'],
            'deuda_id' => ['nullable', 'integer', Rule::exists('deudas', 'id')->where('deudor_id', $this->route('deudor')?->id)],
            'comentario' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'monto.regex' => 'El monto debe tener máximo dos decimales y ser positivo.',
            'fecha.after_or_equal' => 'Si el dinero entra al efectivo o a una cuenta, la fecha debe ser del ciclo actual. Para pagos anteriores elige "Solo anotar".',
            'fecha.before_or_equal' => 'La fecha no puede ser futura.',
        ];
    }
}
