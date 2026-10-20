@php($simbolo = auth()->user()->monedaPreferida?->simbolo ?? '$')
@php($abiertas = $deudor->deudas->filter(fn ($d) => $d->pendiente() > 0))
<form x-data="ajaxForm(async function(data) { $dispatch('close-modal', 'pago-modal'); await showAlert('success', '¡Éxito!', data.message); window.dispatchEvent(new CustomEvent('refresh-table', { detail: data })); })"
    @submit.prevent="submit" method="POST" action="{{ route('deudores.pagos.store', $deudor) }}" class="space-y-4" novalidate>
    @csrf
    <div class="flex items-center justify-between">
        <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">Pago de {{ $deudor->nombre }}</h2>
        <button type="button" x-on:click="$dispatch('close-modal', 'pago-modal')" class="text-gray-400 hover:text-gray-500">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
        </button>
    </div>
    <p class="rounded-lg bg-primary-50 px-4 py-3 text-sm text-primary-700 dark:bg-gray-700 dark:text-primary-300">
        Te debe <strong>{{ $simbolo }} {{ number_format($deudor->pendiente, 2) }}</strong>. Si no eliges una deuda, el pago se aplica de la más antigua a la más reciente.
    </p>
    <div class="grid gap-4 sm:grid-cols-2">
        <x-floating-money id="monto" label="Monto" :symbol="$simbolo" :value="$deudor->pendiente" required />
        <x-floating-date id="fecha" label="Fecha" :value="now(auth()->user()->zona_horaria)" :max="now(auth()->user()->zona_horaria)" required />
    </div>
    <x-floating-select id="destino" label="¿A dónde entró el dinero?" :allowEmpty="false" :options="$destinos" value="efectivo" required />
    <x-floating-select id="deuda_id" label="Aplicar a (opcional)" :options="$abiertas->mapWithKeys(fn ($d) => [$d->id => $d->descripcion.' · '.$simbolo.' '.number_format($d->pendiente(), 2)])->all()" />
    @include('hogar._quien', ['label' => '¿Quién recibió?'])
    <x-floating-input id="comentario" label="Comentario (opcional)" maxlength="1000" />
    <div class="flex justify-end gap-3">
        <x-secondary-button type="button" x-on:click="$dispatch('close-modal', 'pago-modal')">Cancelar</x-secondary-button>
        <x-primary-button type="submit" x-bind:disabled="loading">Registrar pago</x-primary-button>
    </div>
</form>
