@php($simbolo = auth()->user()->monedaPreferida?->simbolo ?? '$')
<x-app-layout>
    <x-slot name="header"><h1>Nos deben</h1></x-slot>
    <div class="max-w-7xl mx-auto space-y-6" x-data x-on:refresh-table.window="$event.detail?.redirect ? window.location = $event.detail.redirect : window.location.reload()">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <p class="text-sm text-gray-500 dark:text-gray-400">Total pendiente de cobrar</p>
                <p class="text-3xl font-bold text-primary-600 dark:text-primary-400">{{ $simbolo }} {{ number_format($deudores->sum('pendiente'), 2) }}</p>
            </div>
            @can('deudores.create')
                <x-primary-button type="button" x-on:click="$dispatch('load-deudor-modal-form', '{{ route('deudores.create') }}'); $dispatch('open-modal', 'deudor-modal')">Nuevo deudor</x-primary-button>
            @endcan
        </div>
        @if ($deudores->isEmpty())
            <div class="rounded-lg bg-white p-10 text-center shadow dark:bg-gray-800">
                <p class="text-gray-600 dark:text-gray-300">Nadie te debe… por ahora.</p>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Registra a quien le prestas dinero o le compras algo a plazos, y lleva el control de lo que te devuelve.</p>
            </div>
        @else
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($deudores as $deudor)
                    <a href="{{ route('deudores.show', $deudor) }}" class="block rounded-lg bg-white p-5 shadow hover:bg-gray-50 dark:bg-gray-800 dark:hover:bg-gray-700/40 {{ $deudor->activo ? '' : 'opacity-60' }}" data-record-id="{{ $deudor->id }}">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p class="truncate font-semibold text-gray-900 dark:text-gray-100">{{ $deudor->nombre }}</p>
                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ \App\Models\Deudor::TIPOS[$deudor->tipo] ?? $deudor->tipo }} · {{ $deudor->deudas_abiertas }} {{ $deudor->deudas_abiertas === 1 ? 'deuda abierta' : 'deudas abiertas' }}</p>
                            </div>
                            @unless ($deudor->activo)
                                <span class="shrink-0 rounded-md bg-gray-100 px-2 py-0.5 text-xs text-gray-600 dark:bg-gray-700 dark:text-gray-300">Inactivo</span>
                            @endunless
                        </div>
                        <p class="mt-4 text-2xl font-bold {{ $deudor->pendiente > 0 ? 'text-gray-900 dark:text-gray-100' : 'text-gray-400' }}">{{ $simbolo }} {{ number_format($deudor->pendiente, 2) }}</p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">de {{ $simbolo }} {{ number_format($deudor->prestado, 2) }} prestados</p>
                    </a>
                @endforeach
            </div>
        @endif
    </div>
    <x-ajax-modal name="deudor" />
</x-app-layout>
