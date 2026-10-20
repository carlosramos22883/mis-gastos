@php($simbolo = auth()->user()->monedaPreferida?->simbolo ?? '$')
<form x-data="ajaxForm(async function(data) { $dispatch('close-modal', 'pago-modal'); await showAlert('success', '¡Éxito!', data.message); window.dispatchEvent(new CustomEvent('refresh-table', { detail: data })); })"
    @submit.prevent="submit" method="POST" action="{{ route('prestamos.pagos.store', $prestamo) }}" class="space-y-4" novalidate>
    @csrf
    <div class="flex items-center justify-between">
        <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">Pago de {{ $prestamo->nombre }}</h2>
        <button type="button" x-on:click="$dispatch('close-modal', 'pago-modal')" class="text-gray-400 hover:text-gray-500">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
        </button>
    </div>
    <p class="rounded-lg bg-primary-50 px-4 py-3 text-sm text-primary-700 dark:bg-gray-700 dark:text-primary-300">
        Saldo: <strong>{{ $simbolo }} {{ number_format($saldo, 2) }}</strong>. El desglose está calculado a hoy; ajústalo con lo que diga tu banco.
        Para abonar extra, súbelo al capital.
        @if ((float) $prestamo->monto_planilla > 0)
            <br>De este total, <strong>{{ $simbolo }} {{ number_format((float) $prestamo->monto_planilla, 2) }}</strong> se descuentan de planilla: el egreso será solo el resto.
        @endif
    </p>
    <div x-data="{ capital: @js(number_format($sugerencia['capital'], 2, '.', '')), interes: @js(number_format($sugerencia['interes'], 2, '.', '')), cargos: @js(number_format($sugerencia['cargos'], 2, '.', '')), mora: '0.00',
            get total() { return [this.capital, this.interes, this.cargos, this.mora].reduce((s, v) => s + (parseFloat(String(v).replace(/,/g, '')) || 0), 0).toFixed(2) } }" class="space-y-4">
        <div class="grid gap-4 sm:grid-cols-2">
            <x-floating-date id="fecha" label="Fecha" :value="now(auth()->user()->zona_horaria)" :max="now(auth()->user()->zona_horaria)" required />
            <x-floating-select id="origen" label="¿De dónde sale?" :allowEmpty="false" :options="$origenes" :value="$origen" required />
        </div>
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
            @foreach (['capital' => 'Capital', 'interes' => 'Interés', 'cargos' => 'Seguros y cargos', 'mora' => 'Mora'] as $campo => $etiqueta)
                <label class="text-xs text-gray-600 dark:text-gray-400">{{ $etiqueta }}
                    <input type="text" inputmode="decimal" name="{{ $campo }}" x-model="{{ $campo }}" class="mt-1 w-full rounded-md border-gray-300 text-right text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                    <span class="text-xs text-red-600" x-text="errors['{{ $campo }}']?.[0]"></span>
                </label>
            @endforeach
        </div>
        <p class="text-right text-sm text-gray-700 dark:text-gray-300">Total: <strong>{{ $simbolo }} <span x-text="total"></span></strong></p>
    </div>
    @include('hogar._quien', ['label' => '¿Quién paga?'])
    <x-floating-input id="comentario" label="Comentario (opcional)" maxlength="1000" />
    <div class="flex justify-end gap-3">
        <x-secondary-button type="button" x-on:click="$dispatch('close-modal', 'pago-modal')">Cancelar</x-secondary-button>
        <x-primary-button type="submit" x-bind:disabled="loading">Registrar pago</x-primary-button>
    </div>
</form>
