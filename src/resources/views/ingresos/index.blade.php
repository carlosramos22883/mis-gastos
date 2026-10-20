@php($simbolo = auth()->user()->monedaPreferida?->simbolo ?? '$')
<x-app-layout>
    <x-slot name="header"><h1>Ingresos</h1></x-slot>
    <div class="max-w-7xl mx-auto space-y-6" x-data x-on:refresh-table.window="$event.detail?.redirect ? window.location = $event.detail.redirect : window.location.reload()">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <p class="text-sm text-gray-500 dark:text-gray-400">Neto recibido este año</p>
                <p class="text-3xl font-bold text-primary-600 dark:text-primary-400">{{ $simbolo }} {{ number_format($fuentes->sum('neto_anio'), 2) }}</p>
            </div>
            @can('ingresos.create')
                <x-primary-button type="button" x-on:click="$dispatch('load-fuente-modal-form', '{{ route('ingresos.create') }}'); $dispatch('open-modal', 'fuente-modal')">Nueva fuente de ingreso</x-primary-button>
            @endcan
        </div>
        @if ($fuentes->isEmpty())
            <div class="rounded-lg bg-white p-10 text-center shadow dark:bg-gray-800">
                <p class="text-gray-600 dark:text-gray-300">Registra tu sueldo o tus servicios profesionales.</p>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Con su plantilla de descuentos (renta, AFP, ISSS, préstamos, comisiones) cada pago queda con su desglose y el neto entra solo a tu cuenta.</p>
            </div>
        @else
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($fuentes as $fuente)
                    <div class="relative overflow-hidden rounded-lg bg-white shadow dark:bg-gray-800 {{ $fuente->activo ? '' : 'opacity-60' }}" data-record-id="{{ $fuente->id }}">
                        <span class="absolute inset-y-0 left-0 w-1.5" style="background-color: {{ $fuente->color }}"></span>
                        <a href="{{ route('ingresos.show', $fuente) }}" class="block p-5 pl-6 hover:bg-gray-50 dark:hover:bg-gray-700/40">
                            <p class="truncate font-semibold text-gray-900 dark:text-gray-100">@include('hogar._por', ['id' => $fuente->user_id]){{ $fuente->nombre }}</p>
                            <p class="truncate text-xs text-gray-500 dark:text-gray-400">{{ \App\Models\FuenteIngreso::TIPOS[$fuente->tipo] ?? $fuente->tipo }}{{ $fuente->pagador ? ' · '.$fuente->pagador : '' }}</p>
                            <p class="mt-4 text-2xl font-bold text-gray-900 dark:text-gray-100">{{ $fuente->ultimo ? $simbolo.' '.number_format((float) $fuente->ultimo->neto, 2) : '—' }}</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                {{ $fuente->ultimo ? 'último neto ('.$fuente->ultimo->fecha->format('d/m/Y').')' : 'sin pagos registrados' }}
                                @if ($fuente->proxima) · próximo {{ $fuente->proxima->format('d/m') }} @endif
                            </p>
                        </a>
                        @can('ingresos.create')
                            @if ($fuente->activo)
                                <div class="border-t border-gray-100 px-4 py-2 text-right dark:border-gray-700">
                                    <button type="button" class="text-sm font-medium text-primary-600 hover:underline dark:text-primary-400" x-on:click="$dispatch('load-recibo-modal-form', '{{ route('ingresos.recibos.create', $fuente) }}'); $dispatch('open-modal', 'recibo-modal')">Registrar pago recibido</button>
                                </div>
                            @endif
                        @endcan
                    </div>
                @endforeach
            </div>
        @endif
    </div>
    <x-ajax-modal name="fuente" maxWidth="4xl" />
    <x-ajax-modal name="recibo" maxWidth="3xl" />
</x-app-layout>
