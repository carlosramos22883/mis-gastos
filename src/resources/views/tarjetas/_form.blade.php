@php
    $editing = isset($tarjeta);
    $simbolo = auth()->user()->monedaPreferida?->simbolo ?? '$';
    $dias = collect(range(1, 30))->mapWithKeys(fn ($d) => [$d => "Día {$d}"])->put(31, 'Último día del mes')->all();
@endphp
<form x-data="ajaxForm(async function(data) { $dispatch('close-modal', 'tarjeta-modal'); await showAlert('success', '¡Éxito!', data.message); window.dispatchEvent(new CustomEvent('refresh-table', { detail: data })); })"
    @submit.prevent="submit" method="POST" action="{{ $editing ? route('tarjetas.update', $tarjeta) : route('tarjetas.store') }}" class="space-y-4" novalidate>
    @csrf @if ($editing) @method('PUT') @endif
    <div class="flex items-center justify-between">
        <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">{{ $editing ? 'Editar tarjeta' : 'Nueva tarjeta de crédito' }}</h2>
        <button type="button" x-on:click="$dispatch('close-modal', 'tarjeta-modal')" class="text-gray-400 hover:text-gray-500">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
        </button>
    </div>
    <x-floating-input id="nombre" label="Nombre o alias (ej. BAC Walmart Carlos)" maxlength="100" :value="$tarjeta->nombre ?? ''" required />
    @include('hogar._quien', ['valor' => $tarjeta->user_id ?? null, 'label' => 'Titular'])
    <div class="grid gap-4 sm:grid-cols-2">
        <x-floating-select id="banco_id" label="Banco emisor" :options="$bancos" :value="$tarjeta->banco_id ?? ''" required />
        <x-floating-select id="marca_red_id" label="Marca" :options="$marcas" :value="$tarjeta->marca_red_id ?? ''" required />
    </div>
    <div class="grid gap-4 sm:grid-cols-2">
        <x-floating-input id="ultimos_digitos" label="Últimos 4 dígitos (opcional)" maxlength="4" inputmode="numeric" :value="$tarjeta->ultimos_digitos ?? ''" oninput="this.value = this.value.replace(/\D/g, '').slice(0, 4)" />
        <x-floating-money id="limite_credito" label="Límite de crédito" :symbol="$simbolo" :value="$tarjeta->limite_credito ?? ''" required />
    </div>
    <div class="grid gap-4 sm:grid-cols-2">
        <x-floating-select id="dia_corte" label="Día de corte" :allowEmpty="false" :options="$dias" :value="$tarjeta->dia_corte ?? 15" required />
        <x-floating-select id="dia_pago" label="Día límite de pago" :allowEmpty="false" :options="$dias" :value="$tarjeta->dia_pago ?? 5" required />
    </div>
    <x-floating-input id="tasa_interes" label="Tasa de interés anual % (opcional)" type="number" step="0.01" min="0" :value="$tarjeta->tasa_interes ?? ''" />

    @unless ($editing)
        <div x-data="{ pendiente: false }" class="space-y-4 rounded-lg border border-gray-200 p-4 dark:border-gray-700">
            <p class="text-sm font-medium text-gray-800 dark:text-gray-200">¿Cómo está la tarjeta hoy?</p>
            <x-floating-money id="disponible" label="Disponible actual" :symbol="$simbolo" required />
            <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                <input type="checkbox" name="tiene_pendiente" value="1" x-model="pendiente" class="rounded border-gray-300 text-primary-600 focus:ring-primary-500 dark:border-gray-600">
                Tengo un estado de cuenta pendiente de pago
            </label>
            <div x-show="pendiente" x-collapse class="grid gap-4 sm:grid-cols-2">
                <x-floating-money id="pendiente_monto" label="Saldo del estado de cuenta" :symbol="$simbolo" />
                <x-floating-date id="pendiente_fecha" label="Fecha límite de pago" :max="now()->addYear()" />
            </div>
            <p class="text-xs text-gray-500 dark:text-gray-400">El saldo pendiente se convierte en un compromiso de pago. Lo demás que hayas usado entra en el próximo corte.</p>
        </div>
    @endunless

    <div class="flex items-center gap-6">
        <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
            Color <input type="color" name="color" value="{{ $tarjeta->color ?? '#1E293B' }}" class="h-8 w-12 cursor-pointer rounded border border-gray-300 dark:border-gray-600">
        </label>
        <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
            <input type="checkbox" name="activo" value="1" @checked($tarjeta->activo ?? true) class="rounded border-gray-300 text-primary-600 focus:ring-primary-500 dark:border-gray-600"> Activa
        </label>
    </div>
    <div class="flex justify-end gap-3">
        <x-secondary-button type="button" x-on:click="$dispatch('close-modal', 'tarjeta-modal')">Cancelar</x-secondary-button>
        <x-primary-button type="submit" x-bind:disabled="loading">Guardar</x-primary-button>
    </div>
</form>
