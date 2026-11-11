<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizaFechas;
use App\Services\CicloEfectivoService;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Pagó otra persona: marcar que un participante ya le pagó (un miembro del hogar puede registrar su egreso). */
class EventoPagadoRequest extends FormRequest
{
    use NormalizaFechas;

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
        $conMovimiento = ! in_array($this->input('origen'), [null, '', 'ninguno'], true);
        $fecha = ['required', 'date_format:Y-m-d', 'before_or_equal:'.Carbon::now($user->zona_horaria ?: config('app.timezone'))->toDateString()];
        if ($conMovimiento) {
            $fecha[] = 'after_or_equal:'.app(CicloEfectivoService::class)->cicloConsultado($user)[0]->toDateString();
        }

        return [
            'fecha' => $fecha,
            'origen' => ['nullable', 'string', 'regex:/^(efectivo|cuenta:\d+|tarjeta:\d+|ninguno)$/'],
            'categoria_personal_id' => [$conMovimiento ? 'required' : 'nullable', 'integer',
                Rule::exists('categorias_personales', 'id')->where(fn ($q) => $q->where('hogar_id', $user->hogar_id)->where('activo', true)->where('tipo', 'egreso'))],
            'confirmar' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'fecha.after_or_equal' => 'Si el dinero sale del efectivo, una cuenta o una tarjeta, la fecha debe ser del ciclo actual. Para pagos anteriores elige "Solo anotar".',
            'fecha.before_or_equal' => 'La fecha no puede ser futura.',
        ];
    }

    public function attributes(): array
    {
        return ['categoria_personal_id' => 'categoría'];
    }
}
