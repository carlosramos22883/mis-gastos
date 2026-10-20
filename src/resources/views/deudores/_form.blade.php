@php($editing = isset($deudor))
<form x-data="ajaxForm(async function(data) { $dispatch('close-modal', 'deudor-modal'); await showAlert('success', '¡Éxito!', data.message); window.dispatchEvent(new CustomEvent('refresh-table', { detail: data })); })"
    @submit.prevent="submit" method="POST" action="{{ $editing ? route('deudores.update', $deudor) : route('deudores.store') }}" class="space-y-4" novalidate>
    @csrf @if ($editing) @method('PUT') @endif
    <div class="flex items-center justify-between">
        <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">{{ $editing ? 'Editar deudor' : 'Nuevo deudor' }}</h2>
        <button type="button" x-on:click="$dispatch('close-modal', 'deudor-modal')" class="text-gray-400 hover:text-gray-500">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
        </button>
    </div>
    <x-floating-input id="nombre" label="Nombre (ej. Aquino)" maxlength="100" :value="$deudor->nombre ?? ''" required />
    <div class="grid gap-4 sm:grid-cols-2">
        <x-floating-select id="tipo" label="Tipo" :allowEmpty="false" :options="\App\Models\Deudor::TIPOS" :value="$deudor->tipo ?? 'persona'" required />
        <x-floating-input id="contacto" label="Contacto (opcional)" maxlength="150" :value="$deudor->contacto ?? ''" />
    </div>
    <x-floating-input id="notas" label="Notas (opcional)" maxlength="1000" :value="$deudor->notas ?? ''" />
    @if ($editing)
        <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
            <input type="hidden" name="activo" value="0">
            <input type="checkbox" name="activo" value="1" @checked($deudor->activo) class="rounded border-gray-300 text-primary-600 focus:ring-primary-500 dark:border-gray-600"> Activo
        </label>
    @endif
    <div class="flex justify-end gap-3">
        <x-secondary-button type="button" x-on:click="$dispatch('close-modal', 'deudor-modal')">Cancelar</x-secondary-button>
        <x-primary-button type="submit" x-bind:disabled="loading">Guardar</x-primary-button>
    </div>
</form>
