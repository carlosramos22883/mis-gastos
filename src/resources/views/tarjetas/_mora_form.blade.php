<form x-data="ajaxForm(async function(data) { $dispatch('close-modal', 'mora-modal'); await showAlert('success', '¡Listo!', data.message); window.dispatchEvent(new CustomEvent('refresh-table', { detail: data })); })"
    @submit.prevent="submit" method="POST" action="{{ route('estados-cuenta.mora.store', $estado) }}" class="space-y-4" novalidate>
    @csrf
    <div class="flex items-center justify-between">
        <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">Registrar mora</h2>
        <button type="button" x-on:click="$dispatch('close-modal', 'mora-modal')" class="text-gray-400 hover:text-gray-500">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
        </button>
    </div>
    <p class="text-sm text-gray-600 dark:text-gray-300">El estado de cuenta del {{ $estado->fecha_corte->format('d/m/Y') }} venció el {{ $estado->fecha_limite_pago->format('d/m/Y') }}. Escribe el monto de la mora que te cobró el banco: se suma a la deuda de la tarjeta y se crea un compromiso para pagarla.</p>
    <x-floating-input id="descripcion" label="Descripción" maxlength="255" value="Mora de tarjeta de crédito · {{ $estado->tarjeta->nombre }}" required />
    <x-floating-money id="monto" label="Monto de la mora" :symbol="auth()->user()->monedaPreferida?->simbolo ?? '$'" required />
    <div class="flex justify-end gap-3">
        <x-secondary-button type="button" x-on:click="$dispatch('close-modal', 'mora-modal')">Cancelar</x-secondary-button>
        <x-danger-button type="submit" x-bind:disabled="loading">Registrar mora</x-danger-button>
    </div>
</form>
