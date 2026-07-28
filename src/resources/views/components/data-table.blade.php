@props([
    'headers' => [],
    'data' => null,
    'createRoute' => null,
    'createPermission' => null,
    'searchPlaceholder' => 'Buscar...',
    'emptyMessage' => 'No se encontraron registros.'
])

<div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg overflow-hidden">
    
    <!-- Header: Búsqueda y Botón Crear -->
    <div class="p-6 border-b border-gray-200 dark:border-gray-700">
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
            
            <!-- Formulario de Búsqueda -->
            <form method="GET" action="{{ url()->current() }}" class="flex flex-col sm:flex-row gap-3 w-full sm:w-auto">
                <input 
                    type="text" 
                    name="search" 
                    value="{{ request('search') }}" 
                    placeholder="{{ $searchPlaceholder }}"
                    class="w-full sm:w-64 px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-primary-500 focus:border-transparent text-sm"
                >
                <x-primary-button type="submit" class="py-2 px-4 text-xs">
                    Buscar
                </x-primary-button>
                @if(request('search'))
                    <x-secondary-button type="button" onclick="window.location.href='{{ url()->current() }}'" class="py-2 px-4 text-xs">
                        Limpiar
                    </x-secondary-button>
                @endif
            </form>

            <!-- Botón Crear Nuevo -->
            @if($createRoute && (!$createPermission || auth()->user()->can($createPermission)))
                <x-primary-button onclick="window.location.href='{{ $createRoute }}'">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                    </svg>
                    Nuevo Registro
                </x-primary-button>
            @endif
        </div>
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
                <!-- La vista padre inyecta las filas completas aquí -->
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