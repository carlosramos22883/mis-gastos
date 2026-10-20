<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizaFechas;
use App\Http\Requests\Concerns\ValidaMiembroHogar;
use App\Services\CicloEfectivoService;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;

/** Pago de un préstamo con su desglose (web y API). Desde efectivo o cuenta, la fecha debe ser del ciclo actual. */
class PrestamoPagoRequest extends FormRequest
{
    use NormalizaFechas, ValidaMiembroHogar;

    public function authorize(): bool
    {
        return true; // los permisos se verifican en la ruta (can:prestamos.create)
    }

    protected function prepareForValidation(): void
    {
        $this->normalizarFechas('fecha');
    }

    public function rules(): array
    {
        $user = $this->user();
        $fecha = ['required', 'date_format:Y-m-d', 'before_or_equal:'.Carbon::now($user->zona_horaria ?: config('app.timezone'))->toDateString()];
        if (in_array($this->input('origen'), ['efectivo'], true) || str_starts_with((string) $this->input('origen'), 'cuenta:')) {
            $fecha[] = 'after_or_equal:'.app(CicloEfectivoService::class)->cicloConsultado($user)[0]->toDateString();
        }
        $dinero = ['numeric', 'min:0', 'regex:/^\d+(?:\.\d{1,2})?$/'];

        return [
            'user_id' => $this->reglaMiembro(),
            'fecha' => $fecha,
            'capital' => ['required', ...$dinero],
            'interes' => ['required', ...$dinero],
            'cargos' => ['nullable', ...$dinero],
            'mora' => ['nullable', ...$dinero],
            'origen' => ['required', 'string', 'regex:/^(efectivo|cuenta:\d+|planilla|ninguno)$/'],
            'comentario' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'regex' => 'El monto debe tener máximo dos decimales.',
            'origen.regex' => 'Elige de dónde sale el pago.',
            'fecha.after_or_equal' => 'Si el dinero sale del efectivo o de una cuenta, la fecha debe ser del ciclo actual.',
            'fecha.before_or_equal' => 'La fecha no puede ser futura.',
        ];
    }
}
