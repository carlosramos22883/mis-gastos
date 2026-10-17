@php($editing = isset($cuenta))
<form x-data="ajaxForm(async function(data) { $dispatch('close-modal', 'cuenta-modal'); await showAlert('success', '¡Éxito!', data.message); window.dispatchEvent(new CustomEvent('refresh-table', { detail: data })); })"
    @submit.prevent="submit" method="POST" action="{{ $editing ? route('cuentas.update', $cuenta) : route('cuentas.store') }}" class="space-y-4" novalidate>
    @csrf @if ($editing) @method('PUT') @endif
    <div class="flex items-center justify-between">
        <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">{{ $editing ? 'Editar cuenta' : 'Nueva cuenta o billetera' }}</h2>
        <button type="button" x-on:click="$dispatch('close-modal', 'cuenta-modal')" class="text-gray-400 hover:text-gray-500">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
        </button>
    </div>
    <x-floating-input id="nombre" label="Nombre o alias (ej. BAC Carlos, Chivo)" maxlength="100" :value="$cuenta->nombre ?? ''" required />
    <div class="grid gap-4 sm:grid-cols-2">
        <x-floating-select id="tipo_cuenta_id" label="Tipo" :allowEmpty="false" :options="$tipos" :value="$cuenta->tipo_cuenta_id ?? array_key_first($tipos)" required />
        <x-floating-select id="banco_id" label="Banco (opcional)" :options="$bancos" :value="$cuenta->banco_id ?? ''" />
    </div>
    <x-floating-input id="numero_cuenta" label="Número de cuenta (opcional, se guarda encriptado)" maxlength="34" :value="$cuenta->numero_cuenta ?? ''" />
    @unless ($editing)
        <div class="grid gap-4 sm:grid-cols-2">
            <x-floating-money id="saldo_inicial" label="Saldo actual" :symbol="auth()->user()->monedaPreferida?->simbolo ?? '$'" value="0.00" required />
            <x-floating-date id="fecha_inicial" label="A la fecha" :value="now(auth()->user()->zona_horaria)" required />
        </div>
    @else
        <p class="text-xs text-gray-500 dark:text-gray-400">El saldo inicial no se modifica: si no cuadra, registra un ingreso o egreso de ajuste.</p>
    @endunless
    <div class="flex items-center gap-6">
        <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
            Color <input type="color" name="color" value="{{ $cuenta->color ?? '#0284C7' }}" class="h-8 w-12 cursor-pointer rounded border border-gray-300 dark:border-gray-600">
        </label>
        <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
            <input type="checkbox" name="activo" value="1" @checked($cuenta->activo ?? true) class="rounded border-gray-300 text-primary-600 focus:ring-primary-500 dark:border-gray-600"> Activa
        </label>
    </div>
    <div class="flex justify-end gap-3">
        <x-secondary-button type="button" x-on:click="$dispatch('close-modal', 'cuenta-modal')">Cancelar</x-secondary-button>
        <x-primary-button type="submit" x-bind:disabled="loading">Guardar</x-primary-button>
    </div>
</form>
