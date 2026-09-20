<x-app-layout>
    <x-slot name="header">Tarjetas de crédito</x-slot>
    <div class="max-w-7xl mx-auto">
        @php($headers = [
            ['label' => 'Tarjeta', 'key' => 'nombre', 'sortable' => true],
            ['label' => 'Banco', 'key' => 'banco_id', 'sortable' => false],
            ['label' => 'Límite', 'key' => 'limite_credito', 'sortable' => true],
            ['label' => 'Saldo actual', 'key' => 'saldo_actual', 'sortable' => true],
            ['label' => 'Disponible', 'sortable' => false],
            ['label' => 'Estado', 'key' => 'activo', 'sortable' => true],
            ['label' => 'Acciones', 'sortable' => false, 'width' => 'w-32 text-right'],
        ])
        <x-data-table :headers="$headers" :data="$tarjetas" :createRoute="route('tarjetas.create')" createModal="tarjeta-modal"
            :exportRoute="route('tarjetas.export')" searchPlaceholder="Buscar tarjeta..." defaultSort="nombre" defaultDirection="asc">
            <x-slot:filters>
                <x-floating-select id="activo" label="Estado" :options="['1' => 'Activas', '0' => 'Inactivas']" :value="request('activo')" />
            </x-slot:filters>
            @include('tarjetas._table', ['tarjetas' => $tarjetas])
        </x-data-table>
    </div>
    <x-modal name="tarjeta-modal" :show="false" maxWidth="2xl">
        <div x-data="{ loading: false }"
            x-on:load-tarjeta-modal-form.window="loading = true; $refs.formContainer.innerHTML = ''; fetch($event.detail).then(r => r.text()).then(html => { $refs.formContainer.innerHTML = html; loading = false; Alpine.initTree($refs.formContainer); }).catch(() => { $refs.formContainer.innerHTML = '<p class=\'p-6 text-center text-red-600\'>No se pudo cargar el formulario.</p>'; loading = false; })"
            x-on:close-modal.window="if ($event.detail === 'tarjeta-modal') $refs.formContainer.innerHTML = ''" class="p-6">
            <div x-show="loading" class="py-10 text-center">Cargando...</div>
            <div x-show="!loading" x-ref="formContainer"></div>
        </div>
    </x-modal>
</x-app-layout>
