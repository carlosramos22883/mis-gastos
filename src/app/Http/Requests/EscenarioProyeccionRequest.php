<?php

namespace App\Http\Requests;

use App\Services\ProyeccionService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Escenario de una proyección (web y API). Las palancas llegan como lista (API) o como JSON en un campo oculto (web).
 * Campos de cada palanca según su tipo:
 *  - prestamo: mes, monto, tasa, plazo, comision?, cuota?, destino? (claves de deudas)  - venta: mes, monto, destino?
 *  - gasto: desde, hasta?, monto (gasto mensual nuevo)  - ingreso: desde, hasta?, monto (extra al mes, puede ser negativo)
 *  - unico: mes, monto (positivo entra, negativo sale)  - pagar: mes, deuda, monto? (sin monto = toda la deuda)
 */
class EscenarioProyeccionRequest extends FormRequest
{
    private const OBLIGATORIOS = [
        'prestamo' => ['mes', 'monto', 'plazo'], 'venta' => ['mes', 'monto'], 'gasto' => ['desde', 'monto'],
        'ingreso' => ['desde', 'monto'], 'unico' => ['mes', 'monto'], 'pagar' => ['mes', 'deuda'],
    ];

    public function authorize(): bool
    {
        return true; // los permisos se verifican en la ruta (can:proyecciones.*)
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('palancas'))) {
            $this->merge(['palancas' => json_decode($this->input('palancas'), true) ?? []]);
        }
    }

    public function rules(): array
    {
        $mes = ['nullable', 'date_format:Y-m'];
        $dinero = ['nullable', 'numeric', 'between:-9999999,9999999'];

        return [
            'nombre' => ['required', 'string', 'max:100'],
            'modo_tarjetas' => ['required', Rule::in(['intereses', 'rotar'])],
            'perdida_rotar' => ['nullable', 'numeric', 'between:0,20'],
            'sobrante' => ['required', Rule::in(['abonar', 'ahorrar'])],
            'palancas' => ['present', 'array', 'max:30'],
            'palancas.*.tipo' => ['required', Rule::in(ProyeccionService::TIPOS_PALANCA)],
            'palancas.*.descripcion' => ['nullable', 'string', 'max:100'],
            'palancas.*.mes' => $mes, 'palancas.*.desde' => $mes, 'palancas.*.hasta' => $mes,
            'palancas.*.monto' => $dinero, 'palancas.*.comision' => ['nullable', 'numeric', 'min:0'], 'palancas.*.cuota' => ['nullable', 'numeric', 'min:0'],
            'palancas.*.tasa' => ['nullable', 'numeric', 'between:0,200'], 'palancas.*.plazo' => ['nullable', 'integer', 'between:1,600'],
            'palancas.*.destino' => ['nullable', 'array'], 'palancas.*.destino.*' => ['string', 'regex:/^(tarjeta|prestamo):\d+$/'],
            'palancas.*.deuda' => ['nullable', 'string', 'regex:/^(tarjeta|prestamo):\d+$/'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            foreach ((array) $this->input('palancas', []) as $i => $p) {
                foreach (self::OBLIGATORIOS[$p['tipo'] ?? ''] ?? [] as $campo) {
                    if (($p[$campo] ?? '') === '' || ($p[$campo] ?? null) === null) {
                        $validator->errors()->add('palancas', 'A la palanca '.($i + 1)." le falta: {$campo}.");
                    }
                }
                if (in_array($p['tipo'] ?? '', ['prestamo', 'venta'], true) && (float) ($p['monto'] ?? 0) <= 0) {
                    $validator->errors()->add('palancas', 'A la palanca '.($i + 1).' le falta un monto mayor que cero.');
                }
            }
        }];
    }

    /** La configuración que se guarda y se simula. */
    public function datos(): array
    {
        $v = $this->validated();

        return ['modo_tarjetas' => $v['modo_tarjetas'], 'perdida_rotar' => (float) ($v['perdida_rotar'] ?? 1), 'sobrante' => $v['sobrante'],
            'palancas' => array_values(array_map(fn ($p) => array_filter($p, fn ($x) => $x !== null && $x !== ''), $v['palancas']))];
    }
}
