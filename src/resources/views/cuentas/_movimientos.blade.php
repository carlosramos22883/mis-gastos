@php($simbolo = auth()->user()->monedaPreferida?->simbolo ?? '$')
@forelse($movimientos as $movimiento)
    @php($editable = app(\App\Services\CuentaService::class)->esEditable($movimiento, $inicioActual))
    <tr data-record-id="{{ $movimiento->id }}" class="border-b dark:border-gray-700">
        <td class="px-6 py-4">@include('hogar._por', ['id' => $movimiento->user_id]){{ $movimiento->descripcion }}</td>
        <td class="px-6 py-4 whitespace-nowrap {{ $movimiento->tipo === 'ingreso' ? 'text-green-700 dark:text-green-400' : 'text-red-700 dark:text-red-400' }}">
            {{ $movimiento->tipo === 'ingreso' ? '+' : '−' }} {{ $simbolo }} {{ number_format((float) $movimiento->monto, 2) }}
        </td>
        <td class="px-6 py-4 whitespace-nowrap">{{ $movimiento->fecha->format('d/m/Y') }}</td>
        <td class="px-6 py-4">
            @if ($movimiento->transferencia)
                <span class="rounded-full bg-sky-100 px-2 py-1 text-xs font-medium text-sky-800 dark:bg-sky-900/40 dark:text-sky-300">Transferencia</span>
            @elseif ($movimiento->categoria)
                <span class="rounded-full px-2 py-1 text-xs font-medium" style="background-color: {{ $movimiento->categoria->color }}; color: {{ $movimiento->categoria->textColor() }}">{{ $movimiento->categoria->nombre }}</span>
            @else
                —
            @endif
        </td>
        <td class="px-6 py-4 sticky-col-right">
            <div class="flex justify-end gap-2">
                @if ($editable)
                    @can('cuentas.edit')
                        <x-secondary-button type="button" class="py-1.5 px-2" title="Editar" x-on:click.prevent="$dispatch('load-movimiento-modal-form', '{{ route('cuentas.movimientos.edit', [$cuenta, $movimiento]) }}'); $dispatch('open-modal', 'movimiento-modal')">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg>
                        </x-secondary-button>
                    @endcan
                    @can('cuentas.delete')
                        <x-danger-button type="button" class="py-1.5 px-2" title="Eliminar" x-on:click.prevent="deleteItem({{ $movimiento->id }}, @js($movimiento->descripcion), '{{ route('cuentas.movimientos.destroy', [$cuenta, $movimiento]) }}')">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                        </x-danger-button>
                    @endcan
                @elseif ($movimiento->transferencia && $movimiento->fecha->toDateString() >= $inicioActual->toDateString())
                    @include('transferencias._eliminar', ['transferencia' => $movimiento->transferencia])
                @endif
            </div>
        </td>
    </tr>
@empty
    <tr><td colspan="5" class="px-6 py-10 text-center text-gray-500">No hay movimientos en este ciclo.</td></tr>
@endforelse
