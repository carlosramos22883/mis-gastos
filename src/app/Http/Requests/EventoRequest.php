<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizaFechas;
use App\Services\CicloEfectivoService;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Evento (web y API), como una tabla: participantes ("miembro:{id}", id de un contacto o "nuevo:Nombre"), quién pagó
 * (su posición), productos con su precio y cuántos pidió cada participante ("cantidades" por posición), la foto de la
 * cuenta (opcional) y, si pagó el hogar, de dónde salió el dinero.
 */
class EventoRequest extends FormRequest
{
    use NormalizaFechas;

    public function authorize(): bool
    {
        return true; // los permisos se verifican en la ruta
    }

    protected function prepareForValidation(): void
    {
        $this->normalizarFechas('fecha');
        // Los checkboxes del formulario web llegan como "1"; los que no se marcan no llegan
        $this->merge(['participantes' => collect($this->input('participantes', []))->map(fn ($p) => is_array($p) ? $p + ['es_festejado' => false] : $p)->values()->all()]);
    }

    public function rules(): array
    {
        $user = $this->user();
        $hoy = Carbon::now($user->zona_horaria ?: config('app.timezone'))->toDateString();
        $fecha = ['required', 'date_format:Y-m-d', 'before_or_equal:'.$hoy];
        if ($this->pagaElHogar() && ! in_array($this->input('origen'), [null, '', 'ninguno'], true)) {
            $fecha[] = 'after_or_equal:'.app(CicloEfectivoService::class)->cicloConsultado($user)[0]->toDateString();
        }

        return [
            'nombre' => ['required', 'string', 'max:150'],
            'fecha' => $fecha,
            'lugar' => ['nullable', 'string', 'max:150'],
            'comentario' => ['nullable', 'string', 'max:1000'],
            'propina_tipo' => ['required', Rule::in(['ninguna', 'porcentaje', 'monto'])],
            'propina_valor' => ['nullable', 'required_unless:propina_tipo,ninguna', 'numeric', 'min:0', 'regex:/^\d+(?:\.\d{1,2})?$/', $this->input('propina_tipo') === 'porcentaje' ? 'max:100' : 'max:99999999'],
            'participantes' => ['required', 'array', 'min:1', 'max:60'],
            'participantes.*.quien' => ['required', 'string', 'max:110', 'regex:/^(miembro:\d+|\d+|nuevo:.+)$/'],
            'participantes.*.es_festejado' => ['boolean'],
            'pagador' => ['required', 'integer', 'min:0'],
            'consumos' => ['required', 'array', 'min:1', 'max:100'],
            'consumos.*.descripcion' => ['required', 'string', 'max:150'],
            'consumos.*.precio' => ['required', 'numeric', 'gt:0', 'max:9999999', 'regex:/^\d+(?:\.\d{1,2})?$/'],
            'consumos.*.cantidades' => ['required', 'array'],
            'consumos.*.cantidades.*' => ['nullable', 'integer', 'min:0', 'max:999'],
            'foto' => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:10240'],
            'quitar_foto' => ['nullable', 'boolean'],
            'origen' => ['nullable', 'string', 'regex:/^(efectivo|cuenta:\d+|tarjeta:\d+|ninguno)$/'],
            'categoria_personal_id' => [$this->pagaElHogar() && ! in_array($this->input('origen'), [null, '', 'ninguno'], true) ? 'required' : 'nullable', 'integer',
                Rule::exists('categorias_personales', 'id')->where(fn ($q) => $q->where('hogar_id', $user->hogar_id)->where('activo', true)->where('tipo', 'egreso'))],
            'confirmar' => ['nullable', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $v) {
            $participantes = $this->input('participantes', []);
            $n = is_array($participantes) ? count($participantes) : 0;
            if ($n && collect($participantes)->every(fn ($p) => ! empty($p['es_festejado']))) {
                $v->errors()->add('participantes', 'Al menos una persona debe pagar: no pueden ser todos festejados.');
            }
            if ((int) $this->input('pagador') >= $n) {
                $v->errors()->add('pagador', 'Elige quién pagó la cuenta.');
            }
            foreach ((array) $this->input('consumos', []) as $i => $c) {
                $cantidades = collect(is_array($c['cantidades'] ?? null) ? $c['cantidades'] : []);
                if ($cantidades->keys()->contains(fn ($pos) => ! is_numeric($pos) || (int) $pos >= $n)) {
                    $v->errors()->add("consumos.{$i}.cantidades", 'Las cantidades no corresponden a los participantes.');
                } elseif ($cantidades->sum(fn ($c) => (int) $c) <= 0) {
                    $v->errors()->add("consumos.{$i}.cantidades", 'Nadie pidió «'.($c['descripcion'] ?? '').'»: anota cuántos pidió cada uno o quítalo.');
                }
            }
        }];
    }

    public function messages(): array
    {
        return [
            'consumos.required' => 'Agrega al menos un producto.',
            'consumos.*.cantidades.required' => 'Anota cuántos pidió cada uno.',
            'foto.image' => 'La foto de la cuenta debe ser una imagen (JPG o PNG).',
            'foto.max' => 'La foto de la cuenta no puede pesar más de 10 MB.',
            'consumos.*.precio.regex' => 'El precio debe tener máximo dos decimales.',
            'propina_valor.regex' => 'La propina debe tener máximo dos decimales.',
            'propina_valor.max' => 'Revisa la propina.',
            'participantes.*.quien.required' => 'Elige a la persona.',
            'fecha.after_or_equal' => 'Si el pago sale del efectivo, una cuenta o una tarjeta, la fecha debe ser del ciclo actual. Para eventos anteriores elige "Solo anotar".',
            'fecha.before_or_equal' => 'La fecha no puede ser futura.',
        ];
    }

    public function attributes(): array
    {
        return ['categoria_personal_id' => 'categoría', 'consumos.*.descripcion' => 'producto', 'consumos.*.precio' => 'precio', 'consumos.*.cantidades.*' => 'cantidad', 'foto' => 'foto de la cuenta'];
    }

    /** Pagó un miembro del hogar (el pagador es "miembro:{id}"). */
    private function pagaElHogar(): bool
    {
        $pagador = $this->input('participantes.'.(int) $this->input('pagador').'.quien');

        return is_string($pagador) && str_starts_with($pagador, 'miembro:');
    }
}
