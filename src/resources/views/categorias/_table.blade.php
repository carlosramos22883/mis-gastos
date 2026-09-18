@forelse($categorias as $categoria)
    <tr data-record-id="{{ $categoria->id }}" class="border-b dark:border-gray-700">
        <td class="px-6 py-4">
            {{ $categoria->nombre }}
        </td>
        <td class="px-6 py-4">
            <span class="inline-block h-3 w-3 rounded-full mr-2 align-middle" style="background-color: {{ $categoria->color }}"></span>
            <span class="text-xs text-gray-500">{{ $categoria->color }}</span>
        </td>
        <td class="px-6 py-4">
            <span class="rounded-full px-2 py-1 text-xs font-medium {{ $categoria->tipo === 'ingreso' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' }}">{{ ucfirst($categoria->tipo) }}</span>
        </td>
        <td class="px-6 py-4">{{ $categoria->activo ? 'Activa' : 'Inactiva' }}</td>
        <td class="px-6 py-4 sticky-col-right">
            <div class="flex justify-end gap-2">
            @can('categorias.edit')
                <x-secondary-button type="button" class="py-1.5 px-2" x-on:click.prevent="$dispatch('load-categoria-modal-form', '{{ route('categorias.edit', $categoria) }}?modal=1'); $dispatch('open-modal', 'categoria-modal')">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg>
                </x-secondary-button>
            @endcan
            @can('categorias.delete')
                <x-danger-button type="button" class="py-1.5 px-2" x-on:click.prevent="deleteItem({{ $categoria->id }}, '{{ addslashes($categoria->nombre) }}', '{{ route('categorias.destroy', $categoria) }}')">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                </x-danger-button>
            @endcan
            </div>
        </td>
    </tr>
@empty
    <tr><td colspan="5" class="px-6 py-12 text-center text-gray-500 dark:text-gray-400">No hay categorías registradas.</td></tr>
@endforelse
