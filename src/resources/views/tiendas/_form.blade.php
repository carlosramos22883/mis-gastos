@php($editing = isset($tienda))
<form x-data="ajaxForm(async function(data) { $dispatch('close-modal', 'tienda-modal'); await showAlert('success', '¡Listo!', data.message); window.location.reload(); })"
    @submit.prevent="submit" method="POST" action="{{ $editing ? route('tiendas.update', $tienda) : route('tiendas.store') }}" class="space-y-4" novalidate>
    @csrf @if ($editing) @method('PUT') @endif
    <div class="flex items-center justify-between">
        <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">{{ $editing ? 'Editar tienda' : 'Nueva tienda' }}</h2>
        <button type="button" x-on:click="$dispatch('close-modal', 'tienda-modal')" class="text-gray-400 hover:text-gray-500" aria-label="Cerrar">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
        </button>
    </div>
    <x-floating-input id="nombre" label="Nombre (ej. Súper Selectos)" maxlength="80" :value="$tienda->nombre ?? ''" required />
    <x-floating-select id="fuente" label="Precios en línea (opcional)" :options="\App\Models\Tienda::FUENTES" :value="$tienda->fuente ?? ''" />
    <p class="text-xs text-gray-500 dark:text-gray-400">Si la tienda tiene página con precios, elígela: la app podrá consultar sus precios. Si no, los precios se anotan al comprar o en la tienda.</p>
    <x-floating-input id="notas" label="Notas (opcional, ej. la de Metrocentro)" maxlength="1000" :value="$tienda->notas ?? ''" />
    @if ($editing)
        <x-checkbox name="activo" sinMarcar="0" :checked="$tienda->activo" ayuda="Desmárcala si ya no compras ahí: se conserva su historial de precios.">Sigo comprando aquí</x-checkbox>
    @endif
    <div class="flex justify-end gap-3">
        <x-secondary-button type="button" x-on:click="$dispatch('close-modal', 'tienda-modal')">Cancelar</x-secondary-button>
        <x-primary-button type="submit" x-bind:disabled="loading">Guardar</x-primary-button>
    </div>
</form>
