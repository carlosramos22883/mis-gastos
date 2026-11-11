{{-- Alta / edición de un cargo fijo de la tarjeta (seguro, Netflix…) --}}
<form x-data="ajaxForm(async function(data) { $dispatch('close-modal', 'cargo-modal'); await showAlert('success', '¡Listo!', data.message); window.dispatchEvent(new CustomEvent('refresh-table', { detail: data })); })"
    @submit.prevent="submit" method="POST" action="{{ isset($cargo) ? route('tarjetas.cargos.update', [$tarjeta, $cargo]) : route('tarjetas.cargos.store', $tarjeta) }}" class="space-y-4" novalidate>
    @csrf @isset($cargo) @method('PUT') @endisset
    <div class="flex items-center justify-between">
        <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">{{ isset($cargo) ? 'Editar cargo fijo' : 'Nuevo cargo fijo' }} · {{ $tarjeta->nombre }}</h2>
        <button type="button" x-on:click="$dispatch('close-modal', 'cargo-modal')" class="text-gray-400 hover:text-gray-500">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
        </button>
    </div>
    <p class="text-sm text-gray-600 dark:text-gray-300">Lo que la tarjeta cobra cada mes (seguro, Netflix…). El día de su cobro se registra solo como movimiento y descuenta el disponible. Una compra en cuotas no va aquí: regístrala como compra con su número de cuotas.</p>
    <x-floating-input id="descripcion" label="Descripción" maxlength="100" :value="$cargo->descripcion ?? ''" required />
    <div class="grid gap-4 sm:grid-cols-2">
        <x-floating-money id="monto" label="Monto mensual" :value="$cargo->monto ?? ''" :symbol="auth()->user()->monedaPreferida?->simbolo ?? '$'" required />
        <x-floating-select id="dia" label="Día de cobro" :allowEmpty="false"
            :options="collect(range(1, 30))->mapWithKeys(fn ($d) => [$d => 'Día '.$d])->put(31, 'Último día del mes')->all()"
            :value="$cargo->dia ?? (int) substr($hoy, 8, 2)" required />
    </div>
    <x-floating-select id="categoria_personal_id" label="Categoría (opcional)" :options="$categorias" :value="$cargo->categoria_personal_id ?? ''" />
    <div class="grid gap-4 sm:grid-cols-2">
        <x-floating-date id="desde" label="Fecha de inicio" :value="$cargo->desde ?? $hoy" required />
        <x-floating-date id="hasta" label="Fecha de fin (opcional)" :value="$cargo->hasta ?? ''" />
    </div>
    @isset($cargo)
        <x-checkbox name="activo" sinMarcar="0" :checked="$cargo->activo" ayuda="Desmárcalo si ya no lo cobran.">Activo</x-checkbox>
    @endisset
    <div class="flex justify-end gap-3">
        <x-secondary-button type="button" x-on:click="$dispatch('close-modal', 'cargo-modal')">Cancelar</x-secondary-button>
        <x-primary-button type="submit" x-bind:disabled="loading">Guardar</x-primary-button>
    </div>
</form>
