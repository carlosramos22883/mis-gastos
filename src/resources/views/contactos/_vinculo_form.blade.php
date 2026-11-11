{{-- "Me lo debe": liga un gasto ya registrado (efectivo, cuenta o tarjeta) a una deuda nueva de un contacto --}}
@php($simbolo = auth()->user()->monedaPreferida?->simbolo ?? '$')
<form x-data="ajaxForm(async function(data) { $dispatch('close-modal', 'deuda-modal'); await showAlert('success', '¡Éxito!', data.message); window.dispatchEvent(new CustomEvent('refresh-table', { detail: data })); })"
    @submit.prevent="submit" method="POST" action="{{ route('movimientos.deuda.store', [$clase, $movimiento->id]) }}" class="space-y-4" novalidate>
    @csrf
    <div class="flex items-center justify-between">
        <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">Me lo debe</h2>
        <button type="button" x-on:click="$dispatch('close-modal', 'deuda-modal')" class="text-gray-400 hover:text-gray-500">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
        </button>
    </div>
    <p class="text-sm text-gray-600 dark:text-gray-400">{{ $movimiento->fecha->format('d/m/Y') }} · {{ $movimiento->descripcion }} · {{ $simbolo }} {{ number_format((float) $movimiento->monto, 2) }}</p>
    @include('contactos._quien', ['valor' => $movimiento->contacto_id, 'label' => '¿Quién te lo debe?'])
    <x-floating-input id="descripcion" label="Descripción de la deuda" maxlength="200" :value="$movimiento->descripcion" required />
    <x-floating-money id="monto" label="¿Cuánto te debe?" :symbol="$simbolo" :value="$movimiento->monto" required />
    <p class="text-xs text-gray-500 dark:text-gray-400">Por el monto completo, el gasto pasa a la categoría «Préstamos a terceros» (deja de contar como gasto tuyo); por una parte, conserva su categoría. Desde ahora se modifica desde el contacto, y si eliminas la deuda el gasto se queda.</p>
    <x-floating-input id="comentario" label="Comentario (opcional)" maxlength="1000" />
    <div class="flex justify-end gap-3">
        <x-secondary-button type="button" x-on:click="$dispatch('close-modal', 'deuda-modal')">Cancelar</x-secondary-button>
        <x-primary-button type="submit" x-bind:disabled="loading">Guardar</x-primary-button>
    </div>
</form>
