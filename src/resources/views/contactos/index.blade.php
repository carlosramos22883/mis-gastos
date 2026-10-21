@php($simbolo = auth()->user()->monedaPreferida?->simbolo ?? '$')
<x-app-layout>
    <x-slot name="header"><h1>{{ $filtro === 'deben' ? 'Nos deben' : 'Contactos' }}</h1></x-slot>
    <div class="max-w-7xl mx-auto space-y-6" x-data x-on:refresh-table.window="$event.detail?.redirect ? window.location = $event.detail.redirect : window.location.reload()">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div class="flex flex-wrap items-center gap-6">
                <div class="inline-flex rounded-lg bg-gray-100 p-1 text-sm dark:bg-gray-700">
                    <a href="{{ route('contactos.index') }}" class="rounded-md px-3 py-1.5 {{ $filtro === 'todos' ? 'bg-white font-semibold text-gray-900 shadow dark:bg-gray-800 dark:text-gray-100' : 'text-gray-600 dark:text-gray-300' }}">Todos</a>
                    <a href="{{ route('contactos.index', ['filtro' => 'deben']) }}" class="rounded-md px-3 py-1.5 {{ $filtro === 'deben' ? 'bg-white font-semibold text-gray-900 shadow dark:bg-gray-800 dark:text-gray-100' : 'text-gray-600 dark:text-gray-300' }}">Nos deben</a>
                </div>
                <div>
                    <p class="text-sm text-gray-500 dark:text-gray-400">Pendiente de cobrar</p>
                    <p class="text-2xl font-bold text-primary-600 dark:text-primary-400">{{ $simbolo }} {{ number_format($contactos->sum('pendiente'), 2) }}</p>
                </div>
            </div>
            @can('contactos.create')
                <x-primary-button type="button" x-on:click="$dispatch('load-contacto-modal-form', '{{ route('contactos.create') }}'); $dispatch('open-modal', 'contacto-modal')">Nuevo contacto</x-primary-button>
            @endcan
        </div>
        @if ($contactos->isEmpty())
            <div class="rounded-lg bg-white p-10 text-center shadow dark:bg-gray-800">
                <p class="text-gray-600 dark:text-gray-300">{{ $filtro === 'deben' ? 'Nadie te debe… por ahora.' : 'Aún no tienes contactos.' }}</p>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Personas, tiendas, empresas o instituciones con las que tienes compromisos, deudas o pagos. También se crean solos al escribir un nombre nuevo en «¿Con quién?».</p>
            </div>
        @else
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($contactos as $c)
                    <a href="{{ route('contactos.show', $c) }}" class="flex items-center gap-5 rounded-xl bg-white p-5 shadow hover:bg-gray-50 dark:bg-gray-800 dark:hover:bg-gray-700/40 {{ $c->activo ? '' : 'opacity-60' }}" data-record-id="{{ $c->id }}">
                        <x-avatar-contacto :contacto="$c" size="lg" class="ring-2 ring-white shadow-md dark:ring-gray-700" />
                        <div class="min-w-0 flex-1">
                            <p class="line-clamp-2 text-lg font-semibold leading-snug text-gray-900 dark:text-gray-100" title="{{ $c->nombreCompleto() }}">{{ $c->nombreCompleto() }}</p>
                            <p class="truncate text-sm text-gray-500 dark:text-gray-400">{{ $c->tipoContacto?->nombre }}{{ $c->telefono ? ' · '.$c->telefono : '' }}{{ $c->activo ? '' : ' · inactivo' }}</p>
                        </div>
                        @if ($c->pendiente > 0)
                            <div class="shrink-0 text-right">
                                <p class="text-xs text-gray-500 dark:text-gray-400">Te debe</p>
                                <p class="font-semibold text-gray-900 dark:text-gray-100">{{ $simbolo }} {{ number_format($c->pendiente, 2) }}</p>
                            </div>
                        @endif
                        @if ($c->le_debes > 0)
                            <div class="shrink-0 text-right">
                                <p class="text-xs text-gray-500 dark:text-gray-400">Le debes</p>
                                <p class="font-semibold text-red-600 dark:text-red-400">{{ $simbolo }} {{ number_format($c->le_debes, 2) }}</p>
                            </div>
                        @endif
                    </a>
                @endforeach
            </div>
        @endif
    </div>
    <x-ajax-modal name="contacto" />
</x-app-layout>
