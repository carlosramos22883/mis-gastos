<x-app-layout>
    <x-slot name="header">Movimientos de efectivo del {{ $cycleStart->format('d/m/Y') }} al
        {{ $cycleEnd->format('d/m/Y') }}</x-slot>
    <div class="max-w-7xl mx-auto space-y-4">
        @php($simbolo = auth()->user()->monedaPreferida?->simbolo ?? '$')
        <div class="grid gap-4 md:grid-cols-3">
            @foreach ([['Saldo disponible', $balance, 'text-primary-600'], ['Total de ingresos', $ingresos, 'text-green-600'], ['Total de egresos', $egresos, 'text-red-600']] as [$label, $value, $color])
                <div class="rounded-lg bg-white dark:bg-gray-800 p-5 shadow"><span
                        class="text-sm text-gray-500">{{ $label }}</span><strong
                        class="block text-3xl {{ $color }}">{{ $simbolo }}
                        {{ number_format((float) $value, 2) }}</strong></div>
            @endforeach
        </div>
        <div class="flex items-center justify-between">
            <div class="flex gap-2">
                <a class="text-sm text-primary-600 hover:underline"
                    href="{{ route('efectivo.index', array_merge(request()->query(), ['cycle' => $previousCycle])) }}">Ciclo
                    anterior</a>
                @unless ($isCurrentCycle)
                    <a class="text-sm text-primary-600 hover:underline" href="{{ route('efectivo.index') }}">Ciclo
                        actual</a>
                @endunless
            </div>
            @unless ($isCurrentCycle)
                <span class="text-xs text-gray-500">Ciclo cerrado: solo consulta</span>
            @endunless
        </div>
        @php($headers = [['label' => 'Descripción', 'key' => 'descripcion', 'sortable' => true], ['label' => 'Monto', 'key' => 'monto', 'sortable' => true], ['label' => 'Fecha', 'key' => 'fecha', 'sortable' => true], ['label' => 'Tipo', 'key' => 'tipo', 'sortable' => true], ['label' => 'Categoría', 'sortable' => false], ['label' => 'Acciones', 'sortable' => false, 'width' => 'w-32 text-right']])
        <x-data-table :headers="$headers" :data="$movimientos" :createRoute="$isCurrentCycle ? route('efectivo.create') : null" createModal="efectivo-modal"
            :exportRoute="route('efectivo.export')" searchPlaceholder="Buscar descripción..." defaultSort="fecha">
            <x-slot:filters>
                <x-floating-input id="amount" label="Monto" type="number" step="0.01" :value="request('amount')" />
                <x-floating-date id="from" label="Desde" :value="request('from')" :min="$cycleStart" :max="$cycleEnd" />
                <x-floating-date id="to" label="Hasta" :value="request('to')" :min="$cycleStart" :max="$cycleEnd" />
                <x-floating-select id="tipo" label="Tipo" :options="['ingreso' => 'Ingreso', 'egreso' => 'Egreso']" :value="request('tipo')" />
                <x-floating-select id="categoria" label="Categoría" :options="$categorias->pluck('nombre', 'id')->all()" :value="request('categoria')" />
            </x-slot:filters>
            @include('efectivo._table', [
                'movimientos' => $movimientos,
                'simbolo' => $simbolo,
                'isCurrentCycle' => $isCurrentCycle,
            ])
        </x-data-table>
    </div>
    <x-modal name="efectivo-modal" :show="false">
        <div x-data="{ loading: false }"
            x-on:load-efectivo-modal-form.window="loading = true; $refs.formContainer.innerHTML = ''; fetch($event.detail).then(r => r.text()).then(html => { $refs.formContainer.innerHTML = html; loading = false; Alpine.initTree($refs.formContainer); }).catch(() => { $refs.formContainer.innerHTML = '<p class=\'p-6 text-center text-red-600\'>No se pudo cargar el formulario.</p>'; loading = false; })"
            x-on:close-modal.window="if ($event.detail === 'efectivo-modal') $refs.formContainer.innerHTML = ''"
            class="p-6">
            <div x-show="loading" class="py-10 text-center">Cargando...</div>
            <div x-show="!loading" x-ref="formContainer"></div>
        </div>
    </x-modal>
</x-app-layout>
