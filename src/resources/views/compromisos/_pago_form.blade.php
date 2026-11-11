@php($simbolo = auth()->user()->monedaPreferida?->simbolo ?? '$')
<form x-data="ajaxForm(async function(data) { $dispatch('close-modal', 'pago-modal'); await showAlert('success', '¡Éxito!', data.message); window.dispatchEvent(new CustomEvent('refresh-table', { detail: data })); })"
    @submit.prevent="submit" method="POST" action="{{ route('compromisos.pagar.store', $compromiso) }}" class="space-y-4" novalidate>
    @csrf
    <div class="flex items-center justify-between">
        <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">Pagar compromiso</h2>
        <button type="button" x-on:click="$dispatch('close-modal', 'pago-modal')" class="text-gray-400 hover:text-gray-500">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
        </button>
    </div>
    <p class="rounded-lg bg-primary-50 px-4 py-3 text-sm text-primary-700 dark:bg-gray-700 dark:text-primary-300">
        <strong>{{ $compromiso->descripcion }}</strong> · Pendiente: <strong>{{ $simbolo }} {{ number_format((float) $compromiso->saldo_pendiente, 2) }}</strong>.
        Puedes pagarlo completo o hacer un abono.
    </p>
    <x-floating-select id="origen" label="Pagar desde" :allowEmpty="false" :options="$origenes" :value="$origen" required />
    @if (collect(array_keys($origenes))->contains(fn ($o) => str_starts_with($o, 'tarjeta:')))
        <p class="-mt-2 text-xs text-gray-500 dark:text-gray-400">También puedes pagar con otra tarjeta de crédito (por ejemplo, con el NPE del estado de cuenta): queda como una compra en esa tarjeta y baja su disponible.</p>
    @endif
    <x-floating-money id="monto" label="Monto" :symbol="$simbolo" :value="$compromiso->saldo_pendiente" required />
    @if ($compromiso->tarjeta_credito_id)
        <p class="-mt-2 text-xs text-gray-500 dark:text-gray-400">¿Pagaste de más? Escribe lo que pagaste: lo que pase del pendiente queda como crédito en la tarjeta (sube su disponible y se descuenta del próximo corte).</p>
    @endif
    <x-floating-date id="fecha" label="Fecha" :value="now(auth()->user()->zona_horaria)" :min="$cycleStart" :max="min(now(auth()->user()->zona_horaria), $cycleEnd)" required />
    <x-floating-input id="descripcion" label="Descripción" maxlength="255" :value="$compromiso->descripcion" required />
    @include('hogar._quien', ['label' => '¿Quién paga?'])
    @unless ($compromiso->tarjeta_credito_id)
        <x-floating-select id="categoria_personal_id" label="Categoría" :options="$categorias" :value="$compromiso->categoria_personal_id" required />
    @endunless
    <div class="flex justify-end gap-3">
        <x-secondary-button type="button" x-on:click="$dispatch('close-modal', 'pago-modal')">Cancelar</x-secondary-button>
        <x-primary-button type="submit" x-bind:disabled="loading">Registrar pago</x-primary-button>
    </div>
</form>
