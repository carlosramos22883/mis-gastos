@php
    $editing = isset($movimiento);
    $simbolo = auth()->user()->monedaPreferida?->simbolo ?? '$';
    $tipos = $tipo === 'compra' ? ['compra' => 'Compra'] : ['cargo' => 'Cargo (intereses, seguro, comisión)', 'credito' => 'Crédito (devolución o promoción)'];
@endphp
<form x-data="ajaxForm(async function(data) { $dispatch('close-modal', 'movimiento-modal'); await showAlert('success', '¡Éxito!', data.message); window.dispatchEvent(new CustomEvent('refresh-table', { detail: data })); })"
    @submit.prevent="submit" method="POST" action="{{ $editing ? route('tarjetas.movimientos.update', [$tarjeta, $movimiento]) : route('tarjetas.movimientos.store', $tarjeta) }}" class="space-y-4" novalidate>
    @csrf @if ($editing) @method('PUT') @endif
    <div class="flex items-center justify-between">
        <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">{{ $editing ? 'Editar movimiento' : ($tipo === 'compra' ? 'Registrar compra' : 'Cargo o crédito') }} · {{ $tarjeta->nombre }}</h2>
        <button type="button" x-on:click="$dispatch('close-modal', 'movimiento-modal')" class="text-gray-400 hover:text-gray-500">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
        </button>
    </div>
    <p class="text-sm text-gray-600 dark:text-gray-300">Disponible: <strong>{{ $simbolo }} {{ number_format($disponible, 2) }}</strong> · Período sin facturar desde el {{ $desde->format('d/m/Y') }}</p>
    @if (count($tipos) > 1)
        <x-floating-select id="tipo" label="Tipo" :allowEmpty="false" :options="$tipos" :value="$movimiento->tipo ?? 'cargo'" required />
    @else
        <input type="hidden" name="tipo" value="compra">
    @endif
    <x-floating-input id="descripcion" label="Descripción" maxlength="255" :value="$movimiento->descripcion ?? ''" required />
    @include('hogar._quien', ['valor' => $movimiento->user_id ?? null])
    <div class="grid gap-4 sm:grid-cols-2">
        <x-floating-money id="monto" label="Monto total" :symbol="$simbolo" :value="$movimiento->monto ?? ''" required />
        <x-floating-date id="fecha" label="Fecha" :value="$movimiento->fecha ?? now(auth()->user()->zona_horaria)" :min="$desde" required />
    </div>
    @if ($tipo === 'compra')
        <div class="space-y-1">
            <x-floating-select id="cuotas" label="Forma de pago" :allowEmpty="false"
                :options="collect([1 => 'De contado (un solo corte)'])->union(collect([2, 3, 4, 6, 9, 10, 12, 15, 18, 24, 36, 48])->mapWithKeys(fn ($n) => [$n => $n.' cuotas a tasa cero']))->all()"
                :value="$movimiento->cuotas ?? 1" />
            <p class="text-xs text-gray-500 dark:text-gray-400">En cuotas se descuenta el total del disponible y se factura una cuota en cada corte.</p>
        </div>
    @endif
    <x-floating-select id="categoria_personal_id" label="Categoría (opcional)" :options="$categorias" :value="$movimiento->categoria_personal_id ?? ''" />
    <x-floating-input id="comentario" label="Comentario (opcional)" maxlength="1000" :value="$movimiento->comentario ?? ''" />
    <div class="flex justify-end gap-3">
        <x-secondary-button type="button" x-on:click="$dispatch('close-modal', 'movimiento-modal')">Cancelar</x-secondary-button>
        <x-primary-button type="submit" x-bind:disabled="loading">Guardar</x-primary-button>
    </div>
</form>
