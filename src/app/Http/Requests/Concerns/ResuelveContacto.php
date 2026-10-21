<?php

namespace App\Http\Requests\Concerns;

use App\Services\ContactoService;

/**
 * "¿Con quién?" (contacto_id): un contacto del hogar o "nuevo:Nombre" escrito en el selector.
 * Se convierte en id solo cuando todo lo demás pasó la validación (así no quedan contactos huérfanos).
 */
trait ResuelveContacto
{
    protected function reglaContacto(): array
    {
        return ['nullable', 'string', 'max:110', 'regex:/^(\d+|nuevo:.+)$/'];
    }

    protected function passedValidation(): void
    {
        if (! $this->has('contacto_id')) {
            return;
        }
        $id = app(ContactoService::class)->resolver($this->user(), $this->input('contacto_id'));
        $data = $this->validator->getData();
        $data['contacto_id'] = $id;
        $this->validator->setData($data);
        $this->merge(['contacto_id' => $id]);
    }
}
