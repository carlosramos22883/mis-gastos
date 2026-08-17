<tbody class="divide-y divide-gray-200 dark:divide-gray-700">
    @forelse($items as $marcaRed)
        <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors">
            <td class="px-6 py-4">
                <div class="flex items-center gap-3">
                    <img src="{{ $marcaRed->logo ? asset('storage/' . $marcaRed->logo) : 'https://ui-avatars.com/api/?name=' . urlencode($marcaRed->nombre) . '&background=0a0a5e&color=fff' }}"
                        alt="{{ $marcaRed->nombre }}"
                        class="w-10 h-10 rounded-lg object-cover border border-gray-200 dark:border-gray-600 shrink-0">
                    <span class="font-medium text-gray-900 dark:text-gray-100">{{ $marcaRed->nombre }}</span>
                </div>
            </td>            
            <td class="px-6 py-4">
                @if($marcaRed->activo)
                    <span class="px-2 py-1 text-xs font-semibold rounded bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200">Activo</span>
                @else
                    <span class="px-2 py-1 text-xs font-semibold rounded bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300">Inactivo</span>
                @endif
            </td>
            <td class="px-6 py-4 sticky-col-right">
                <div class="flex justify-end gap-2">
                    @can('marcas-red.edit')
                        <x-secondary-button type="button" class="py-1.5 px-2" x-on:click.prevent="$dispatch('load-marca-red-modal-form', '{{ route('admin.catalogos.marcas-red.edit', $marcaRed) }}?modal=1'); $dispatch('open-modal', 'marca-red-modal')">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg>
                        </x-secondary-button>
                    @endcan
                    @can('marcas-red.delete')
                        <x-danger-button type="button" class="py-1.5 px-2" x-on:click.prevent="deleteItem({{ $marcaRed->id }}, '{{ $marcaRed->nombre }}', '{{ route('admin.catalogos.marcas-red.destroy', $marcaRed) }}')">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                        </x-danger-button>
                    @endcan
                </div>
            </td>
        </tr>
    @empty
        <tr><td colspan="4" class="px-6 py-12 text-center text-gray-500 dark:text-gray-400">No se encontraron marcas de red.</td></tr>
    @endforelse
</tbody>
