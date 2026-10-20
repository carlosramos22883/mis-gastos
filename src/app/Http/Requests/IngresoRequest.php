<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizaFechas;
use App\Http\Requests\Concerns\ValidaMiembroHogar;
use App\Services\CicloEfectivoService;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Pago recibido con su desglose (web y API). Si entra al efectivo o a una cuenta, la fecha debe ser del ciclo actual. */
class IngresoRequest extends FormRequest
{
    use NormalizaFechas, ValidaMiembroHogar;

    public function authorize(): bool
    {
        return true; // los permisos se verifican en la ruta (can:ingresos.create)
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
        $dinero = ['numeric', 'min:0', 'regex:/^\d+(?:\.\d{1,2})?$/'];

        return [
            'user_id' => $this->reglaMiembro(),
            'fecha' => $fecha,
            'bruto' => ['required', ...$dinero],
            'destino' => ['required', 'string', 'regex:/^(efectivo|cuenta:\d+|ninguno)$/'],
            'comentario' => ['nullable', 'string', 'max:1000'],
            'lineas' => ['nullable', 'array', 'max:30'],
            'lineas.*.nombre' => ['nullable', 'string', 'max:100'],
            'lineas.*.tipo' => ['required_with:lineas.*.nombre', Rule::in(['descuento', 'adicion'])],
            'lineas.*.monto' => ['nullable', ...$dinero],
            'lineas.*.prestamo_id' => ['nullable', 'integer', Rule::exists('prestamos', 'id')->where('hogar_id', $user->hogar_id)],
        ];
    }

    public function messages(): array
    {
        return [
            'regex' => 'El monto debe tener máximo dos decimales.',
            'destino.regex' => 'Elige a dónde entró el dinero.',
            'fecha.after_or_equal' => 'Si el dinero entra al efectivo o a una cuenta, la fecha debe ser del ciclo actual. Para pagos anteriores elige "Solo anotar".',
            'fecha.before_or_equal' => 'La fecha no puede ser futura.',
        ];
    }
}
