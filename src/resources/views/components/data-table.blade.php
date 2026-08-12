@props([
    'headers' => [],
    'data' => null,
    'createRoute' => null,
    'createPermission' => null,
    'createModal' => null,
    'searchPlaceholder' => 'Buscar...',
    'emptyMessage' => 'No se encontraron registros.',
    'exportRoute' => null,
    'exportPermission' => null,
    'grosorIconos' => 2,
    'defaultSort' => 'created_at',
    'defaultDirection' => 'desc',
])

@php
    $currentSort = request('sort', $defaultSort);
    $currentDirection = request('direction', $defaultDirection);
@endphp

<div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg overflow-hidden">

    <!-- Header: Todo envuelto en un solo formulario -->
    <div class="p-6 border-b border-gray-200 dark:border-gray-700">
        <form method="GET" action="{{ url()->current() }}" class="flex flex-col gap-4">

            {{-- Inputs ocultos para mantener el ordenamiento --}}
            @if (request('sort'))
                <input type="hidden" name="sort" value="{{ request('sort') }}">
            @endif
            @if (request('direction'))
                <input type="hidden" name="direction" value="{{ request('direction') }}">
            @endif

            <!-- Fila 1: Registros por página y Botones de Exportación -->
            <div class="flex justify-between items-start gap-2 overflow-x-auto pt-4 pb-2 pl-1">

                <!-- Selector de items por página -->
                <div class="w-24 shrink-0">
                    <x-floating-select id="per_page" name="per_page" label="Por página" :allowEmpty="false"
                        :options="[
                            '10' => '10',
                            '25' => '25',
                            '50' => '50',
                            '100' => '100',
                        ]" :value="request('per_page', 10)" :submitForm="true" :searchable="false" />
                </div>

                <!-- Botones de exportación -->
                @if ($exportRoute && (!$exportPermission || auth()->user()->can($exportPermission)))
                    <div class="flex gap-1 shrink-0">

                        <!-- CSV -->
                        <x-primary-button type="button"
                            onclick="window.location.href='{{ $exportRoute }}?format=csv&{{ http_build_query(request()->all()) }}'"
                            title="Exportar a CSV" class="py-2.5 px-3">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"
                                stroke-width="{{ $grosorIconos }}">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                            </svg>
                        </x-primary-button>

                        <!-- Excel -->
                        <x-secondary-button type="button"
                            onclick="window.location.href='{{ $exportRoute }}?format=xlsx&{{ http_build_query(request()->all()) }}'"
                            title="Exportar a Excel" class="py-2.5 px-3">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"
                                stroke-width="{{ $grosorIconos }}">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M3 10h18M3 14h18m-9-4v8m-7 0h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" />
                            </svg>
                        </x-secondary-button>

                        <!-- PDF -->
                        <x-danger-button type="button"
                            onclick="window.location.href='{{ $exportRoute }}?format=pdf&{{ http_build_query(request()->all()) }}'"
                            title="Exportar a PDF" class="py-2.5 px-3">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"
                                stroke-width="{{ $grosorIconos }}">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M9 13h1.5a1.5 1.5 0 000-3H9v6" />
                            </svg>
                        </x-danger-button>
                    </div>
                @endif
            </div>

            <!-- Fila 2: Búsqueda, Filtros y Botones de Acción -->
            <div class="flex flex-col lg:flex-row justify-between items-start lg:items-end gap-3">

                <!-- Contenedor flexible para el input de búsqueda y los filtros adicionales -->
                <div class="flex flex-1 flex-wrap gap-3 items-end w-full">

                    <!-- Input de búsqueda -->
                    <div class="flex-1 min-w-[200px]">
                        <x-floating-input id="search" name="search" label="{{ $searchPlaceholder }}"
                            :value="request('search')" />
                    </div>

                    <!-- Filtros Personalizados -->
                    @if (isset($filters))
                        {{ $filters }}
                    @endif
                </div>

                <!-- Botones de acción derecha -->
                <div class="flex gap-2 shrink-0 flex-wrap">

                    <!-- Botón Buscar -->
                    <x-primary-button type="submit" class="py-2.5 px-3" title="Buscar">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"
                            stroke-width="{{ $grosorIconos }}">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                        </svg>
                    </x-primary-button>

                    <!-- Botón Limpiar -->
                    @php
                        // Excluimos parámetros que NO son filtros
                        $excludedParams = ['page', 'per_page', 'sort', 'direction', '_token'];

                        // Obtenemos los filtros y eliminamos los valores vacíos
                        $actualFilters = array_filter(
                            request()->except($excludedParams),
                            fn($value) => $value !== null && $value !== '',
                        );

                        $hasFilters = !empty($actualFilters);

                        // Construimos la URL de limpieza: mantenemos per_page, sort y direction
                        $cleanUrl = url()->current();
                        $keepParams = request()->only(['per_page', 'sort', 'direction']);
                        $keepParams = array_filter($keepParams, fn($v) => $v !== null && $v !== '');

                        if (!empty($keepParams)) {
                            $cleanUrl .= '?' . http_build_query($keepParams);
                        }
                    @endphp

                    @if ($hasFilters)
                        <x-secondary-button type="button" onclick="window.location.href='{{ $cleanUrl }}'"
                            class="py-2.5 px-3" title="Limpiar todos los filtros">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"
                                stroke-width="{{ $grosorIconos }}">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </x-secondary-button>
                    @endif

                    <!-- Botón Crear Nuevo -->
                    @if ($createRoute && (!$createPermission || auth()->user()->can($createPermission)))
                        @if ($createModal)
                            <!-- Agregamos type="button" y .prevent para que no envíe el formulario GET de la tabla -->
                            <x-primary-button x-data="" type="button"
                                @click.prevent="$dispatch('load-{{ $createModal }}-form', '{{ $createRoute }}?modal=1'); $dispatch('open-modal', '{{ $createModal }}')"
                                title="Crear nuevo registro">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"
                                    stroke-width="{{ $grosorIconos }}">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
                                </svg>
                            </x-primary-button>
                        @else
                            <!-- Comportamiento por defecto: navegación normal -->
                            <x-primary-button type="button" onclick="window.location.href='{{ $createRoute }}'"
                                title="Crear nuevo registro">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"
                                    stroke-width="{{ $grosorIconos }}">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
                                </svg>
                            </x-primary-button>
                        @endif
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
                            @if ($header['sortable'] ?? false)
                                @php
                                    $sortField = $header['sortField'] ?? $header['key'];
                                    $newDirection =
                                        $currentSort === $sortField && $currentDirection === 'asc' ? 'desc' : 'asc';
                                    $isActive = $currentSort === $sortField;
                                @endphp
                                <a href="{{ request()->fullUrlWithQuery(['sort' => $sortField, 'direction' => $newDirection]) }}"
                                    class="inline-flex items-center gap-1 hover:text-gray-700 dark:hover:text-gray-300 transition-colors">
                                    {{ $header['label'] }}
                                    @if ($isActive)
                                        @if ($currentDirection === 'asc')
                                            <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20">
                                                <path fill-rule="evenodd"
                                                    d="M14.707 12.707a1 1 0 01-1.414 0L10 9.414l-3.293 3.293a1 1 0 01-1.414-1.414l4-4a1 1 0 011.414 0l4 4a1 1 0 010 1.414z"
                                                    clip-rule="evenodd" />
                                            </svg>
                                        @else
                                            <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20">
                                                <path fill-rule="evenodd"
                                                    d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"
                                                    clip-rule="evenodd" />
                                            </svg>
                                        @endif
                                    @else
                                        <svg class="w-3 h-3 opacity-30" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd"
                                                d="M14.707 12.707a1 1 0 01-1.414 0L10 9.414l-3.293 3.293a1 1 0 01-1.414-1.414l4-4a1 1 0 011.414 0l4 4a1 1 0 010 1.414z"
                                                clip-rule="evenodd" />
                                        </svg>
                                    @endif
                                </a>
                            @else
                                {{ $header['label'] }}
                            @endif
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
