@forelse($movimientos as $movimiento)
    <tr data-record-id="{{ $movimiento->id }}" class="border-b dark:border-gray-700">
        <td class="px-6 py-4">{{ $movimiento->descripcion }}</td>
        <td class="px-6 py-4">{{ $simbolo ?? '$' }} {{ number_format((float) $movimiento->monto, 2) }}</td>
        <td class="px-6 py-4">{{ $movimiento->fecha->format('d/m/Y') }}</td>
        <td class="px-6 py-4">
            <span class="rounded-full px-2 py-1 text-xs font-medium {{ $movimiento->tipo === 'ingreso' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' }}">{{ ucfirst($movimiento->tipo) }}</span>
        </td>
        <td class="px-6 py-4">
            @if ($movimiento->categoria)
                <span class="rounded-full px-2 py-1 text-xs font-medium" style="background-color: {{ $movimiento->categoria->color }}; color: {{ $movimiento->categoria->textColor() }}">{{ $movimiento->categoria->nombre }}</span>
            @else
                —
            @endif
        </td>
        <td class="px-6 py-4 sticky-col-right">
            <div class="flex justify-end gap-2">
                @if($isCurrentCycle ?? true) @can('efectivo.edit')
                    <x-secondary-button type="button" class="py-1.5 px-2" x-on:click.prevent="$dispatch('load-efectivo-modal-form', '{{ route('efectivo.edit', $movimiento) }}?modal=1'); $dispatch('open-modal', 'efectivo-modal')">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg>
                    </x-secondary-button>
                @endcan @endif
                @if($isCurrentCycle ?? true) @can('efectivo.delete')
                    <x-danger-button type="button" class="py-1.5 px-2" x-on:click.prevent="deleteItem({{ $movimiento->id }}, '{{ addslashes($movimiento->descripcion) }}', '{{ route('efectivo.destroy', $movimiento) }}')">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                    </x-danger-button>
                @endcan @endif
            </div>
        </td>
    </tr>
@empty
    <tr><td colspan="6" class="px-6 py-12 text-center text-gray-500">No hay movimientos registrados.</td></tr>
@endforelse
