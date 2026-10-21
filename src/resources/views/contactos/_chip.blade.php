{{-- Contacto de un registro, pequeño y con enlace a su ficha. Uso: @include('contactos._chip', ['contacto' => $mov->contacto]) --}}
@if ($contacto ?? null)
    <a href="{{ route('contactos.show', $contacto) }}" class="ml-1 inline-flex items-center gap-1 align-middle text-xs text-gray-500 hover:underline dark:text-gray-400" title="{{ $contacto->nombreCompleto() }}">
        <x-avatar-contacto :contacto="$contacto" size="xs" />{{ $contacto->nombreCompleto() }}
    </a>
@endif
