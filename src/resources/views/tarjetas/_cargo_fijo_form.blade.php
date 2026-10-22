{{-- Alta / edición de un cargo fijo de la tarjeta (seguro, membresía…) --}}
<form x-data="ajaxForm(async function(data) { $dispatch('close-modal', 'cargo-modal'); await showAlert('success', '¡Listo!', data.message); window.dispatchEvent(new CustomEvent('refresh-table', { detail: data })); })"
    @submit.prevent="submit" method="POST" action="{{ isset($cargo) ? route('tarjetas.cargos.update', [$tarjeta, $cargo]) : route('tarjetas.cargos.store', $tarjeta) }}" class="space-y-4" novalidate>
    @csrf @isset($cargo) @method('PUT') @endisset
    <div class="flex items-center justify-between">
        <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">{{ isset($cargo) ? 'Editar cargo fijo' : 'Nuevo cargo fijo' }} · {{ $tarjeta->nombre }}</h2>
        <button type="button" x-on:click="$dispatch('close-modal', 'cargo-modal')" class="text-gray-400 hover:text-gray-500">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
        </button>
    </div>
    <p class="text-sm text-gray-600 dark:text-gray-300">Lo que la tarjeta cobra en cada corte (seguro, membresía…). Se agrega solo al estado de cuenta, sin que tengas que registrarlo.</p>
    <x-floating-input id="descripcion" label="Descripción" maxlength="100" :value="$cargo->descripcion ?? 'Seguro'" required />
    <x-floating-money id="monto" label="Monto por corte" :value="$cargo->monto ?? ''" :symbol="auth()->user()->monedaPreferida?->simbolo ?? '$'" required />
    <x-floating-select id="categoria_personal_id" label="Categoría (opcional)" :options="$categorias" :value="$cargo->categoria_personal_id ?? ''" />
    <x-floating-date id="desde" label="Se cobra desde el corte del" :value="$cargo->desde ?? $proximoCorte" required />
    @isset($cargo)
        <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
            <input type="hidden" name="activo" value="0">
            <input type="checkbox" name="activo" value="1" @checked($cargo->activo) class="rounded border-gray-300 text-primary-600 focus:ring-primary-500 dark:border-gray-600"> Activo (desmárcalo si ya no lo cobran)
        </label>
    @endisset
    <div class="flex justify-end gap-3">
        <x-secondary-button type="button" x-on:click="$dispatch('close-modal', 'cargo-modal')">Cancelar</x-secondary-button>
        <x-primary-button type="submit" x-bind:disabled="loading">Guardar</x-primary-button>
    </div>
</form>
