@php($modal = $catalogo.'-modal')
<form x-data="ajaxForm(async function(data) { $dispatch('close-modal', '{{ $modal }}'); await showAlert('success', '¡Éxito!', data.message); window.dispatchEvent(new CustomEvent('refresh-table', { detail: data })); })"
    @submit.prevent="submit" method="POST" novalidate class="space-y-4"
    action="{{ $item ? route('admin.catalogos.'.$catalogo.'.update', $item->id) : route('admin.catalogos.'.$catalogo.'.store') }}">
    @csrf @if ($item) @method('PUT') @endif
    <div class="flex items-center justify-between">
        <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">{{ $item ? 'Editar '.$def['singular'] : 'Nuevo '.$def['singular'] }}</h2>
        <button type="button" x-on:click="$dispatch('close-modal', '{{ $modal }}')" class="text-gray-400 hover:text-gray-500">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
        </button>
    </div>
    <x-floating-input id="nombre" label="Nombre" maxlength="100" :value="$item->nombre ?? ''" :placeholder="$def['ejemplo']" required />
    @foreach ($def['campos'] as $campo => [$etiqueta, $tipo, , $opciones])
        @if ($tipo === 'select')
            <x-floating-select :id="$campo" :label="$etiqueta" :allowEmpty="false" :options="$opciones" :value="$item->{$campo} ?? array_key_first($opciones)" required />
        @elseif ($tipo === 'dinero')
            <x-floating-money :id="$campo" :label="$etiqueta" :value="$item?->{$campo} !== null ? $item->{$campo} : ''" />
        @else
            <x-floating-input :id="$campo" :label="$etiqueta" type="number" step="0.001" min="0" :value="$item?->{$campo} !== null ? (float) $item->{$campo} : ''" />
        @endif
    @endforeach
    <x-floating-input id="descripcion" label="Descripción (opcional)" maxlength="255" :value="$item->descripcion ?? ''" />
    <div class="grid grid-cols-2 items-center gap-4">
        <x-floating-input id="orden" label="Orden en las listas" type="number" min="0" max="999" :value="$item->orden ?? 0" />
        <x-checkbox name="activo" :checked="$item->activo ?? true">Activo</x-checkbox>
    </div>
    <div class="flex justify-end gap-3">
        <x-secondary-button type="button" x-on:click="$dispatch('close-modal', '{{ $modal }}')">Cancelar</x-secondary-button>
        <x-primary-button type="submit" x-bind:disabled="loading">Guardar</x-primary-button>
    </div>
</form>
