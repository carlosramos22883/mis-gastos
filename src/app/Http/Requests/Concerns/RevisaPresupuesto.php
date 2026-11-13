<?php

namespace App\Http\Requests\Concerns;

use App\Services\PresupuestoTraspasoService;
use Illuminate\Validation\Rule;

/**
 * Campos para revisar el presupuesto de un gasto antes de guardarlo (PresupuestoTraspasoService): presupuesto =
 * revisar | pasarme | cubrir (sin él no se revisa) y traspasos = [{presupuesto_id, monto}] (cuánto se quita a cada categoría).
 */
trait RevisaPresupuesto
{
    protected function reglasPresupuesto(): array
    {
        return [
            'presupuesto' => ['nullable', Rule::in(PresupuestoTraspasoService::MODOS)],
            'traspasos' => ['nullable', 'array', 'max:20'],
            'traspasos.*.presupuesto_id' => ['required', 'integer'],
            'traspasos.*.monto' => ['nullable', 'numeric', 'min:0', 'regex:/^\d+(?:\.\d{1,2})?$/'],
        ];
    }
}
