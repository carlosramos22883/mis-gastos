<x-app-layout>
    <x-slot name="header">
        <h1>{{ __('Gestión de Marcas de Red') }}</h1>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            @php
                $headers = [
                    ['label' => 'Nombre', 'key' => 'nombre', 'sortable' => true, 'width' => 'w-1/2'],
                    ['label' => 'Estado', 'sortable' => false, 'width' => 'w-24'],
                    ['label' => 'Acciones', 'sortable' => false, 'width' => 'w-32 text-right'],
                ];
            @endphp

            <x-data-table :headers="$headers" :data="$items" :createRoute="route('admin.catalogos.marcas-red.create')" createPermission="marcas-red.create"
                createModal="marca-red-modal" searchPlaceholder="Buscar por nombre o color..." defaultSort="nombre"
                defaultDirection="asc">

                <!-- El cuerpo de la tabla se carga vía AJAX en _table_body.blade.php -->
            </x-data-table>
        </div>
    </div>

    <!-- Modal -->
    <x-modal name="marca-red-modal" :show="false" maxWidth="2xl">
        <div x-data="{ html: '', loading: false }" tabindex="-1"
            x-on:load-marca-red-modal-form.window="
            loading = true;
            html = '';
            fetch($event.detail)
                .then(r => r.text())
                .then(h => { 
                    $refs.formContainer.innerHTML = h;
                    loading = false;
                    setTimeout(() => {
                        if (typeof Alpine !== 'undefined' && typeof Alpine.initTree === 'function') {
                            Alpine.initTree($refs.formContainer);
                        }
                    }, 50);
                })
                .catch(err => {
                    $refs.formContainer.innerHTML = '<p class=\'text-red-500 text-center p-4\'>Error al cargar</p>';
                    loading = false;
                });
        "
            x-on:close-modal.window="if ($event.detail === 'marca-red-modal') { html = ''; loading = false; if ($refs.formContainer) $refs.formContainer.innerHTML = ''; }"
            class="p-6 focus:outline-none">
            <div x-show="loading" class="flex justify-center items-center py-12">
                <svg class="animate-spin h-8 w-8 text-primary-600" xmlns="http://www.w3.org/2000/svg" fill="none"
                    viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor"
                        stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor"
                        d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                    </path>
                </svg>
            </div>
            <div x-show="!loading" x-ref="formContainer"></div>
        </div>
    </x-modal>
</x-app-layout>
