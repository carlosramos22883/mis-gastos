<x-app-layout>
    @php
        $modal = $catalogo.'-modal';
    @endphp
    <x-slot name="header"><h1>{{ $def['titulo'] }}</h1></x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            @php
                $headers = [
                    ['label' => 'Nombre', 'key' => 'nombre', 'sortable' => true, 'width' => 'w-1/3'],
                    ['label' => 'Detalle', 'sortable' => false, 'width' => 'w-1/3'],
                    ['label' => 'Orden', 'key' => 'orden', 'sortable' => true, 'width' => 'w-20'],
                    ['label' => 'Estado', 'key' => 'activo', 'sortable' => true, 'width' => 'w-24'],
                    ['label' => 'Acciones', 'sortable' => false, 'width' => 'text-right'],
                ];
            @endphp
            <x-data-table :headers="$headers" :data="$items"
                :createRoute="route('admin.catalogos.'.$catalogo.'.create')"
                :createPermission="$def['permiso'].'.create'"
                :createModal="$modal"
                searchPlaceholder="Buscar por nombre o descripción..."
                defaultSort="orden" defaultDirection="asc">
            </x-data-table>
        </div>
    </div>

    <x-modal :name="$modal" :show="false">
        <div x-data="{ loading: false }" tabindex="-1"
            x-on:load-{{ $modal }}-form.window="loading = true; fetch($event.detail).then(r => r.text()).then(h => { $refs.formContainer.innerHTML = h; loading = false; setTimeout(() => Alpine.initTree($refs.formContainer), 50); }).catch(() => { $refs.formContainer.innerHTML = '<p class=\'p-4 text-center text-red-500\'>Error al cargar</p>'; loading = false; })"
            x-on:close-modal.window="loading = false; if ($refs.formContainer) $refs.formContainer.innerHTML = '';"
            class="p-6 focus:outline-none">
            <div x-show="loading" class="py-12 text-center text-gray-500 dark:text-gray-400">Cargando...</div>
            <div x-show="!loading" x-ref="formContainer"></div>
        </div>
    </x-modal>
</x-app-layout>
