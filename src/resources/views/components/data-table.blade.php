@props([
    'headers' => [],
    'data' => null,
    'createRoute' => null,
    'createPermission' => null,
    'searchPlaceholder' => 'Buscar...',
    'emptyMessage' => 'No se encontraron registros.',
    'exportRoute' => null,
    'exportPermission' => null,
])

<div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg overflow-hidden">

    <!-- Header: Todo envuelto en un solo formulario -->
    <div class="p-6 border-b border-gray-200 dark:border-gray-700">
        <form method="GET" action="{{ url()->current() }}" class="flex flex-col gap-4">

            <!-- Fila 1: Búsqueda, Filtros y Botones de Acción -->
            <div class="flex flex-col lg:flex-row justify-between items-start lg:items-end gap-3">

                <!-- Contenedor flexible para el input de búsqueda y los filtros adicionales -->
                <div class="flex flex-1 flex-wrap gap-3 items-end w-full">

                    <!-- Input de búsqueda (Floating Input) -->
                    <div class="flex-1 min-w-[200px]">
                        <x-floating-input id="search" name="search" label="{{ $searchPlaceholder }}"
                            :value="request('search')" />
                    </div>

                    <!-- Filtros Personalizados (Slot) -->
                    @if (isset($filters))
                        {{ $filters }}
                    @endif
                </div>

                <!-- Botones de acción derecha -->
                <div class="flex gap-2 shrink-0 flex-wrap">
                    <!-- Selector de items por página -->
                    <div class="w-24">
                        <x-floating-select id="per_page" name="per_page" label="Por página" :allow-empty="false"
                            :options="[
                                '10' => '10',
                                '25' => '25',
                                '50' => '50',
                                '100' => '100',
                            ]" :value="request('per_page', 10)" @change="$el.closest('form').submit()"
                            :searchable="false" />
                    </div>

                    <!-- Botones de exportación -->
                    @if ($exportRoute && (!$exportPermission || auth()->user()->can($exportPermission)))
                        <div class="flex gap-1">
                            <button type="button"
                                onclick="window.location.href='{{ $exportRoute }}?format=csv&{{ http_build_query(request()->all()) }}'"
                                title="Exportar a CSV"
                                class="px-3 py-2.5 bg-green-600 hover:bg-green-700 text-white rounded-lg transition-colors">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                </svg>
                            </button>

                            <button type="button"
                                onclick="window.location.href='{{ $exportRoute }}?format=xlsx&{{ http_build_query(request()->all()) }}'"
                                title="Exportar a Excel"
                                class="px-3 py-2.5 bg-green-700 hover:bg-green-800 text-white rounded-lg transition-colors">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                </svg>
                            </button>

                            <button type="button"
                                onclick="window.location.href='{{ $exportRoute }}?format=pdf&{{ http_build_query(request()->all()) }}'"
                                title="Exportar a PDF"
                                class="px-3 py-2.5 bg-red-600 hover:bg-red-700 text-white rounded-lg transition-colors">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
                                </svg>
                            </button>
                        </div>
                    @endif

                    <!-- Botón Buscar -->
                    <x-primary-button type="submit" class="py-2.5 px-3" title="Buscar">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                        </svg>
                    </x-primary-button>

                    <!-- Botón Limpiar -->
                    @php
                        $hasFilters =
                            request('search') || count(request()->except(['search', 'page', 'per_page', '_token'])) > 0;
                    @endphp

                    @if ($hasFilters)
                        <x-secondary-button type="button" onclick="window.location.href='{{ url()->current() }}'"
                            class="py-2.5 px-3" title="Limpiar todos los filtros">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </x-secondary-button>
                    @endif

                    <!-- Botón Crear Nuevo -->
                    @if ($createRoute && (!$createPermission || auth()->user()->can($createPermission)))
                        <x-primary-button type="button" onclick="window.location.href='{{ $createRoute }}'"
                            title="Crear nuevo registro">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M12 4v16m8-8H4" />
                            </svg>
                        </x-primary-button>
                    @endif
                </div>
            </div>

        </form>
    </div>

    <!-- Tabla de Datos -->
    <div class="overflow-x-auto">
        <table class="w-full text-sm text-left text-gray-700 dark:text-gray-300">
            <thead class="text-xs text-gray-500 uppercase bg-gray-50 dark:bg-gray-700 dark:text-gray-400">
                <tr>
                    @foreach ($headers as $header)
                        <th class="px-6 py-3 {{ $header['width'] ?? '' }}">
                            {{ $header['label'] }}
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                {{ $slot }}
            </tbody>
        </table>
    </div>

    <!-- Paginación -->
    @if ($data && $data->hasPages())
        <div class="p-6 border-t border-gray-200 dark:border-gray-700">
            {{ $data->links() }}
        </div>
    @endif
</div>
