@php($simbolo = auth()->user()->monedaPreferida?->simbolo ?? '$')
<x-app-layout>
    <x-slot name="header"><h1>{{ $tarjeta->nombre }}</h1></x-slot>
    {{-- ?estado=ID abre ese estado de cuenta (viene de "ir al movimiento" de uno ya facturado, o de guardar/eliminar un movimiento desde su detalle) --}}
    @php($abrirEstado = request()->filled('estado') ? $tarjeta->estadosCuenta()->whereKey((int) request('estado'))->first() : null)
    {{-- Un clic en la pantalla (los modales están fuera de este div) olvida el estado de cuenta del que se venía --}}
    <div class="max-w-7xl mx-auto space-y-6" x-data x-on:click.capture="window.volverAlEstado = null"
        x-on:refresh-table.window="if ($event.detail?.redirect) { window.location = $event.detail.redirect } else { const url = new URL(window.location.href); url.searchParams.delete('resaltar'); window.volverAlEstado ? url.searchParams.set('estado', window.volverAlEstado) : url.searchParams.delete('estado'); window.location = url.toString() }"
        @if ($abrirEstado) x-init="$nextTick(() => { $dispatch('load-estado-modal-form', '{{ route('estados-cuenta.show', $abrirEstado) }}'); $dispatch('open-modal', 'estado-modal') })" @endif>
        <div class="grid gap-6 lg:grid-cols-3">
            <div>@include('tarjetas._tarjeta')</div>
            <div class="lg:col-span-2 space-y-4">
                <div class="flex flex-wrap gap-2">
                    <x-volver />
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
                <span class="text-right">
                    @php([$programadas, $cobrado] = $porFacturar->partition(fn ($l) => $l['programada']))
                    <span class="block text-lg font-bold text-gray-900 dark:text-gray-100">{{ $simbolo }} {{ number_format($cobrado->sum('monto') + $saldoAFavor, 2) }}</span>
                    @if ($saldoAFavor < 0)
                        <span class="block text-xs text-green-700 dark:text-green-400">Incluye el saldo a favor del corte anterior ({{ $simbolo }} {{ number_format(-$saldoAFavor, 2) }})</span>
                    @endif
                    {{-- Aún no se cobran: los cargos fijos se registran solos y las cuotas entran el día de su cobro --}}
                    @php($proximos = $cargosProximos->map(fn ($c) => ['fecha' => $c->proximo_cobro, 'texto' => $c->descripcion, 'monto' => (float) $c->monto])
                        ->concat($programadas->map(fn ($l) => ['fecha' => $l['fecha'], 'texto' => 'cuota '.$l['cuota_numero'].' de '.$l['movimiento']->cuotas.' de '.$l['movimiento']->descripcion, 'monto' => $l['monto']]))
                        ->sortBy(fn ($p) => $p['fecha']->toDateString()))
                    @if ($proximos->isNotEmpty())
                        <span class="block text-xs text-gray-500 dark:text-gray-400">Antes del corte se cobrará: {{ $proximos->map(fn ($p) => $p['texto'].' el '.$p['fecha']->format('d/m').' ('.$simbolo.' '.number_format($p['monto'], 2).')')->join(', ') }}</span>
                    @endif
                </span>
            </div>
            {{-- Lo más reciente primero; cada columna se puede ordenar con un clic --}}
            <div class="overflow-x-auto" x-data="tablaOrdenable('fecha', 'desc')">
                <table class="mt-3 w-full text-sm text-left text-gray-700 dark:text-gray-300">
                    <thead class="text-xs uppercase bg-cabecera text-gray-600 dark:bg-gray-700 dark:text-gray-400">
                        <tr>
                            <x-th-ordenable campo="fecha">Fecha</x-th-ordenable>
                            <x-th-ordenable campo="descripcion">Descripción</x-th-ordenable>
                            <x-th-ordenable campo="tipo">Tipo</x-th-ordenable>
                            <x-th-ordenable campo="monto" class="text-right">Monto</x-th-ordenable>
                            <th class="px-6 py-3 text-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($cobrado->sortByDesc(fn ($l) => $l['fecha']->toDateString().sprintf('%010d', $l['movimiento']->id)) as $linea)
                            @php($m = $linea['movimiento'])
                            @php($tipoLinea = $linea['cuota_numero'] ? 'Cuota '.$linea['cuota_numero'].' de '.$m->cuotas : \App\Models\MovimientoTarjeta::TIPOS[$m->tipo])
                            <tr class="border-t border-gray-200 dark:border-gray-700" data-movimiento="{{ $m->id }}"
                                data-orden-fecha="{{ $linea['fecha']->toDateString() }}-{{ sprintf('%010d', $m->id) }}" data-orden-descripcion="{{ $m->descripcion }}" data-orden-tipo="{{ $tipoLinea }}" data-orden-monto="{{ $linea['monto'] }}">
                                <td class="px-6 py-3 whitespace-nowrap">{{ $linea['fecha']->format('d/m/Y') }}</td>
                                <td class="px-6 py-3">@include('hogar._por', ['id' => $m->user_id]){{ $m->descripcion }}@include('contactos._chip', ['contacto' => $m->contacto])@if ($m->categoria)<span class="ml-2 text-xs text-gray-400">{{ $m->categoria->nombre }}</span>@endif</td>
                                <td class="px-6 py-3 whitespace-nowrap">{{ $tipoLinea }}</td>
                                <td class="px-6 py-3 text-right whitespace-nowrap {{ $linea['monto'] < 0 ? 'text-green-700 dark:text-green-400' : '' }}">{{ $simbolo }} {{ number_format($linea['monto'], 2) }}</td>
                                <td class="px-6 py-3">
                                    <div class="flex justify-end gap-2">
                                        @include('contactos._me_lo_debe', ['clase' => 'tarjeta', 'movimiento' => $m])
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
                                <span>
                                    {{ $compra->items_count }} de {{ $compra->cuotas }} facturadas
                                    @if ($compra->dia_cuota)
                                        <span class="block text-right text-xs text-gray-500 dark:text-gray-400">{{ $compra->dia_cuota === 31 ? 'El último día' : 'El día '.$compra->dia_cuota }} de cada mes · próxima: {{ app(\App\Services\TarjetaService::class)->fechaCuota($compra, $compra->items_count + 1)->format('d/m/Y') }}</span>
                                    @endif
                                </span>
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
                    <thead class="text-xs uppercase bg-cabecera text-gray-600 dark:bg-gray-700 dark:text-gray-400">
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
                                    <div x-data="{ editando: false }">
                                        <span x-show="!editando">{{ $estado->fecha_limite_pago->format('d/m/Y') }}
                                            @can('tarjetas.edit')
                                                @if (! $compromiso || $compromiso->estado === 'pendiente')
                                                    <button type="button" class="ml-1 text-xs text-primary-600 hover:underline dark:text-primary-400" x-on:click="editando = true">cambiar</button>
                                                @endif
                                            @endcan
                                        </span>
                                        @can('tarjetas.edit')
                                            <form x-show="editando" x-cloak method="POST" action="{{ route('estados-cuenta.fecha-limite', $estado) }}" class="flex items-start gap-2" novalidate
                                                x-data="ajaxForm(async function(data) { await showAlert('success', '¡Listo!', data.message); window.location.reload(); })" @submit.prevent="submit">
                                                @csrf
                                                @method('PATCH')
                                                <div class="w-40"><x-floating-date id="fecha_limite_pago_{{ $estado->id }}" x-bind:name="'fecha_limite_pago'" label="Fecha límite" :value="$estado->fecha_limite_pago" :min="$estado->fecha_corte" :max="$estado->fecha_corte->copy()->addMonths(3)" /></div>
                                                <x-primary-button type="submit" class="py-2" x-bind:disabled="loading">Guardar</x-primary-button>
                                                <x-secondary-button type="button" class="py-2" x-on:click="editando = false">Cancelar</x-secondary-button>
                                            </form>
                                        @endcan
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

        {{-- Cargos fijos: se agregan solos en cada corte --}}
        <section class="overflow-hidden rounded-lg bg-white shadow dark:bg-gray-800">
            <div class="flex items-center justify-between border-b border-gray-200 px-6 py-4 dark:border-gray-700">
                <div>
                    <h2 class="font-semibold text-gray-900 dark:text-gray-100">Cargos fijos</h2>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Seguro, Netflix… cada mes, el día de su cobro, se registran solos como movimiento de la tarjeta.</p>
                </div>
                @can('tarjetas.edit')
                    <x-secondary-button type="button" x-on:click="$dispatch('load-cargo-modal-form', '{{ route('tarjetas.cargos.create', $tarjeta) }}'); $dispatch('open-modal', 'cargo-modal')">Agregar</x-secondary-button>
                @endcan
            </div>
            <ul class="divide-y divide-gray-100 text-sm dark:divide-gray-700">
                @forelse ($tarjeta->cargosFijos()->with('categoria')->orderByDesc('activo')->orderBy('descripcion')->get() as $cargo)
                    @php($proximo = $cargo->activo ? app(\App\Services\TarjetaService::class)->proximoCobro($cargo) : null)
                    <li class="flex items-center justify-between gap-3 px-6 py-3 {{ $cargo->activo && $proximo ? '' : 'opacity-60' }}">
                        <span class="min-w-0">
                            <span class="block text-gray-800 dark:text-gray-200">{{ $cargo->descripcion }}@unless ($cargo->activo) <span class="text-xs text-gray-500">· inactivo</span>@elseif (! $proximo) <span class="text-xs text-gray-500">· terminado</span>@endunless</span>
                            <span class="text-xs text-gray-500 dark:text-gray-400">
                                {{ $cargo->dia === 31 ? 'El último día' : 'El día '.$cargo->dia }} de cada mes · desde el {{ $cargo->desde->format('d/m/Y') }}{{ $cargo->hasta ? ' hasta el '.$cargo->hasta->format('d/m/Y') : '' }}{{ $proximo ? ' · próximo cobro: '.$proximo->format('d/m/Y') : '' }}{{ $cargo->categoria ? ' · '.$cargo->categoria->nombre : '' }}
                            </span>
                        </span>
                        <span class="flex shrink-0 items-center gap-3">
                            <strong class="text-gray-900 dark:text-gray-100">{{ $simbolo }} {{ number_format((float) $cargo->monto, 2) }}</strong>
                            @can('tarjetas.edit')
                                <button type="button" class="text-primary-600 hover:underline dark:text-primary-400" x-on:click="$dispatch('load-cargo-modal-form', '{{ route('tarjetas.cargos.edit', [$tarjeta, $cargo]) }}'); $dispatch('open-modal', 'cargo-modal')">Editar</button>
                                <button type="button" class="text-red-600 hover:underline dark:text-red-400" x-on:click="deleteItem({{ $cargo->id }}, {{ \Illuminate\Support\Js::from($cargo->descripcion) }}, '{{ route('tarjetas.cargos.destroy', [$tarjeta, $cargo]) }}')">Eliminar</button>
                            @endcan
                        </span>
                    </li>
                @empty
                    <li class="px-6 py-6 text-center text-gray-500 dark:text-gray-400">Sin cargos fijos. Si la tarjeta cobra algo cada mes (un seguro, Netflix…), agrégalo aquí.</li>
                @endforelse
            </ul>
        </section>

        {{-- Pagos hechos a sus estados de cuenta (desde efectivo, una cuenta u otra tarjeta); cada uno lleva a su movimiento --}}
        <section class="overflow-hidden rounded-lg bg-white shadow dark:bg-gray-800">
            <div class="flex items-center justify-between border-b border-gray-200 px-6 py-4 dark:border-gray-700">
                <h2 class="font-semibold text-gray-900 dark:text-gray-100">Pagos realizados</h2>
                @if ($pagos->count() >= 30)<span class="text-sm text-gray-500 dark:text-gray-400">Los 30 más recientes</span>@endif
            </div>
            <ul class="divide-y divide-gray-100 text-sm dark:divide-gray-700">
                @forelse ($pagos as $p)
                    <li><a href="{{ route('movimientos.ver', [$p['clase'], $p['id'], 'desde' => 'tarjeta:'.$tarjeta->id]) }}" class="flex items-center justify-between gap-3 px-6 py-3 hover:bg-gray-50 dark:hover:bg-gray-700/40">
                        <span class="min-w-0">
                            <span class="block truncate text-gray-800 dark:text-gray-200">@include('hogar._por', ['id' => $p['user_id']]){{ $p['descripcion'] }}</span>
                            <span class="text-xs text-gray-500 dark:text-gray-400">{{ $p['fecha']->format('d/m/Y') }} · desde {{ $p['desde'] }}</span>
                        </span>
                        <strong class="shrink-0 text-green-700 dark:text-green-400">{{ $simbolo }} {{ number_format($p['monto'], 2) }}</strong>
                    </a></li>
                @empty
                    <li class="px-6 py-6 text-center text-gray-500 dark:text-gray-400">Aún no hay pagos registrados a esta tarjeta.</li>
                @endforelse
            </ul>
        </section>
    </div>
    @foreach (['tarjeta', 'movimiento', 'transferencia', 'estado', 'pago', 'mora', 'cargo', 'deuda'] as $modal)
        <x-ajax-modal :name="$modal" />
    @endforeach
</x-app-layout>
