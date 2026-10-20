<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Validation\Rule;

/**
 * user_id = quién hizo el movimiento (o el titular de la cuenta/tarjeta). Es opcional:
 * si no viene, queda quien lo registra. Solo puede ser un miembro del mismo hogar.
 */
trait ValidaMiembroHogar
{
    protected function reglaMiembro(): array
    {
        return ['sometimes', 'required', 'integer', Rule::exists('users', 'id')->where('hogar_id', $this->user()->hogar_id)];
    }
}
