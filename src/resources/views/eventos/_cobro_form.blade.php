@php($simbolo = auth()->user()->monedaPreferida?->simbolo ?? '$')
<form x-data="ajaxForm(async function(data) { $dispatch('close-modal', 'cobro-modal'); await showAlert('success', '¡Éxito!', data.message); window.dispatchEvent(new CustomEvent('refresh-table', { detail: data })); })"
    @submit.prevent="submit" method="POST" action="{{ route('eventos.cobro.store', [$evento, $participante]) }}" class="space-y-4" novalidate>
    @csrf
    <div class="flex items-center justify-between">
        <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">Cobrar a {{ $participante->nombre() }}</h2>
        <button type="button" x-on:click="$dispatch('close-modal', 'cobro-modal')" class="text-gray-400 hover:text-gray-500">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
        </button>
    </div>
    <p class="rounded-lg bg-primary-50 px-4 py-3 text-sm text-primary-700 dark:bg-gray-700 dark:text-primary-300">
        De <strong>{{ $evento->nombre }}</strong> te debe <strong>{{ $simbolo }} {{ number_format($pendiente, 2) }}</strong>. El dinero entra solo al efectivo o a la cuenta que elijas (también se ve en "Nos deben").
    </p>
    <div class="grid gap-4 sm:grid-cols-2">
        <x-floating-money id="monto" label="Monto" :symbol="$simbolo" :value="$pendiente" required />
        <x-floating-date id="fecha" label="Fecha" :value="now(auth()->user()->zona_horaria)" :max="now(auth()->user()->zona_horaria)" required />
    </div>
    <x-floating-select id="destino" label="¿A dónde entró el dinero?" :allowEmpty="false" :options="$destinos" value="efectivo" required />
    @include('hogar._quien', ['label' => '¿Quién recibió?'])
    <x-floating-input id="comentario" label="Comentario (opcional)" maxlength="1000" />
    <div class="flex justify-end gap-3">
        <x-secondary-button type="button" x-on:click="$dispatch('close-modal', 'cobro-modal')">Cancelar</x-secondary-button>
        <x-primary-button type="submit" x-bind:disabled="loading">Registrar pago</x-primary-button>
    </div>
</form>
