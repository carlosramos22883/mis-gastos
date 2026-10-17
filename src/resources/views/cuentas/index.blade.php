@php($simbolo = auth()->user()->monedaPreferida?->simbolo ?? '$')
<x-app-layout>
    <x-slot name="header"><h1>Cuentas y billeteras</h1></x-slot>
    <div class="max-w-7xl mx-auto space-y-6" x-data x-on:refresh-table.window="window.location.reload()">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <p class="text-sm text-gray-500 dark:text-gray-400">Saldo total en cuentas activas</p>
                <p class="text-3xl font-bold text-primary-600 dark:text-primary-400">{{ $simbolo }} {{ number_format($cuentas->where('activo', true)->sum('saldo_actual'), 2) }}</p>
            </div>
            <div class="flex gap-2">
                @can('cuentas.create')
                    @if ($cuentas->where('activo', true)->isNotEmpty())
                        <x-secondary-button type="button" x-on:click="$dispatch('load-transferencia-modal-form', '{{ route('transferencias.create') }}'); $dispatch('open-modal', 'transferencia-modal')">Transferir</x-secondary-button>
                    @endif
                    <x-primary-button type="button" x-on:click="$dispatch('load-cuenta-modal-form', '{{ route('cuentas.create') }}'); $dispatch('open-modal', 'cuenta-modal')">Nueva cuenta</x-primary-button>
                @endcan
            </div>
        </div>
        @include('cuentas._grid')
    </div>
    <x-ajax-modal name="cuenta" />
    <x-ajax-modal name="transferencia" />
</x-app-layout>
