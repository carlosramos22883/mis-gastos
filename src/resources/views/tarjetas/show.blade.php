@php($simbolo = auth()->user()->monedaPreferida?->simbolo ?? '$')
<x-app-layout>
    <x-slot name="header"><h1>{{ $tarjeta->nombre }}</h1></x-slot>
    <div class="max-w-7xl mx-auto space-y-6" x-data x-on:refresh-table.window="$event.detail?.redirect ? window.location = $event.detail.redirect : window.location.reload()">
        <div class="grid gap-6 lg:grid-cols-3">
            <div>@include('tarjetas._tarjeta')</div>
            <div class="lg:col-span-2 space-y-4">
                <div class="flex flex-wrap gap-2">
                    <x-secondary-button type="button" onclick="window.location='{{ route('tarjetas.index') }}'">Todas las tarjetas</x-secondary-button>
                    @if ($tarjeta->activo)
                        @can('tarjetas.create')
                            <x-primary-button type="button" x-on:click="$dispatch('load-movimiento-modal-form', '{{ route('tarjetas.movimientos.create', [$tarjeta, 'tipo' => 'compra']) }}'); $dispatch('open-modal', 'movimiento-modal')">Registrar compra</x-primary-button>
                            <x-secondary-button type="button" x-on:click="$dispatch('load-movimiento-modal-form', '{{ route('tarjetas.movimientos.create', [$tarjeta, 'tipo' => 'cargo']) }}'); $dispatch('open-modal', 'movimiento-modal')">Cargo o crédito</x-secondary-button>
                        @endcan
                        @if ($tarjeta->permite_avances)
                        @can('cuentas.create')
                            <x-secondary-button type="button" title="Sacar dinero con la tarjeta hacia el efectivo o una cuenta (ej. Chivo)" x-on:click="$dispatch('load-transferencia-modal-form', '{{ route('transferencias.create', ['origen' => 'tarjeta:'.$tarjeta->id]) }}'); $dispatch('open-modal', 'transferencia-modal')">Avance</x-secondary-button>
                        @endcan
                        @endif
                    @endif
                    @can('tarjetas.edit')
                        <x-secondary-button type="button" x-on:click="$dispatch('load-tarjeta-modal-form', '{{ route('tarjetas.edit', $tarjeta) }}'); $dispatch('open-modal', 'tarjeta-modal')">Editar</x-secondary-button>
                    @endcan
                    @can('tarjetas.delete')
                        <x-danger-button type="button" x-on:click="deleteItem({{ $tarjeta->id }}, {{ \Illuminate\Support\Js::from($tarjeta->nombre) }}, '{{ route('tarjetas.destroy', $tarjeta) }}')">Eliminar</x-danger-button>
                    @endcan
                </div>
                <div class="grid gap-4 sm:grid-cols-3">
                    @foreach ([
                        ['Corte', 'Día '.($tarjeta->dia_corte === 31 ? 'último' : $tarjeta->dia_corte)],
                        ['Pago', 'Día '.($tarjeta->dia_pago === 31 ? 'último' : $tarjeta->dia_pago)],
                        ['Tasa de interés', $tarjeta->tasa_interes ? $tarjeta->tasa_interes.' %' : '—'],
                    ] as [$label, $valor])
                        <div class="rounded-lg bg-white p-4 shadow dark:bg-gray-800">
                            <p class="text-xs text-gray-500 dark:text-gray-400">{{ $label }}</p>
                            <p class="mt-1 font-semibold text-gray-900 dark:text-gray-100">{{ $valor }}</p>
                        </div>
                    @endforeach
                </div>
                @if ($porPagar->isNotEmpty())
                    <div class="rounded-lg border border-sky-200 bg-sky-50 p-4 text-sm text-sky-900 dark:border-sky-800 dark:bg-sky-900/20 dark:text-sky-100" x-data="{ ver: false }">
                        @foreach ($porPagar as $p)
                            <p>
                                @if ($p['costo'])
                                    Si no pagas completo <strong>{{ $p['compromiso']->descripcion }}</strong>{{ $p['compromiso']->fecha_esperada ? ' antes del '.$p['compromiso']->fecha_esperada->format('d/m/Y') : '' }}:
                                    ≈ <strong>{{ $simbolo }} {{ number_format($p['costo']['interes_mes'], 2) }}</strong> de intereses al mes ({{ rtrim(rtrim(number_format($p['costo']['tasa'], 2), '0'), '.') }} % anual sobre {{ $simbolo }} {{ number_format($p['costo']['pendiente'], 2) }}) + el cargo por pago tardío.
                                @else
                                    <strong>{{ $p['compromiso']->descripcion }}</strong>: {{ $simbolo }} {{ number_format((float) $p['compromiso']->saldo_pendiente, 2) }} pendientes. Agrega la <em>tasa de interés</em> de la tarjeta (Editar) para ver cuánto te costaría no pagarlo completo.
                                @endif
                            </p>
                        @endforeach
                        <button type="button" class="mt-2 text-xs font-medium underline" x-on:click="ver = !ver">¿Cómo cobran intereses las tarjetas?</button>
                        <ul x-show="ver" x-cloak class="mt-2 list-disc space-y-1 pl-5 text-xs">
                            <li>Si pagas <strong>el total</strong> del estado de cuenta antes de la fecha límite, no pagas intereses (por eso pasar una tarjeta al siguiente ciclo no cuesta nada mientras no llegue su fecha límite).</li>
                            <li>Si pagas <strong>menos del total</strong> (aunque sea el mínimo), cobran intereses sobre lo que quedó, y muchos bancos los cuentan desde la fecha de cada compra, no desde la fecha límite: el cálculo de arriba es un mínimo.</li>
                            <li>Si no pagas <strong>ni el mínimo</strong> a tiempo: cargo por pago tardío (más IVA), intereses moratorios y reporte en tu historial de crédito. Regístralo con «Registrar mora» en el estado de cuenta vencido.</li>
                            <li>Las comisiones y cargos (seguros, membresía, «beneficio portafolio») llevan IVA del 13 %.</li>
                        </ul>
                    </div>
                @endif
                @if ($otrosCompromisos->isNotEmpty())
                    <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800 dark:border-amber-800 dark:bg-amber-900/30 dark:text-amber-200">
                        @foreach ($otrosCompromisos as $c)
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <span>{{ $c->descripcion }}: <strong>{{ $simbolo }} {{ number_format((float) $c->saldo_pendiente, 2) }}</strong>{{ $c->fecha_esperada ? ' · vence '.$c->fecha_esperada->format('d/m/Y') : '' }}</span>
                                @canany(['efectivo.create', 'cuentas.create'])
                                    <button type="button" class="font-medium underline" x-on:click="$dispatch('load-pago-modal-form', '{{ route('compromisos.pagar', $c) }}'); $dispatch('open-modal', 'pago-modal')">Pagar</button>
                                @endcanany
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>

        {{-- Lo que entrará en el próximo corte --}}
        <section class="rounded-lg bg-white shadow dark:bg-gray-800">
            <div class="flex flex-wrap items-center justify-between gap-2 px-6 pt-5">
                <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100">Por facturar en el corte del {{ $tarjeta->proximo_corte->format('d/m/Y') }}</h2>
                <span class="text-lg font-bold text-gray-900 dark:text-gray-100">{{ $simbolo }} {{ number_format($porFacturar->sum('monto'), 2) }}</span>
            </div>
            <div class="overflow-x-auto">
                <table class="mt-3 w-full text-sm text-left text-gray-700 dark:text-gray-300">
                    <thead class="text-xs uppercase bg-gray-50 text-gray-500 dark:bg-gray-700 dark:text-gray-400">
                        <tr><th class="px-6 py-3">Fecha</th><th class="px-6 py-3">Descripción</th><th class="px-6 py-3">Tipo</th><th class="px-6 py-3 text-right">Monto</th><th class="px-6 py-3 text-right">Acciones</th></tr>
                    </thead>
                    <tbody>
                        @forelse ($porFacturar as $linea)
                            @php($m = $linea['movimiento'])
                            <tr class="border-t border-gray-200 dark:border-gray-700">
                                <td class="px-6 py-3 whitespace-nowrap">{{ $m->fecha->format('d/m/Y') }}</td>
                                <td class="px-6 py-3">@include('hogar._por', ['id' => $m->user_id]){{ $m->descripcion }}@include('contactos._chip', ['contacto' => $m->contacto])@if ($m->categoria)<span class="ml-2 text-xs text-gray-400">{{ $m->categoria->nombre }}</span>@endif</td>
                                <td class="px-6 py-3 whitespace-nowrap">{{ $linea['cuota_numero'] ? 'Cuota '.$linea['cuota_numero'].' de '.$m->cuotas : \App\Models\MovimientoTarjeta::TIPOS[$m->tipo] }}</td>
                                <td class="px-6 py-3 text-right whitespace-nowrap {{ $linea['monto'] < 0 ? 'text-green-700 dark:text-green-400' : '' }}">{{ $simbolo }} {{ number_format($linea['monto'], 2) }}</td>
                                <td class="px-6 py-3">
                                    <div class="flex justify-end gap-2">
                                        @if (app(\App\Services\TarjetaService::class)->esEditable($m))
                                            @can('tarjetas.edit')
                                                <x-secondary-button type="button" class="py-1.5 px-2" title="Editar" x-on:click="$dispatch('load-movimiento-modal-form', '{{ route('tarjetas.movimientos.edit', [$tarjeta, $m]) }}'); $dispatch('open-modal', 'movimiento-modal')">
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg>
                                                </x-secondary-button>
                                            @endcan
                                            @can('tarjetas.delete')
                                                <x-danger-button type="button" class="py-1.5 px-2" title="Eliminar" x-on:click="deleteItem({{ $m->id }}, {{ \Illuminate\Support\Js::from($m->descripcion) }}, '{{ route('tarjetas.movimientos.destroy', [$tarjeta, $m]) }}')">
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                                                </x-danger-button>
                                            @endcan
                                        @elseif ($m->transferencia)
                                            @include('transferencias._eliminar', ['transferencia' => $m->transferencia])
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-6 py-8 text-center text-gray-500">Nada por facturar en este período.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        @if ($enCuotas->isNotEmpty())
            <section class="rounded-lg bg-white p-6 shadow dark:bg-gray-800">
                <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100">Compras en cuotas (tasa cero)</h2>
                <div class="mt-4 space-y-4">
                    @foreach ($enCuotas as $compra)
                        <div>
                            <div class="flex flex-wrap justify-between gap-2 text-sm text-gray-700 dark:text-gray-300">
                                <span><strong>{{ $compra->descripcion }}</strong> · {{ $simbolo }} {{ number_format((float) $compra->monto, 2) }} en {{ $compra->cuotas }} cuotas de {{ $simbolo }} {{ number_format((float) $compra->monto / $compra->cuotas, 2) }}</span>
                                <span>{{ $compra->items_count }} de {{ $compra->cuotas }} facturadas</span>
                            </div>
                            <div class="mt-1 h-2 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700">
                                <div class="h-full rounded-full bg-primary-500" style="width: {{ $compra->items_count / $compra->cuotas * 100 }}%"></div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

        {{-- Estados de cuenta generados en cada corte --}}
        <section class="rounded-lg bg-white shadow dark:bg-gray-800">
            <h2 class="px-6 pt-5 text-base font-semibold text-gray-900 dark:text-gray-100">Estados de cuenta</h2>
            <div class="overflow-x-auto">
                <table class="mt-3 w-full text-sm text-left text-gray-700 dark:text-gray-300">
                    <thead class="text-xs uppercase bg-gray-50 text-gray-500 dark:bg-gray-700 dark:text-gray-400">
                        <tr><th class="px-6 py-3">Corte</th><th class="px-6 py-3">Total</th><th class="px-6 py-3">Fecha límite</th><th class="px-6 py-3">Pago</th><th class="px-6 py-3 text-right">Acciones</th></tr>
                    </thead>
                    <tbody>
                        @forelse ($estados as $estado)
                            @php($compromiso = $estado->compromiso)
                            @php($estadoVisual = $compromiso?->estadoVisual($hoy, $inicioCiclo))
                            <tr class="border-t border-gray-200 dark:border-gray-700">
                                <td class="px-6 py-3 whitespace-nowrap">{{ $estado->fecha_corte->format('d/m/Y') }}
                                    <span class="block text-xs text-gray-400">desde {{ $estado->fecha_inicio->format('d/m/Y') }}</span></td>
                                <td class="px-6 py-3 whitespace-nowrap font-semibold">{{ $simbolo }} {{ number_format((float) $estado->total, 2) }}</td>
                                <td class="px-6 py-3 whitespace-nowrap">
                                    <div x-data="{ editando: false, fecha: '{{ $estado->fecha_limite_pago->toDateString() }}' }">
                                        <span x-show="!editando">{{ $estado->fecha_limite_pago->format('d/m/Y') }}
                                            @can('tarjetas.edit')
                                                @if (! $compromiso || $compromiso->estado === 'pendiente')
                                                    <button type="button" class="ml-1 text-xs text-primary-600 hover:underline dark:text-primary-400" x-on:click="editando = true">cambiar</button>
                                                @endif
                                            @endcan
                                        </span>
                                        <span x-show="editando" x-cloak class="flex items-center gap-1">
                                            <input type="date" x-model="fecha" class="rounded border-gray-300 px-2 py-1 text-xs dark:border-gray-600 dark:bg-gray-700">
                                            <button type="button" class="text-xs font-medium text-primary-600 dark:text-primary-400"
                                                x-on:click="fetch('{{ route('estados-cuenta.fecha-limite', $estado) }}', { method: 'PATCH', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content }, body: JSON.stringify({ fecha_limite_pago: fecha }) })
                                                    .then(async r => { const d = await r.json(); if (!r.ok) throw d; await showAlert('success', '¡Listo!', d.message); window.location.reload(); })
                                                    .catch(e => showAlert('error', 'Error', e?.message || 'No se pudo actualizar.'))">Guardar</button>
                                            <button type="button" class="text-xs text-gray-500" x-on:click="editando = false">Cancelar</button>
                                        </span>
                                    </div>
                                </td>
                                <td class="px-6 py-3">
                                    @if ($compromiso)
                                        @include('compromisos._estado', ['estado' => $estadoVisual])
                                        @if ($compromiso->estado === 'pendiente' && $compromiso->montoPagado() > 0)
                                            <span class="block text-xs text-gray-500">Pendiente {{ $simbolo }} {{ number_format((float) $compromiso->saldo_pendiente, 2) }}</span>
                                        @endif
                                    @else
                                        <span class="text-xs text-gray-500">Sin saldo</span>
                                    @endif
                                </td>
                                <td class="px-6 py-3">
                                    <div class="flex justify-end gap-3 text-xs font-medium">
                                        <button type="button" class="text-primary-600 hover:underline dark:text-primary-400" x-on:click="$dispatch('load-estado-modal-form', '{{ route('estados-cuenta.show', $estado) }}'); $dispatch('open-modal', 'estado-modal')">Detalle</button>
                                        @if ($compromiso?->estado === 'pendiente')
                                            @canany(['efectivo.create', 'cuentas.create'])
                                                <button type="button" class="text-primary-600 hover:underline dark:text-primary-400" x-on:click="$dispatch('load-pago-modal-form', '{{ route('compromisos.pagar', $compromiso) }}'); $dispatch('open-modal', 'pago-modal')">Pagar</button>
                                            @endcanany
                                            @if (in_array($estadoVisual, ['vencido', 'antiguo'], true))
                                                @can('tarjetas.create')
                                                    <button type="button" class="text-red-600 hover:underline dark:text-red-400" x-on:click="$dispatch('load-mora-modal-form', '{{ route('estados-cuenta.mora.create', $estado) }}'); $dispatch('open-modal', 'mora-modal')">Registrar mora</button>
                                                @endcan
                                            @endif
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-6 py-8 text-center text-gray-500">Aún no hay cortes. El primero se genera el {{ $tarjeta->proximo_corte->format('d/m/Y') }}.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
    @foreach (['tarjeta', 'movimiento', 'transferencia', 'estado', 'pago', 'mora'] as $modal)
        <x-ajax-modal :name="$modal" />
    @endforeach
</x-app-layout>
