<x-app-layout>
    <x-slot name="header">Categorías personales</x-slot>
    <div class="max-w-7xl mx-auto">
        @php($headers = [['label' => 'Nombre', 'key' => 'nombre', 'sortable' => true], ['label' => 'Color', 'key' => 'color', 'sortable' => false], ['label' => 'Tipo', 'key' => 'tipo', 'sortable' => true], ['label' => 'Estado', 'key' => 'activo', 'sortable' => true], ['label' => 'Acciones', 'sortable' => false, 'width' => 'w-32 text-right']])
        <x-data-table :headers="$headers" :data="$categorias" :createRoute="route('categorias.create')" createModal="categoria-modal"
            :exportRoute="route('categorias.export')" searchPlaceholder="Buscar por nombre..." defaultSort="nombre" defaultDirection="asc">
            <x-slot:filters>
                <x-floating-select id="tipo" label="Tipo" :options="['ingreso' => 'Ingreso', 'egreso' => 'Egreso']" :value="request('tipo')" />
                <x-floating-select id="activo" label="Estado" :options="['1' => 'Activas', '0' => 'Inactivas']" :value="request('activo')" />
            </x-slot:filters>
            @include('categorias._table', ['categorias' => $categorias])
        </x-data-table>
    </div>
    <x-modal name="categoria-modal" :show="false">
        <div x-data="{ loading: false }"
            x-on:load-categoria-modal-form.window="loading = true; $refs.formContainer.innerHTML = ''; fetch($event.detail).then(r => r.text()).then(html => { $refs.formContainer.innerHTML = html; loading = false; Alpine.initTree($refs.formContainer); }).catch(() => { $refs.formContainer.innerHTML = '<p class=\'p-6 text-center text-red-600\'>No se pudo cargar el formulario.</p>'; loading = false; })"
            x-on:close-modal.window="if ($event.detail === 'categoria-modal') $refs.formContainer.innerHTML = ''"
            class="p-6">
            <div x-show="loading" class="py-10 text-center">Cargando...</div>
            <div x-show="!loading" x-ref="formContainer"></div>
        </div>
    </x-modal>
</x-app-layout>
