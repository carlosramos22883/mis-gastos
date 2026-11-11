@php($simbolo = auth()->user()->monedaPreferida?->simbolo ?? '$')
@php($pagador = $evento->pagador())
<form x-data="ajaxForm(async function(data) { $dispatch('close-modal', 'cobro-modal'); await showAlert('success', '¡Éxito!', data.message); window.dispatchEvent(new CustomEvent('refresh-table', { detail: data })); })"
    @submit.prevent="submit" method="POST" action="{{ route('eventos.pagado.store', [$evento, $participante]) }}" class="space-y-4" novalidate>
    @csrf
    <div class="flex items-center justify-between">
        <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">{{ $participante->nombre() }} ya pagó</h2>
        <button type="button" x-on:click="$dispatch('close-modal', 'cobro-modal')" class="text-gray-400 hover:text-gray-500">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
        </button>
    </div>
    <p class="rounded-lg bg-primary-50 px-4 py-3 text-sm text-primary-700 dark:bg-gray-700 dark:text-primary-300">
        Su parte es <strong>{{ $simbolo }} {{ number_format($monto, 2) }}</strong> y se la pagó a <strong>{{ $pagador?->nombre() }}</strong>.
    </p>
    <x-floating-date id="fecha" label="Fecha" :value="now(auth()->user()->zona_horaria)" :max="now(auth()->user()->zona_horaria)" required />
    @if ($participante->user_id)
        {{-- Un miembro del hogar: su parte es un gasto nuestro --}}
        <x-floating-select id="origen" label="¿De dónde salió el dinero?" :allowEmpty="false" :options="$origenes" value="efectivo" />
        <x-floating-select id="categoria_personal_id" label="Categoría del gasto" :options="$categorias" />
    @endif
    <div class="flex justify-end gap-3">
        <x-secondary-button type="button" x-on:click="$dispatch('close-modal', 'cobro-modal')">Cancelar</x-secondary-button>
        <x-primary-button type="submit" x-bind:disabled="loading">Marcar como pagado</x-primary-button>
    </div>
</form>
