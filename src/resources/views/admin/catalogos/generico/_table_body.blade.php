@php($modal = $catalogo.'-modal')
<tbody class="divide-y divide-gray-200 dark:divide-gray-700">
    @forelse($items as $item)
        <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors" data-record-id="{{ $item->id }}">
            <td class="px-6 py-4 font-medium text-gray-900 dark:text-gray-100">{{ $item->nombre }}</td>
            <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-400">
                @if ($catalogo === 'conceptos-planilla')
                    {{ $item->tipo === 'adicion' ? 'Adición' : 'Descuento' }}{{ $item->porcentaje !== null ? ' · '.(float) $item->porcentaje.' %' : '' }}{{ $item->tope !== null ? ' · tope $'.number_format((float) $item->tope, 2) : '' }}{{ $item->monto !== null ? ' · $'.number_format((float) $item->monto, 2) : '' }}
                    @if ($item->descripcion)<span class="block text-xs">{{ $item->descripcion }}</span>@endif
                @else
                    {{ $item->descripcion ?: '—' }}
                @endif
                @if ($item->usos)<span class="block text-xs text-gray-400">En uso: {{ $item->usos }}</span>@endif
            </td>
            <td class="px-6 py-4 text-gray-600 dark:text-gray-400">{{ $item->orden }}</td>
            <td class="px-6 py-4">
                @if($item->activo)
                    <span class="px-2 py-1 text-xs font-semibold rounded bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200">Activo</span>
                @else
                    <span class="px-2 py-1 text-xs font-semibold rounded bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300">Inactivo</span>
                @endif
            </td>
            <td class="px-6 py-4 sticky-col-right">
                <div class="flex justify-end gap-2">
                    @can($def['permiso'].'.edit')
                        <x-secondary-button type="button" class="py-1.5 px-2" title="Editar" x-on:click.prevent="$dispatch('load-{{ $modal }}-form', '{{ route('admin.catalogos.'.$catalogo.'.edit', $item->id) }}'); $dispatch('open-modal', '{{ $modal }}')">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg>
                        </x-secondary-button>
                    @endcan
                    @can($def['permiso'].'.delete')
                        <x-danger-button type="button" class="py-1.5 px-2" title="Eliminar" x-on:click.prevent="deleteItem({{ $item->id }}, {{ \Illuminate\Support\Js::from($item->nombre) }}, '{{ route('admin.catalogos.'.$catalogo.'.destroy', $item->id) }}')">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                        </x-danger-button>
                    @endcan
                </div>
            </td>
        </tr>
    @empty
        <tr><td colspan="5" class="px-6 py-12 text-center text-gray-500 dark:text-gray-400">No se encontraron registros.</td></tr>
    @endforelse
</tbody>
