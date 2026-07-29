@props([
    'headers' => [],
    'data' => null,
    'createRoute' => null,
    'createPermission' => null,
    'searchPlaceholder' => 'Buscar...',
    'emptyMessage' => 'No se encontraron registros.'
])

<div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg overflow-hidden">
    
    <!-- Header: Todo envuelto en un solo formulario -->
    <div class="p-6 border-b border-gray-200 dark:border-gray-700">
        <form method="GET" action="{{ url()->current() }}" class="flex flex-col sm:flex-row gap-3 items-end">
            
            <!-- Contenedor flexible para el input de búsqueda y los filtros adicionales -->
            <div class="flex flex-1 flex-wrap gap-3 items-end w-full">
                
                <!-- Input de búsqueda (Floating Input) -->
                <div class="flex-1 min-w-[200px]">
                    <x-floating-input 
                        id="search" 
                        name="search" 
                        label="{{ $searchPlaceholder }}" 
                        :value="request('search')" 
                    />
                </div>

                <!-- Filtros Personalizados (Slot) -->
                @if(isset($filters))
                    {{ $filters }}
                @endif
            </div>

            <!-- Botones de acción (shrink-0 evita que se aplasten) -->
            <div class="flex gap-2 shrink-0">
                <!-- Botón Buscar -->
                <x-primary-button type="submit" class="py-3 px-3" title="Buscar">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                    </svg>
                </x-primary-button>
                
                <!-- Botón Limpiar -->
                @php
                    $hasFilters = request('search') || count(request()->except(['search', 'page', '_token'])) > 0;
                @endphp

                @if($hasFilters)
                    <x-secondary-button type="button" onclick="window.location.href='{{ url()->current() }}'" class="py-3 px-3" title="Limpiar todos los filtros">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </x-secondary-button>
                @endif

                <!-- Botón Crear Nuevo -->
                @if($createRoute && (!$createPermission || auth()->user()->can($createPermission)))
                    <x-primary-button type="button" onclick="window.location.href='{{ $createRoute }}'" title="Crear nuevo registro">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                        </svg>
                    </x-primary-button>
                @endif
            </div>
            
        </form>
    </div>

    <!-- Tabla de Datos -->
    <div class="overflow-x-auto">
        <table class="w-full text-sm text-left text-gray-700 dark:text-gray-300">
            <thead class="text-xs text-gray-500 uppercase bg-gray-50 dark:bg-gray-700 dark:text-gray-400">
                <tr>
                    @foreach($headers as $header)
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
    @if($data && $data->hasPages())
        <div class="p-6 border-t border-gray-200 dark:border-gray-700">
            {{ $data->links() }}
        </div>
    @endif
</div>