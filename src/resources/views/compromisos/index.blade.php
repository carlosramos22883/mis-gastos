<x-app-layout>
    <x-slot name="header"><h1>Compromisos</h1></x-slot>
    <div class="max-w-7xl mx-auto">
        @php($headers = [['label' => 'Descripción', 'key' => 'descripcion', 'sortable' => true], ['label' => 'Monto', 'key' => 'monto', 'sortable' => true], ['label' => 'Fecha esperada', 'key' => 'fecha_esperada', 'sortable' => true], ['label' => 'Recurrencia', 'key' => 'recurrencia_tipo', 'sortable' => false], ['label' => 'Estado', 'key' => 'estado', 'sortable' => true], ['label' => 'Acciones', 'sortable' => false, 'width' => 'w-32 text-right']])
        <x-data-table :headers="$headers" :data="$compromisos" :createRoute="route('compromisos.create')" createModal="compromiso-modal"
            :exportRoute="route('compromisos.export')" searchPlaceholder="Buscar por descripción..." defaultSort="fecha_esperada" defaultDirection="desc">
            <x-slot:filters>
                <x-floating-select id="estado" label="Estado" :options="['activo'=>'Activo','cancelado'=>'Cancelado']" :value="request('estado')" />
            </x-slot:filters>
            @include('compromisos._table', ['compromisos' => $compromisos])
        </x-data-table>
    </div>
    <x-modal name="compromiso-modal" :show="false"><div x-data="{ loading: false }" x-on:load-compromiso-modal-form.window="loading = true; $refs.formContainer.innerHTML = ''; fetch($event.detail).then(r => r.text()).then(html => { $refs.formContainer.innerHTML = html; loading = false; Alpine.initTree($refs.formContainer); }).catch(() => { $refs.formContainer.innerHTML = '<p class=\'p-6 text-center text-red-600\'>No se pudo cargar el formulario.</p>'; loading = false; })" x-on:close-modal.window="if ($event.detail === 'compromiso-modal') $refs.formContainer.innerHTML = ''" class="p-6"><div x-show="loading" class="py-10 text-center">Cargando...</div><div x-show="!loading" x-ref="formContainer"></div></div></x-modal>
</x-app-layout>
