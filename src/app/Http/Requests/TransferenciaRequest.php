<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizaFechas;
use App\Http\Requests\Concerns\ValidaMiembroHogar;
use App\Services\CicloEfectivoService;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;

class TransferenciaRequest extends FormRequest
{
    use NormalizaFechas, ValidaMiembroHogar;

    public function authorize(): bool
    {
        return true; // los permisos se verifican en la ruta (can:cuentas.create)
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
            'origen' => ['required', 'string', 'regex:/^(efectivo|cuenta:\d+|tarjeta:\d+)$/'],
            'destino' => ['required', 'string', 'regex:/^(efectivo|cuenta:\d+)$/', 'different:origen'],
            'monto' => ['required', 'numeric', 'gt:0', 'regex:/^\d+(?:\.\d{1,2})?$/'],
            'fecha' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$inicio->toDateString(), 'before_or_equal:'.$limite],
            'descripcion' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'destino.different' => 'El origen y el destino deben ser distintos.',
            'monto.regex' => 'El monto debe tener máximo dos decimales y ser positivo.',
            'fecha.after_or_equal' => 'La fecha debe estar dentro del ciclo actual.',
            'fecha.before_or_equal' => 'La fecha no puede ser futura.',
        ];
    }
}
