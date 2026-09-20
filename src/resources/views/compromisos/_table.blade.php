@forelse($compromisos as $compromiso)
<tr data-record-id="{{ $compromiso->id }}" class="border-t border-gray-200 dark:border-gray-700">
    <td class="px-6 py-4">{{ $compromiso->descripcion }}</td>
    <td class="px-6 py-4">{{ auth()->user()->monedaPreferida?->simbolo ?? '$' }} {{ number_format((float) $compromiso->monto, 2) }}</td>
    <td class="px-6 py-4">{{ $compromiso->fecha_esperada?->format('d/m/Y') ?? 'Sin fecha' }}</td>
    <td class="px-6 py-4">{{ $compromiso->recurrencia_tipo === 'unica' ? 'Una vez' : "Cada {$compromiso->recurrencia_intervalo} {$compromiso->recurrencia_unidad}" }}</td>
    <td class="px-6 py-4">{{ ucfirst($compromiso->estado) }}</td>
    <td class="px-6 py-4 sticky-col-right"><div class="flex justify-end gap-2">
        @can('compromisos.edit')
            <x-secondary-button type="button" class="py-1.5 px-2" x-on:click.prevent="$dispatch('load-compromiso-modal-form', '{{ route('compromisos.edit', $compromiso) }}?modal=1'); $dispatch('open-modal', 'compromiso-modal')"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg></x-secondary-button>
        @endcan
        <x-secondary-button type="button" class="py-1.5 px-2" title="Ver detalle" x-on:click.prevent="window.location='{{ route('compromisos.show', $compromiso) }}'"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12H9m12 0a9 9 0 11-18 0 9 9 0 0118 0z" /></svg></x-secondary-button>
        @can('compromisos.delete')
            <x-danger-button type="button" class="py-1.5 px-2" x-on:click.prevent="deleteItem({{ $compromiso->id }}, '{{ addslashes($compromiso->descripcion) }}', '{{ route('compromisos.destroy', $compromiso) }}')"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg></x-danger-button>
        @endcan
    </div></td>
</tr>
@empty
<tr><td colspan="6" class="px-6 py-10 text-center text-gray-500">No hay compromisos registrados.</td></tr>
@endforelse
