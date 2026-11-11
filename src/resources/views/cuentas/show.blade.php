@php($simbolo = auth()->user()->monedaPreferida?->simbolo ?? '$')
<x-app-layout>
    <x-slot name="header">
        <h1 class="flex items-center gap-3">
            @if ($cuenta->banco)<x-logo-banco :banco="$cuenta->banco" size="lg" />@else<span class="inline-block h-4 w-4 rounded-full" style="background-color: {{ $cuenta->color }}"></span>@endif
            {{ $cuenta->nombre }}
        </h1>
    </x-slot>
    <div class="max-w-7xl mx-auto space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3 text-sm text-gray-600 dark:text-gray-300">
            <span>{{ collect([$cuenta->tipoCuenta?->nombre, $cuenta->banco?->nombre, $cuenta->numeroEnmascarado()])->filter()->implode(' · ') }}
                @unless ($cuenta->activo) · <strong>Inactiva</strong> @endunless</span>
            <div class="flex gap-2">
                <x-volver />
                <x-secondary-button type="button" onclick="window.location='{{ route('cuentas.index') }}'">Todas las cuentas</x-secondary-button>
                @if ($esActual && $cuenta->activo)
                    @can('cuentas.create')
                        <x-secondary-button type="button" x-data x-on:click="$dispatch('load-transferencia-modal-form', '{{ route('transferencias.create', ['origen' => 'cuenta:'.$cuenta->id, 'destino' => 'efectivo']) }}'); $dispatch('open-modal', 'transferencia-modal')">Transferir</x-secondary-button>
                    @endcan
                @endif
            </div>
        </div>

        <p class="text-sm font-medium text-gray-700 dark:text-gray-300">Ciclo del {{ $inicio->format('d/m/Y') }} al {{ $fin->format('d/m/Y') }}</p>
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            {{-- Lo más importante primero: el saldo de hoy. Cada tarjeta se actualiza al guardar (data-cash-card = clave del resumen) --}}
            @foreach ([
                [$esActual ? 'Saldo actual' : 'Saldo al corte', $resumen['saldo_final'], 'text-primary-600 dark:text-primary-400', 'saldo_final'],
                ['Ingresos', $resumen['ingresos'], 'text-green-600 dark:text-green-400', 'ingresos'],
                ['Egresos', $resumen['egresos'], 'text-red-600 dark:text-red-400', 'egresos'],
                ['Saldo al iniciar el ciclo', $resumen['saldo_inicial'], 'text-gray-900 dark:text-gray-100', 'saldo_inicial'],
            ] as [$label, $valor, $color, $clave])
                <div class="rounded-lg bg-white p-5 shadow dark:bg-gray-800" data-cash-card="{{ $clave }}">
                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ $label }}</p>
                    <p class="mt-1 text-2xl font-bold {{ $color }}" data-cash-value data-cash-symbol="{{ $simbolo }}">{{ $simbolo }} {{ number_format($valor, 2) }}</p>
                </div>
            @endforeach
        </div>

        @php($cycleLink = fn ($cycle) => route('cuentas.show', array_filter(['cuenta' => $cuenta->id, 'cycle' => $cycle])))
        <nav class="flex flex-wrap items-center justify-between gap-2 text-sm" aria-label="Navegación entre ciclos">
            <div class="flex items-center gap-4">
                @if ($anterior)<a class="text-primary-600 dark:text-primary-400 hover:underline" href="{{ $cycleLink($anterior) }}">&larr; Ciclo anterior</a>@endif
                @if ($siguiente)<a class="text-primary-600 dark:text-primary-400 hover:underline" href="{{ $cycleLink($siguiente) }}">Ciclo siguiente &rarr;</a>@endif
                @unless ($esActual)<a class="text-primary-600 dark:text-primary-400 hover:underline" href="{{ $cycleLink(null) }}">Ir al ciclo actual</a>@endunless
            </div>
            @unless ($esActual)<span class="text-xs text-gray-500 dark:text-gray-400">Ciclo cerrado: solo consulta</span>@endunless
        </nav>

        @php($headers = [
            ['label' => 'Descripción', 'key' => 'descripcion', 'sortable' => true, 'width' => 'w-[22rem]'],
            ['label' => 'Monto', 'key' => 'monto', 'sortable' => true],
            ['label' => 'Fecha', 'key' => 'fecha', 'sortable' => true],
            ['label' => 'Categoría', 'sortable' => false],
            ['label' => 'Acciones', 'sortable' => false, 'width' => 'w-32 text-right'],
        ])
        <x-data-table filterCount="2" :keep="['cycle', 'desde']" :headers="$headers" :data="$movimientos"
            :createRoute="$esActual && $cuenta->activo ? route('cuentas.movimientos.create', $cuenta) : null" createModal="movimiento-modal" createPermission="cuentas.create"
            :exportRoute="route('cuentas.export', $cuenta)" exportPermission="cuentas.export"
            searchPlaceholder="Buscar descripción..." defaultSort="fecha" defaultDirection="desc">
            <x-slot:filters>
                <x-floating-select id="tipo" label="Tipo" :options="['ingreso' => 'Ingresos', 'egreso' => 'Egresos']" :value="request('tipo')" />
                <x-floating-select id="categoria" label="Categoría" :options="$categorias" :value="request('categoria')" />
            </x-slot:filters>
            @include('cuentas._movimientos')
        </x-data-table>
    </div>
    <x-ajax-modal name="movimiento" />
    <x-ajax-modal name="transferencia" />
    <x-ajax-modal name="deuda" />
</x-app-layout>
