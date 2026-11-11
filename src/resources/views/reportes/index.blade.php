@php
    $simbolo = auth()->user()->monedaPreferida?->simbolo ?? '$';
    $dinero = fn ($v) => ($v < 0 ? '-' : '').$simbolo.' '.number_format(abs((float) $v), 2);
    $fecha = fn ($f) => $f ? \Carbon\Carbon::parse($f)->format('d/m/Y') : '—';
    $pestanas = ['ejecucion' => 'Ejecución financiera', 'gastos' => 'Gastos por categoría', 'prestamos' => 'Préstamos', 'estado-cuenta' => 'Estado de cuenta'];
    // Los parámetros del período viajan entre pestañas y a la exportación
    $consulta = array_filter(request()->only(['ciclo', 'desde', 'hasta', 'tarjeta', 'estado']));
    $conPeriodo = in_array($reporte, ['ejecucion', 'gastos'], true);
    $esRango = request()->filled(['desde', 'hasta']);
@endphp
<x-app-layout>
    <x-slot name="header"><h1>Reportes</h1></x-slot>

    <div class="max-w-7xl mx-auto space-y-6">
        {{-- Pestañas --}}
        <nav class="flex flex-wrap gap-2" aria-label="Reportes">
            @foreach ($pestanas as $clave => $nombre)
                <a href="{{ route('reportes.show', [$clave] + \Illuminate\Support\Arr::except($consulta, ['tarjeta', 'estado'])) }}"
                   @if ($clave === $reporte) aria-current="page" style="background-color: var(--color-primary)" @endif
                   class="rounded-full px-4 py-2 text-sm font-medium {{ $clave === $reporte ? 'text-white dark:text-white shadow-sm' : 'bg-white text-gray-700 shadow-sm hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700' }}">{{ $nombre }}</a>
            @endforeach
        </nav>

        {{-- Período y descargas --}}
        <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg bg-white p-4 shadow dark:bg-gray-800">
            @if ($conPeriodo)
                <div class="flex flex-wrap items-center gap-3" x-data="{ rango: {{ $esRango ? 'true' : 'false' }} }">
                    <div class="flex items-center gap-1" x-show="!rango">
                        @if ($periodo['es_ciclo'] && $periodo['anterior'])
                            <a href="{{ route('reportes.show', [$reporte, 'ciclo' => $periodo['anterior']]) }}" class="rounded p-2 text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-700" title="Ciclo anterior" aria-label="Ciclo anterior">
                                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                            </a>
                        @endif
                        <span class="text-sm font-medium text-gray-800 dark:text-gray-100">{{ $textoPeriodo }}</span>
                        @if ($periodo['es_ciclo'] && $periodo['siguiente'])
                            <a href="{{ route('reportes.show', [$reporte, 'ciclo' => $periodo['siguiente']]) }}" class="rounded p-2 text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-700" title="Ciclo siguiente" aria-label="Ciclo siguiente">
                                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                            </a>
                        @endif
                        @unless ($periodo['es_ciclo'])
                            <a href="{{ route('reportes.show', [$reporte]) }}" class="ml-2 text-sm text-primary-600 hover:underline dark:text-primary-400">Volver al ciclo actual</a>
                        @endunless
                    </div>
                    <form method="GET" action="{{ route('reportes.show', [$reporte]) }}" class="flex flex-wrap items-end gap-2" x-show="rango" x-cloak>
                        <label class="text-xs text-gray-500 dark:text-gray-400">Desde
                            <input type="date" name="desde" value="{{ request('desde', $periodo['desde']->toDateString()) }}" required
                                   class="mt-1 block rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                        </label>
                        <label class="text-xs text-gray-500 dark:text-gray-400">Hasta
                            <input type="date" name="hasta" value="{{ request('hasta', $periodo['hasta']->toDateString()) }}" required
                                   class="mt-1 block rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                        </label>
                        <x-primary-button>Ver</x-primary-button>
                    </form>
                    <button type="button" class="text-sm text-primary-600 hover:underline dark:text-primary-400" @click="rango = !rango" x-text="rango ? 'Por ciclo' : 'Elegir fechas'"></button>
                    @error('hasta')<p class="w-full text-sm text-red-600">{{ $message }}</p>@enderror
                </div>
            @else
                <span class="text-sm font-medium text-gray-800 dark:text-gray-100">{{ $reporte === 'prestamos' ? $textoPeriodo : 'Elige la tarjeta y el corte' }}</span>
            @endif

            @can('reportes.export')
                @if ($d)
                    <div class="flex flex-wrap gap-2">
                        @foreach (['pdf' => 'PDF', 'xlsx' => 'Excel', 'csv' => 'CSV'] as $formato => $etiqueta)
                            <a href="{{ route('reportes.exportar', [$reporte, 'formato' => $formato] + $consulta) }}"
                               class="inline-flex items-center gap-1.5 rounded-md border border-gray-300 bg-white px-3 py-2 text-xs font-semibold uppercase tracking-wide text-gray-700 shadow-sm hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700">
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                                {{ $etiqueta }}
                            </a>
                        @endforeach
                    </div>
                @endif
            @endcan
        </div>

        @include('reportes._'.str_replace('-', '_', $reporte))
    </div>
</x-app-layout>
