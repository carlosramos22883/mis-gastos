<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizaFechas;
use App\Http\Requests\Concerns\ValidaMiembroHogar;
use App\Services\CicloEfectivoService;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;

/** Cobrar a un participante su parte de un evento (pagó el hogar): entra al efectivo, a una cuenta o solo se anota. */
class EventoCobroRequest extends FormRequest
{
    use NormalizaFechas, ValidaMiembroHogar;

    public function authorize(): bool
    {
        return true; // los permisos se verifican en la ruta
    }

    protected function prepareForValidation(): void
    {
        $this->normalizarFechas('fecha');
    }

    public function rules(): array
    {
        $user = $this->user();
        $fecha = ['required', 'date_format:Y-m-d', 'before_or_equal:'.Carbon::now($user->zona_horaria ?: config('app.timezone'))->toDateString()];
        if ($this->input('destino') !== 'ninguno') {
            $fecha[] = 'after_or_equal:'.app(CicloEfectivoService::class)->cicloConsultado($user)[0]->toDateString();
        }

        return [
            'user_id' => $this->reglaMiembro(),
            'monto' => ['required', 'numeric', 'gt:0', 'regex:/^\d+(?:\.\d{1,2})?$/'],
            'fecha' => $fecha,
            'destino' => ['required', 'string', 'regex:/^(efectivo|cuenta:\d+|ninguno)$/'],
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
