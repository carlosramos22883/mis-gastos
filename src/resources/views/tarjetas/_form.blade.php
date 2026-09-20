@php($editing = isset($tarjeta))
<form x-data="ajaxForm(async function(data) { $dispatch('close-modal', 'tarjeta-modal'); await showAlert('success', '¡Éxito!', data.message); window.dispatchEvent(new CustomEvent('refresh-table', { detail: data })); })" @submit.prevent="submit" method="post" action="{{ $editing ? route('tarjetas.update', $tarjeta) : route('tarjetas.store') }}" class="space-y-4" novalidate>
    @csrf @if($editing) @method('PUT') @endif
    <div class="flex items-center justify-between mb-6">
        <h2 class="text-lg font-medium">{{ $editing ? 'Editar tarjeta de crédito' : 'Nueva tarjeta de crédito' }}</h2>
        <button type="button" x-on:click="$dispatch('close-modal', 'tarjeta-modal')" class="text-gray-400"><svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg></button>
    </div>
    <x-floating-input id="nombre" name="nombre" label="Nombre de la tarjeta" maxlength="100" :value="$tarjeta->nombre ?? ''" required />
    <div class="grid gap-4 md:grid-cols-2">
        <x-floating-select id="banco_id" name="banco_id" label="Banco emisor" :options="$bancos" :value="$tarjeta->banco_id ?? ''" required />
        <x-floating-select id="marca_red_id" name="marca_red_id" label="Marca de red" :options="$marcas" :value="$tarjeta->marca_red_id ?? ''" required />
    </div>
    <div class="grid gap-4 md:grid-cols-2">
        <x-floating-input id="ultimos_digitos" name="ultimos_digitos" label="Últimos 4 dígitos" maxlength="4" inputmode="numeric" pattern="\d{4}" oninput="this.value = this.value.replace(/\D/g, '').slice(0, 4)" />
        <x-floating-money id="limite_credito" name="limite_credito" label="Límite de crédito" :value="$tarjeta->limite_credito ?? '0.00'" :symbol="auth()->user()->monedaPreferida?->simbolo ?? '$'" required />
    </div>
    <x-floating-money id="saldo_actual" label="Saldo actual" :value="$tarjeta->saldo_actual ?? '0.00'" :symbol="auth()->user()->monedaPreferida?->simbolo ?? '$'" required />
    <div class="grid gap-4 md:grid-cols-2">
        <x-floating-input id="dia_corte" name="dia_corte" label="Día de corte" type="number" min="1" max="31" step="1" inputmode="numeric" :value="$tarjeta->dia_corte ?? ''" required />
        <x-floating-input id="dia_pago" name="dia_pago" label="Día límite de pago" type="number" min="1" max="31" step="1" inputmode="numeric" :value="$tarjeta->dia_pago ?? ''" required />
    </div>
    <div class="flex items-center gap-2">
        <input type="hidden" name="activo" value="0">
        <input type="checkbox" name="activo" value="1" {{ ($tarjeta->activo ?? true) ? 'checked' : '' }} class="h-4 w-4 rounded border-gray-300 text-primary-600">
        <span class="text-sm">Tarjeta activa</span>
    </div>
    <div class="flex justify-end gap-3 pt-4">
        <x-secondary-button type="button" x-on:click="$dispatch('close-modal', 'tarjeta-modal')">Cancelar</x-secondary-button>
        <x-primary-button type="submit">Guardar</x-primary-button>
    </div>
</form>
