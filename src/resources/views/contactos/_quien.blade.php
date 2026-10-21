{{-- "¿Con quién?": elegir un contacto o escribir uno nuevo (se crea al guardar).
     Uso: @include('contactos._quien', ['valor' => $registro->contacto_id ?? null, 'label' => '¿A quién le pagaste?']) --}}
@can('contactos.view')
    <x-floating-select id="contacto_id" :label="$label ?? '¿Con quién? (opcional)'" searchable creatable
        :options="app(\App\Services\ContactoService::class)->opciones(auth()->user(), $valor ?? null)" :value="$valor ?? ''" />
@endcan
