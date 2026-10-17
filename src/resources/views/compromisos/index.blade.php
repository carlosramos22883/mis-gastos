<x-app-layout>
    <x-slot name="header"><h1>Compromisos</h1></x-slot>
    <div class="max-w-7xl mx-auto">
        @php($headers = [
            ['label' => 'Descripción', 'key' => 'descripcion', 'sortable' => true],
            ['label' => 'Fecha de pago', 'key' => 'fecha_esperada', 'sortable' => true],
            ['label' => 'Monto', 'key' => 'monto', 'sortable' => true],
            ['label' => 'Estado', 'sortable' => false],
            ['label' => 'Acciones', 'sortable' => false, 'width' => 'w-40 text-right'],
        ])
        <x-data-table filterCount="2" :headers="$headers" :data="$compromisos" :createRoute="route('compromisos.create')" createModal="compromiso-modal" createPermission="compromisos.create"
            :exportRoute="route('compromisos.export')" exportPermission="compromisos.export" searchPlaceholder="Buscar por descripción..." defaultSort="fecha_esperada" defaultDirection="asc">
            <x-slot:filters>
                <x-floating-select id="periodo" label="Período" :allowEmpty="false" :options="['actual' => 'Ciclo actual', 'proximos' => 'Próximos ciclos', 'todos' => 'Todos']" :value="request('periodo', 'actual')" />
                <x-floating-select id="estado" label="Estado" :options="\App\Models\Compromiso::ESTADOS_VISUALES" :value="request('estado')" />
            </x-slot:filters>
            @include('compromisos._table', ['compromisos' => $compromisos])
        </x-data-table>
    </div>
    @include('compromisos._modales')
</x-app-layout>
