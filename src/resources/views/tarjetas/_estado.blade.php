@php($simbolo = auth()->user()->monedaPreferida?->simbolo ?? '$')
{{-- Al llegar con ?resaltar=ID se marca ese movimiento dentro del estado de cuenta --}}
{{-- Al pasar a otro modal se avisa por window: cerrar este vacía su contenido (y el botón que se pulsó ya no puede despachar eventos) --}}
{{-- window.volverAlEstado: al guardar o eliminar desde aquí, la pantalla se recarga con este estado de cuenta abierto --}}
<div class="space-y-4" x-init="$nextTick(() => window.resaltarDesdeUrl($el))"
    x-data="{ abrirMovimiento(url, modal = 'movimiento') { window.volverAlEstado = {{ $estado->id }}; $dispatch('close-modal', 'estado-modal'); window.dispatchEvent(new CustomEvent('load-' + modal + '-modal-form', { detail: url })); window.dispatchEvent(new CustomEvent('open-modal', { detail: modal + '-modal' })); } }">
    <div class="flex items-center justify-between">
        <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">{{ $estado->tarjeta->nombre }} · corte {{ $estado->fecha_corte->format('d/m/Y') }}</h2>
        <button type="button" x-on:click="$dispatch('close-modal', 'estado-modal')" class="text-gray-400 hover:text-gray-500">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
        </button>
    </div>
    <p class="text-sm text-gray-600 dark:text-gray-300">Período del {{ $estado->fecha_inicio->format('d/m/Y') }} al {{ $estado->fecha_corte->format('d/m/Y') }} · Fecha límite: {{ $estado->fecha_limite_pago->format('d/m/Y') }}</p>
    {{-- Por defecto por fecha (y en el mismo día, en el orden en que se anotaron); los encabezados reordenan en el navegador --}}
    <div class="max-h-96 overflow-y-auto rounded-lg border border-gray-200 dark:border-gray-700" x-data="tablaOrdenable('fecha', 'asc')">
        <table class="w-full text-sm text-left text-gray-700 dark:text-gray-300">
            <thead class="sticky top-0 text-xs text-gray-600 uppercase bg-cabecera dark:bg-gray-700 dark:text-gray-400">
                <tr>
                    <x-th-ordenable campo="fecha" class="px-4 py-2">Fecha</x-th-ordenable>
                    <x-th-ordenable campo="descripcion" class="px-4 py-2">Descripción</x-th-ordenable>
                    <x-th-ordenable campo="monto" class="px-4 py-2 text-right">Monto</x-th-ordenable>
                    <th class="px-2 py-2"><span class="sr-only">Acciones</span></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($estado->items->sortBy(fn ($i) => $i->movimiento->fecha->toDateString().'-'.str_pad((string) $i->id, 10, '0', STR_PAD_LEFT))->values() as $item)
                    <tr class="border-b border-gray-100 dark:border-gray-700" data-movimiento="{{ $item->movimiento_tarjeta_id }}"
                        data-orden-fecha="{{ $item->movimiento->fecha->toDateString() }}-{{ sprintf('%010d', $item->id) }}" data-orden-descripcion="{{ $item->movimiento->descripcion }}" data-orden-monto="{{ (float) $item->monto }}">
                        <td class="px-4 py-2 whitespace-nowrap">{{ $item->movimiento->fecha->format('d/m/Y') }}</td>
                        <td class="px-4 py-2">{{ $item->movimiento->descripcion }}@if ($item->cuota_numero)<span class="ml-1 text-xs text-gray-400">cuota {{ $item->cuota_numero }} de {{ $item->movimiento->cuotas }}</span>@endif</td>
                        <td class="px-4 py-2 text-right whitespace-nowrap">{{ $simbolo }} {{ number_format((float) $item->monto, 2) }}</td>
                        <td class="w-px px-2 py-2">
                            <div class="flex justify-end gap-1">
                                @can('deudores.create')
                                    @if (app(\App\Services\DeudorService::class)->vinculable($item->movimiento))
                                        <button type="button" class="rounded p-1 text-gray-400 hover:text-primary-600 dark:hover:text-primary-400" title="Me lo debe (alguien te debe este gasto)"
                                            x-on:click="abrirMovimiento('{{ route('movimientos.deuda.create', ['tarjeta', $item->movimiento_tarjeta_id]) }}', 'deuda')">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 15v-1a4 4 0 00-4-4H8m0 0l3 3m-3-3l3-3m9 14V5a2 2 0 00-2-2H6a2 2 0 00-2 2v16l4-2 4 2 4-2 4 2z" /></svg>
                                        </button>
                                    @endif
                                @endcan
                                {{-- Corregir un error de un corte pasado: el total y el pago pendiente se ajustan --}}
                                @if (app(\App\Services\TarjetaService::class)->esEditable($item->movimiento))
                                    @can('tarjetas.edit')
                                        <button type="button" class="rounded p-1 text-gray-400 hover:text-primary-600 dark:hover:text-primary-400" title="Corregir"
                                            x-on:click="abrirMovimiento('{{ route('tarjetas.movimientos.edit', [$estado->tarjeta, $item->movimiento]) }}')">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg>
                                        </button>
                                    @endcan
                                    @can('tarjetas.delete')
                                        <button type="button" class="rounded p-1 text-gray-400 hover:text-red-600 dark:hover:text-red-400" title="Eliminar"
                                            x-on:click="const url = '{{ route('tarjetas.movimientos.destroy', [$estado->tarjeta, $item->movimiento]) }}'; window.volverAlEstado = {{ $estado->id }}; $dispatch('close-modal', 'estado-modal'); deleteItem({{ $item->movimiento->id }}, {{ \Illuminate\Support\Js::from($item->movimiento->descripcion) }}, url)">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                                        </button>
                                    @endcan
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-6 text-center text-gray-500">Sin movimientos en este período.</td></tr>
                @endforelse
            </tbody>
            <tfoot class="font-semibold text-gray-900 dark:text-gray-100">
                @if ((float) $estado->saldo_anterior < 0)
                    <tr class="font-normal text-green-700 dark:text-green-400"><td colspan="2" class="px-4 py-2 text-right">Saldo a favor del corte anterior</td><td class="px-4 py-2 text-right whitespace-nowrap">{{ $simbolo }} {{ number_format((float) $estado->saldo_anterior, 2) }}</td><td></td></tr>
                @endif
                <tr><td colspan="2" class="px-4 py-3 text-right">Total del estado de cuenta</td><td class="px-4 py-3 text-right whitespace-nowrap">{{ $simbolo }} {{ number_format((float) $estado->total, 2) }}</td><td></td></tr>
            </tfoot>
        </table>
    </div>
    @if ($estado->tarjeta->activo)
        @can('tarjetas.create')
            {{-- Algo que faltó anotar en este corte --}}
            <div class="flex flex-wrap gap-2">
                <x-secondary-button type="button" x-on:click="abrirMovimiento('{{ route('tarjetas.movimientos.create', [$estado->tarjeta, 'tipo' => 'compra', 'estado' => $estado->id]) }}')">Agregar compra</x-secondary-button>
                <x-secondary-button type="button" x-on:click="abrirMovimiento('{{ route('tarjetas.movimientos.create', [$estado->tarjeta, 'tipo' => 'cargo', 'estado' => $estado->id]) }}')">Agregar cargo o crédito</x-secondary-button>
            </div>
        @endcan
    @endif
    {{-- Intereses bonificables del estado de cuenta del banco: se restan del disponible hasta el corte siguiente --}}
    <div class="text-sm text-gray-600 dark:text-gray-300" x-data="{ editando: false }">
        <div x-show="!editando" class="flex flex-wrap items-baseline gap-x-2">
            <span>Intereses bonificables: {{ $simbolo }} {{ number_format((float) $estado->intereses_bonificables, 2) }}</span>
            @can('tarjetas.edit')
                <button type="button" class="text-xs text-primary-600 hover:underline dark:text-primary-400" x-on:click="editando = true">cambiar</button>
            @endcan
            <span class="block w-full text-xs text-gray-400">Los trae tu estado de cuenta. El banco los aparta del disponible hasta el próximo corte, aunque ya hayas pagado. Si pagaste el total a tiempo, ahí te los devuelve; si no, te los cobra.</span>
        </div>
        @can('tarjetas.edit')
            <form x-show="editando" x-cloak method="POST" action="{{ route('estados-cuenta.intereses', $estado) }}" class="flex flex-wrap items-start gap-3" novalidate
                x-data="ajaxForm(async function(data) { window.volverAlEstado = {{ $estado->id }}; await showAlert('success', '¡Listo!', data.message); window.dispatchEvent(new CustomEvent('refresh-table', { detail: data })); })"
                @submit.prevent="submit">
                @csrf
                @method('PATCH')
                <div class="w-48"><x-floating-money id="intereses_bonificables" label="Intereses bonificables" :symbol="$simbolo" :value="number_format((float) $estado->intereses_bonificables, 2, '.', '')" /></div>
                <x-secondary-button type="button" x-on:click="editando = false">Cancelar</x-secondary-button>
                <x-primary-button type="submit" x-bind:disabled="loading">Guardar</x-primary-button>
            </form>
        @endcan
    </div>
    @if ($estado->compromiso)
        <p class="text-sm text-gray-600 dark:text-gray-300">Pagado: {{ $simbolo }} {{ number_format($estado->compromiso->montoPagado(), 2) }} · Pendiente: {{ $simbolo }} {{ number_format((float) $estado->compromiso->saldo_pendiente, 2) }}</p>
        @if (($excedente = app(\App\Services\TarjetaService::class)->excedente($estado->compromiso)) > 0)
            <p class="text-sm text-green-700 dark:text-green-400">Se pagaron {{ $simbolo }} {{ number_format($excedente, 2) }} de más: quedaron como crédito en la tarjeta.</p>
        @endif
    @elseif ((float) $estado->total < 0)
        <p class="text-sm text-green-700 dark:text-green-400">Este corte quedó a favor: los {{ $simbolo }} {{ number_format(-(float) $estado->total, 2) }} se descuentan del corte siguiente.</p>
    @endif
</div>
