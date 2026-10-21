@php($simbolo = auth()->user()->monedaPreferida?->simbolo ?? '$')
{{-- Al llegar con ?resaltar=ID se marca ese movimiento dentro del estado de cuenta --}}
<div class="space-y-4" x-data x-init="$nextTick(() => window.resaltarDesdeUrl($el))">
    <div class="flex items-center justify-between">
        <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">{{ $estado->tarjeta->nombre }} · corte {{ $estado->fecha_corte->format('d/m/Y') }}</h2>
        <button type="button" x-on:click="$dispatch('close-modal', 'estado-modal')" class="text-gray-400 hover:text-gray-500">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
        </button>
    </div>
    <p class="text-sm text-gray-600 dark:text-gray-300">Período del {{ $estado->fecha_inicio->format('d/m/Y') }} al {{ $estado->fecha_corte->format('d/m/Y') }} · Fecha límite: {{ $estado->fecha_limite_pago->format('d/m/Y') }}</p>
    <div class="max-h-96 overflow-y-auto rounded-lg border border-gray-200 dark:border-gray-700">
        <table class="w-full text-sm text-left text-gray-700 dark:text-gray-300">
            <tbody>
                @forelse ($estado->items as $item)
                    <tr class="border-b border-gray-100 dark:border-gray-700" data-movimiento="{{ $item->movimiento_tarjeta_id }}">
                        <td class="px-4 py-2 whitespace-nowrap">{{ $item->movimiento->fecha->format('d/m/Y') }}</td>
                        <td class="px-4 py-2">{{ $item->movimiento->descripcion }}@if ($item->cuota_numero)<span class="ml-1 text-xs text-gray-400">cuota {{ $item->cuota_numero }} de {{ $item->movimiento->cuotas }}</span>@endif</td>
                        <td class="px-4 py-2 text-right whitespace-nowrap">{{ $simbolo }} {{ number_format((float) $item->monto, 2) }}</td>
                    </tr>
                @empty
                    <tr><td class="px-4 py-6 text-center text-gray-500">Sin movimientos en este período.</td></tr>
                @endforelse
            </tbody>
            <tfoot class="font-semibold text-gray-900 dark:text-gray-100">
                <tr><td colspan="2" class="px-4 py-3 text-right">Total del estado de cuenta</td><td class="px-4 py-3 text-right whitespace-nowrap">{{ $simbolo }} {{ number_format((float) $estado->total, 2) }}</td></tr>
            </tfoot>
        </table>
    </div>
    @if ($estado->compromiso)
        <p class="text-sm text-gray-600 dark:text-gray-300">Pagado: {{ $simbolo }} {{ number_format($estado->compromiso->montoPagado(), 2) }} · Pendiente: {{ $simbolo }} {{ number_format((float) $estado->compromiso->saldo_pendiente, 2) }}</p>
    @endif
</div>
