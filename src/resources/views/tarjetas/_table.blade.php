@forelse($tarjetas as $tarjeta)
    <tr data-record-id="{{ $tarjeta->id }}" class="border-b dark:border-gray-700">
        <td class="px-6 py-4">
            <div class="flex items-center gap-2">
                <img src="{{ $tarjeta->banco?->logo ? asset('storage/'.$tarjeta->banco->logo) : 'https://ui-avatars.com/api/?name='.urlencode($tarjeta->banco?->nombre ?? 'Banco').'&background=0a0a5e&color=fff' }}" alt="" class="h-8 w-8 rounded object-contain border border-gray-200">
                <img src="{{ $tarjeta->marcaRed?->logo ? asset('storage/'.$tarjeta->marcaRed->logo) : 'https://ui-avatars.com/api/?name='.urlencode($tarjeta->marcaRed?->nombre ?? 'Red').'&background=64748b&color=fff' }}" alt="" class="h-8 w-8 rounded object-contain border border-gray-200">
                <span class="font-medium">{{ $tarjeta->nombre }}</span>
            </div>
            <div class="text-xs text-gray-500">{{ $tarjeta->marcaRed?->nombre ?? 'Sin marca' }} · {{ $tarjeta->ultimos_digitos ? '**** '.$tarjeta->ultimos_digitos : 'Sin últimos dígitos' }}</div>
        </td>
        <td class="px-6 py-4">{{ $tarjeta->banco?->nombre ?? 'Sin banco' }}</td>
        <td class="px-6 py-4">{{ auth()->user()->monedaPreferida?->simbolo ?? '$' }} {{ number_format((float) $tarjeta->limite_credito, 2) }}</td>
        <td class="px-6 py-4">{{ auth()->user()->monedaPreferida?->simbolo ?? '$' }} {{ number_format((float) $tarjeta->saldo_actual, 2) }}</td>
        <td class="px-6 py-4">{{ auth()->user()->monedaPreferida?->simbolo ?? '$' }} {{ number_format($tarjeta->disponible(), 2) }}</td>
        <td class="px-6 py-4">
            <span class="rounded-full px-2 py-1 text-xs {{ $tarjeta->activo ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-700' }}">{{ $tarjeta->activo ? 'Activa' : 'Inactiva' }}</span>
        </td>
        <td class="px-6 py-4 sticky-col-right"><div class="flex justify-end gap-2">
            @can('tarjetas.edit')
                <x-secondary-button type="button" class="py-1.5 px-2" x-on:click.prevent="$dispatch('load-tarjeta-modal-form', '{{ route('tarjetas.edit', $tarjeta) }}?modal=1'); $dispatch('open-modal', 'tarjeta-modal')">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg>
                </x-secondary-button>
            @endcan
            @can('tarjetas.delete')
                <x-danger-button type="button" class="py-1.5 px-2" x-on:click.prevent="deleteItem({{ $tarjeta->id }}, '{{ addslashes($tarjeta->nombre) }}', '{{ route('tarjetas.destroy', $tarjeta) }}')">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                </x-danger-button>
            @endcan
        </div></td>
    </tr>
@empty
    <tr><td colspan="7" class="px-6 py-12 text-center text-gray-500">No hay tarjetas de crédito registradas.</td></tr>
@endforelse
