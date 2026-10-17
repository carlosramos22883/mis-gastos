@php($simbolo = auth()->user()->monedaPreferida?->simbolo ?? '$')
<form x-data="ajaxForm(async function(data) { $dispatch('close-modal', 'transferencia-modal'); await showAlert('success', '¡Éxito!', data.message); window.dispatchEvent(new CustomEvent('refresh-table', { detail: data })); })"
    @submit.prevent="submit" method="POST" action="{{ route('transferencias.store') }}" class="space-y-4" novalidate>
    @csrf
    <div class="flex items-center justify-between">
        <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">Transferir dinero</h2>
        <button type="button" x-on:click="$dispatch('close-modal', 'transferencia-modal')" class="text-gray-400 hover:text-gray-500">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
        </button>
    </div>
    <p class="text-sm text-gray-600 dark:text-gray-300">Por ejemplo: depositar efectivo en el banco, retirar de una billetera o pasar dinero entre cuentas. Se registra la salida y la entrada.</p>
    <div class="grid gap-4 sm:grid-cols-2">
        <x-floating-select id="origen" label="Desde" :allowEmpty="false" :options="$origenes" :value="$origen" required />
        <x-floating-select id="destino" label="Hacia" :allowEmpty="false" :options="$destinos" :value="$destino" required />
    </div>
    <x-floating-money id="monto" label="Monto" :symbol="$simbolo" required />
    <x-floating-date id="fecha" label="Fecha" :value="now(auth()->user()->zona_horaria)" :min="$cycleStart" :max="min(now(auth()->user()->zona_horaria), $cycleEnd)" required />
    <x-floating-input id="descripcion" label="Descripción (opcional)" maxlength="255" />
    <div class="flex justify-end gap-3">
        <x-secondary-button type="button" x-on:click="$dispatch('close-modal', 'transferencia-modal')">Cancelar</x-secondary-button>
        <x-primary-button type="submit" x-bind:disabled="loading">Transferir</x-primary-button>
    </div>
</form>
