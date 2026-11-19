<x-app-layout>
    <x-slot name="header"><h1>Tiendas</h1></x-slot>
    <div class="max-w-4xl mx-auto space-y-6" x-data x-on:refresh-table.window="window.location.reload()">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <p class="text-sm text-gray-600 dark:text-gray-300">Donde compras. Las que tienen página se pueden consultar en línea; en las demás, el precio se anota.</p>
            <div class="flex flex-wrap gap-3">
                @can('despensa.create')
                    <x-secondary-button type="button" x-on:click="accionLista('{{ route('tiendas.comunes') }}')">Agregar las de siempre</x-secondary-button>
                    <x-primary-button type="button" x-on:click="$dispatch('load-tienda-modal-form', '{{ route('tiendas.create') }}'); $dispatch('open-modal', 'tienda-modal')">Nueva tienda</x-primary-button>
                @endcan
            </div>
        </div>
        @if ($tiendas->isEmpty())
            <div class="rounded-xl bg-white p-10 text-center shadow dark:bg-gray-800">
                <p class="text-gray-600 dark:text-gray-300">Aún no tienes tiendas.</p>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">«Agregar las de siempre» trae Súper Selectos, Walmart, La Despensa de Don Juan, PriceSmart, Maxi Despensa, Despensa Familiar y Dollarcity.</p>
            </div>
        @else
            <ul class="divide-y divide-gray-100 overflow-hidden rounded-xl bg-white shadow dark:divide-gray-700 dark:bg-gray-800">
                @foreach ($tiendas as $t)
                    <li class="flex flex-wrap items-center justify-between gap-3 px-5 py-4 {{ $t->activo ? '' : 'opacity-60' }}">
                        <div class="min-w-0">
                            <p class="font-medium text-gray-900 dark:text-gray-100">{{ $t->nombre }}{{ $t->activo ? '' : ' · ya no compro aquí' }}</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                <span class="rounded-full px-2 py-0.5 {{ $t->enLinea() ? 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300' : 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300' }}">{{ $t->enLinea() ? 'Precios en línea' : 'Precio anotado' }}</span>
                                · {{ $t->precios_count }} {{ $t->precios_count === 1 ? 'precio' : 'precios' }}{{ $t->notas ? ' · '.$t->notas : '' }}
                            </p>
                        </div>
                        <div class="flex gap-4 text-sm font-medium">
                            @can('despensa.edit')
                                <button type="button" class="text-primary-600 hover:underline dark:text-primary-400" x-on:click="$dispatch('load-tienda-modal-form', '{{ route('tiendas.edit', $t) }}'); $dispatch('open-modal', 'tienda-modal')">Editar</button>
                            @endcan
                            @can('despensa.delete')
                                <button type="button" class="text-red-600 hover:underline dark:text-red-400" x-on:click="deleteItem({{ $t->id }}, {{ \Illuminate\Support\Js::from($t->nombre) }}, '{{ route('tiendas.destroy', $t) }}')">Eliminar</button>
                            @endcan
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
    <x-ajax-modal name="tienda" />
</x-app-layout>
