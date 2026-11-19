<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizaFechas;
use App\Http\Requests\Concerns\ValidaMiembroHogar;
use App\Services\CicloEfectivoService;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;

/**
 * «Pagar aquí» de una lista de compras (CompraPagoService). Categoría = id o "nueva:Nombre". presupuestos[clave] =
 * lo que se decidió en el aviso de presupuesto de esa categoría (cubrir con traspasos o pasarme).
 */
class CompraPagoRequest extends FormRequest
{
    use NormalizaFechas, ValidaMiembroHogar;

    public function authorize(): bool
    {
        return true; // la ruta exige despensa.edit; el servicio, el permiso de crear en el origen elegido
    }

    protected function prepareForValidation(): void
    {
        $this->normalizarFechas('fecha');
        $dinero = fn ($v) => is_string($v) ? str_replace([',', '$', ' '], '', $v) : $v;
        $extras = collect($this->input('extras', []))->filter(fn ($e) => is_array($e) && (trim((string) ($e['descripcion'] ?? '')) !== '' || trim((string) ($e['precio'] ?? '')) !== ''))
            ->map(fn ($e) => ['precio' => $dinero($e['precio'] ?? null)] + $e)->values()->all();
        if ($this->input('extras_modo') !== 'detalle') {
            $extras = []; // lo escrito en otra opción no se valida ni se guarda
        }
        $this->merge(['monto_lista' => $dinero($this->input('monto_lista')), 'extras_total' => $dinero($this->input('extras_total')), 'extras' => $extras]);
    }

    public function rules(): array
    {
        $user = $this->user();
        [$inicio, $fin] = app(CicloEfectivoService::class)->cicloConsultado($user);
        $limite = min(Carbon::now($user->zona_horaria ?: config('app.timezone'))->toDateString(), $fin->toDateString());
        $monto = ['numeric', 'regex:/^\d+(?:\.\d{1,2})?$/', 'max:9999999'];

        return [
            'user_id' => $this->reglaMiembro(),
            'origen' => ['required', 'string', 'regex:/^(efectivo|cuenta:\d+|tarjeta:\d+)$/'],
            'fecha' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$inicio->toDateString(), 'before_or_equal:'.$limite],
            'categoria' => ['nullable', 'string', 'max:110'],
            'monto_lista' => ['nullable', ...$monto],
            'extras_modo' => ['required', 'in:ninguno,total,detalle'],
            'extras_total' => ['nullable', 'required_if:extras_modo,total', ...$monto, 'gt:0'],
            'extras_categoria' => ['nullable', 'required_if:extras_modo,total', 'string', 'max:110'],
            'extras' => ['array', 'max:30', 'required_if:extras_modo,detalle'],
            'extras.*.descripcion' => ['required', 'string', 'max:200'],
            'extras.*.precio' => ['required', ...$monto, 'gt:0'],
            'extras.*.categoria' => ['required', 'string', 'max:110'],
            'presupuestos' => ['array'],
            'presupuestos.*.modo' => ['required', 'in:revisar,pasarme,cubrir'],
            'presupuestos.*.traspasos' => ['array'],
            'presupuestos.*.traspasos.*.presupuesto_id' => ['required', 'integer'],
            'presupuestos.*.traspasos.*.monto' => ['required', 'numeric', 'gt:0'],
            'confirmar' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'monto_lista.regex' => 'El total debe tener máximo dos decimales.',
            'extras_total.regex' => 'El total debe tener máximo dos decimales.',
            'extras.*.precio.regex' => 'El precio debe tener máximo dos decimales.',
            'extras.required_if' => 'Anota al menos una cosa extra, o elige otra opción.',
            'extras_total.required_if' => 'Escribe cuánto fue lo extra.',
            'extras_categoria.required_if' => 'Elige la categoría de lo extra.',
            'fecha.after_or_equal' => 'La fecha debe estar dentro del ciclo actual.',
            'fecha.before_or_equal' => 'La fecha no puede ser futura.',
        ];
    }

    public function attributes(): array
    {
        return ['monto_lista' => 'total de la lista', 'extras_total' => 'total de lo extra', 'extras_categoria' => 'categoría de lo extra',
            'extras.*.descripcion' => 'qué fue', 'extras.*.precio' => 'precio', 'extras.*.categoria' => 'categoría'];
    }
}
