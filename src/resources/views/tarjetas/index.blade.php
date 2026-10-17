@php($simbolo = auth()->user()->monedaPreferida?->simbolo ?? '$')
<x-app-layout>
    <x-slot name="header"><h1>Tarjetas de crédito</h1></x-slot>
    <div class="max-w-7xl mx-auto space-y-6" x-data x-on:refresh-table.window="$event.detail?.redirect ? window.location = $event.detail.redirect : window.location.reload()">
        @php($activas = $tarjetas->where('activo', true))
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div class="flex flex-wrap gap-8">
                <div>
                    <p class="text-sm text-gray-500 dark:text-gray-400">Disponible total</p>
                    <p class="text-3xl font-bold text-primary-600 dark:text-primary-400">{{ $simbolo }} {{ number_format($activas->sum('disponible'), 2) }}</p>
                </div>
                <div>
                    <p class="text-sm text-gray-500 dark:text-gray-400">Deuda total</p>
                    <p class="text-3xl font-bold text-red-600 dark:text-red-400">{{ $simbolo }} {{ number_format($tarjetas->sum('deuda'), 2) }}</p>
                </div>
            </div>
            <div class="flex gap-2">
                @can('tarjetas.export')
                    <x-secondary-button type="button" onclick="window.location='{{ route('tarjetas.export', ['format' => 'xlsx']) }}'">Exportar</x-secondary-button>
                @endcan
                @can('tarjetas.create')
                    <x-primary-button type="button" x-on:click="$dispatch('load-tarjeta-modal-form', '{{ route('tarjetas.create') }}'); $dispatch('open-modal', 'tarjeta-modal')">Nueva tarjeta</x-primary-button>
                @endcan
            </div>
        </div>

        @if ($tarjetas->isEmpty())
            <div class="rounded-lg bg-white p-10 text-center shadow dark:bg-gray-800">
                <p class="text-gray-600 dark:text-gray-300">Aún no tienes tarjetas registradas.</p>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Regístrala con su límite, su disponible de hoy y, si lo tienes, el estado de cuenta pendiente de pago.</p>
            </div>
        @else
            <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($tarjetas as $tarjeta)
                    @include('tarjetas._tarjeta', ['enlace' => true])
                @endforeach
            </div>
        @endif
    </div>
    <x-ajax-modal name="tarjeta" />
</x-app-layout>
