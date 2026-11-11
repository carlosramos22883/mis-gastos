@php($simbolo = auth()->user()->monedaPreferida?->simbolo ?? '$')
<x-app-layout>
    <x-slot name="header"><h1>Eventos</h1></x-slot>
    <div class="max-w-7xl mx-auto space-y-6">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <p class="text-sm text-gray-500 dark:text-gray-400">Te falta cobrar</p>
                <p class="text-3xl font-bold text-primary-600 dark:text-primary-400">{{ $simbolo }} {{ number_format($eventos->sum(fn ($e) => $e->resumen['por_cobrar']), 2) }}</p>
            </div>
            @can('eventos.create')
                <a href="{{ route('eventos.create') }}"><x-primary-button type="button">Nuevo evento</x-primary-button></a>
            @endcan
        </div>
        @if ($eventos->isEmpty())
            <div class="rounded-lg bg-white p-10 text-center shadow dark:bg-gray-800">
                <p class="text-gray-600 dark:text-gray-300">Aún no tienes eventos.</p>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">¿Salieron a comer? Anota quiénes fueron, qué pidió cada uno, la propina y los festejados: verás cuánto le toca a cada quien, podrás compartir el cuadro y cobrar lo que te deben.</p>
            </div>
        @else
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($eventos as $evento)
                    @php($r = $evento->resumen)
                    <a href="{{ route('eventos.show', $evento) }}" class="block rounded-lg bg-white p-5 shadow hover:bg-gray-50 dark:bg-gray-800 dark:hover:bg-gray-700/40" data-record-id="{{ $evento->id }}">
                        <p class="truncate font-semibold text-gray-900 dark:text-gray-100">{{ $evento->nombre }}</p>
                        <p class="truncate text-xs text-gray-500 dark:text-gray-400">{{ $evento->fecha->format('d/m/Y') }}@if ($evento->lugar) · {{ $evento->lugar }}@endif · {{ $evento->participantes->count() }} {{ $evento->participantes->count() === 1 ? 'persona' : 'personas' }}</p>
                        <p class="mt-4 text-2xl font-bold text-gray-900 dark:text-gray-100">{{ $simbolo }} {{ number_format($r['total'], 2) }}</p>
                        <p class="mt-1 text-xs {{ $r['por_cobrar'] > 0 ? 'text-red-600 dark:text-red-400' : 'text-gray-500 dark:text-gray-400' }}">
                            @if ($r['pago_el_hogar'])
                                {{ $r['por_cobrar'] > 0 ? 'Falta cobrar '.$simbolo.' '.number_format($r['por_cobrar'], 2) : 'Todo cobrado' }}
                            @else
                                Pagó {{ $evento->pagador()?->nombre() }}
                            @endif
                        </p>
                    </a>
                @endforeach
            </div>
        @endif
    </div>
</x-app-layout>
