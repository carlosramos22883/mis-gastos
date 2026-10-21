@php($simbolo = auth()->user()->monedaPreferida?->simbolo ?? '$')
<form x-data="ajaxForm(async function(data) { $dispatch('close-modal', 'deuda-modal'); await showAlert('success', '¡Éxito!', data.message); window.dispatchEvent(new CustomEvent('refresh-table', { detail: data })); })"
    @submit.prevent="submit" method="POST" action="{{ route('contactos.deudas.store', $contacto) }}" class="space-y-4" novalidate>
    @csrf
    <div class="flex items-center justify-between">
        <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">Prestar a {{ $contacto->nombreCompleto() }}</h2>
        <button type="button" x-on:click="$dispatch('close-modal', 'deuda-modal')" class="text-gray-400 hover:text-gray-500">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
        </button>
    </div>
    <x-floating-input id="descripcion" label="¿Qué le prestaste o compraste? (ej. Camiseta Def Leppard)" maxlength="200" required />
    <div class="grid gap-4 sm:grid-cols-2">
        <x-floating-money id="monto" label="Monto" :symbol="$simbolo" required />
        <x-floating-date id="fecha" label="Fecha" :value="now(auth()->user()->zona_horaria)" :max="now(auth()->user()->zona_horaria)" required />
    </div>
    <x-floating-select id="origen" label="¿De dónde salió el dinero?" :allowEmpty="false" :options="$origenes" value="efectivo" required />
    <p class="text-xs text-gray-500 dark:text-gray-400">Si sale del efectivo, una cuenta o una tarjeta, se registra ese gasto (categoría «Préstamos a terceros»). «Solo anotar» sirve para deudas que ya estaban registradas o de ciclos anteriores.</p>
    @include('hogar._quien', ['label' => '¿Quién prestó?'])
    <x-floating-input id="comentario" label="Comentario (opcional)" maxlength="1000" />
    <div class="flex justify-end gap-3">
        <x-secondary-button type="button" x-on:click="$dispatch('close-modal', 'deuda-modal')">Cancelar</x-secondary-button>
        <x-primary-button type="submit" x-bind:disabled="loading">Registrar</x-primary-button>
    </div>
</form>
