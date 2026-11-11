@php
    $simbolo = auth()->user()->monedaPreferida?->simbolo ?? '$';
    $pagador = $evento->pagador();
    $movimiento = $evento->movimiento();
    $insignias = [
        'pagador' => ['Pagó la cuenta', 'bg-primary-50 text-primary-700 dark:bg-primary-900/30 dark:text-primary-300'],
        'festejado' => ['Festejado', 'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300'],
        'hogar' => ['Del hogar', 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300'],
        'pagado' => ['Pagado', 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400'],
        'parcial' => ['Abonó', 'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300'],
        'pendiente' => ['Pendiente', 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400'],
    ];
@endphp
<x-app-layout>
    <x-slot name="header"><h1>{{ $evento->nombre }}</h1></x-slot>
    <div class="max-w-6xl mx-auto space-y-6" x-data x-on:refresh-table.window="$event.detail?.redirect ? window.location = $event.detail.redirect : window.location.reload()">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <a href="{{ route('eventos.index') }}" class="text-sm text-primary-600 hover:underline dark:text-primary-400">← Eventos</a>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                    {{ $evento->fecha->translatedFormat('j \d\e F \d\e Y') }}@if ($evento->lugar) · {{ $evento->lugar }}@endif
                    · Pagó la cuenta: <strong>{{ $pagador?->nombre() }}</strong>
                    @if ($movimiento)
                        · <a class="text-primary-600 hover:underline dark:text-primary-400" href="{{ route('movimientos.ver', [match (true) { $movimiento instanceof \App\Models\MovimientoCuenta => 'cuenta', $movimiento instanceof \App\Models\MovimientoTarjeta => 'tarjeta', default => 'efectivo' }, $movimiento->id]) }}">ver el pago</a>
                    @endif
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <x-primary-button type="button" x-on:click="compartirImagen('{{ route('eventos.imagen', $evento) }}', 'evento-{{ $evento->id }}.png', {{ \Illuminate\Support\Js::from($evento->nombre) }})">Compartir imagen</x-primary-button>
                @can('eventos.edit')<a href="{{ route('eventos.edit', $evento) }}"><x-secondary-button type="button">Editar</x-secondary-button></a>@endcan
                @can('eventos.delete')
                    <x-danger-button type="button" x-on:click="deleteItem({{ $evento->id }}, {{ \Illuminate\Support\Js::from($evento->nombre) }}, '{{ route('eventos.destroy', $evento) }}')">Eliminar</x-danger-button>
                @endcan
            </div>
        </div>

        <div class="grid gap-4 sm:grid-cols-3">
            @foreach ([['Total de la cuenta', $resumen['total'], 'text-gray-900 dark:text-gray-100'], ['Propina', $resumen['propina'], 'text-gray-900 dark:text-gray-100'],
                $resumen['pago_el_hogar'] ? ['Te falta cobrar', $resumen['por_cobrar'], $resumen['por_cobrar'] > 0 ? 'text-red-600 dark:text-red-400' : 'text-green-600 dark:text-green-400'] : ['Consumo', $resumen['subtotal'], 'text-gray-900 dark:text-gray-100']] as [$label, $valor, $color])
                <div class="rounded-lg bg-white p-5 shadow dark:bg-gray-800">
                    <span class="text-sm text-gray-500 dark:text-gray-400">{{ $label }}</span>
                    <strong class="mt-1 block text-2xl {{ $color }}">{{ $simbolo }} {{ number_format($valor, 2) }}</strong>
                </div>
            @endforeach
        </div>

        {{-- La tabla, como en la hoja: personas × productos, total, propina, lo del cumpleañero, lo que paga cada uno y si ya pagó --}}
        @php
            $hayPropina = $resumen['propina'] > 0;
            $hayFestejo = $evento->participantes->contains('es_festejado', true);
            $cantidades = $evento->consumos->mapWithKeys(fn ($c) => [$c->id => $c->participantes->pluck('pivot.cantidad', 'id')]);
        @endphp
        <div class="overflow-x-auto rounded-lg bg-white shadow dark:bg-gray-800">
            <table class="min-w-full text-sm text-gray-700 dark:text-gray-300">
                <thead class="text-xs bg-cabecera text-gray-600 dark:bg-gray-700 dark:text-gray-400">
                    <tr class="align-bottom">
                        <th class="sticky left-0 z-10 bg-cabecera px-4 py-3 text-left uppercase dark:bg-gray-700">Persona</th>
                        @foreach ($evento->consumos as $c)
                            <th class="px-2 py-3 text-right font-medium"><span class="block max-w-[7rem] ml-auto">{{ $c->descripcion }}</span><span class="block text-gray-400">{{ $simbolo }} {{ number_format((float) $c->precio, 2) }}</span></th>
                        @endforeach
                        <th class="px-3 py-3 text-right uppercase">Total</th>
                        @if ($hayPropina)<th class="px-3 py-3 text-right uppercase">Propina</th><th class="px-3 py-3 text-right uppercase">Con propina</th>@endif
                        @if ($hayFestejo)<th class="px-3 py-3 text-right uppercase">Cumpleañero</th>@endif
                        <th class="px-3 py-3 text-right uppercase">A pagar</th>
                        <th class="px-3 py-3 text-right uppercase">Ya pagó</th>
                        <th class="px-3 py-3 text-right uppercase">Debe</th>
                        <th class="px-3 py-3"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($evento->participantes as $p)
                        @php($r = $resumen['participantes'][$p->id])
                        <tr class="border-t border-gray-200 dark:border-gray-700 {{ $p->es_festejado ? 'bg-amber-50 dark:bg-amber-900/10' : '' }}">
                            <td class="sticky left-0 z-10 px-4 py-3 font-medium text-gray-900 dark:text-gray-100 {{ $p->es_festejado ? 'bg-amber-50 dark:bg-gray-800' : 'bg-white dark:bg-gray-800' }}">
                                <span class="inline-flex items-center gap-2 whitespace-nowrap">@if ($p->contacto)<x-avatar-contacto :contacto="$p->contacto" size="sm" />@endif{{ $p->nombre() }}</span>
                                <span class="mt-1 block"><span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $insignias[$r['estado']][1] }}">{{ $insignias[$r['estado']][0] }}</span></span>
                            </td>
                            @foreach ($evento->consumos as $c)
                                @php($n = (int) ($cantidades[$c->id][$p->id] ?? 0))
                                <td class="px-2 py-3 text-right whitespace-nowrap">@if ($n)<span class="text-xs text-gray-400">{{ $n > 1 ? $n.'×' : '' }}</span> {{ number_format($n * (float) $c->precio, 2) }}@endif</td>
                            @endforeach
                            <td class="px-3 py-3 text-right whitespace-nowrap">{{ $simbolo }} {{ number_format($r['consumo'], 2) }}</td>
                            @if ($hayPropina)
                                <td class="px-3 py-3 text-right whitespace-nowrap">{{ $simbolo }} {{ number_format($r['propina'], 2) }}</td>
                                <td class="px-3 py-3 text-right whitespace-nowrap">{{ $simbolo }} {{ number_format($r['consumo'] + $r['propina'], 2) }}</td>
                            @endif
                            @if ($hayFestejo)<td class="px-3 py-3 text-right whitespace-nowrap">{{ $p->es_festejado ? '—' : $simbolo.' '.number_format($r['festejo'], 2) }}</td>@endif
                            <td class="px-3 py-3 text-right whitespace-nowrap font-semibold text-gray-900 dark:text-gray-100">{{ $p->es_festejado ? 'Festejado' : $simbolo.' '.number_format($r['total'], 2) }}</td>
                            <td class="px-3 py-3 text-right whitespace-nowrap text-green-700 dark:text-green-400">{{ in_array($r['estado'], ['pagado', 'parcial'], true) ? $simbolo.' '.number_format($r['pagado'], 2) : '' }}</td>
                            <td class="px-3 py-3 text-right whitespace-nowrap {{ $r['pendiente'] > 0 && ! in_array($r['estado'], ['pagador', 'festejado', 'hogar'], true) ? 'text-red-600 dark:text-red-400' : '' }}">{{ $r['pendiente'] > 0 && ! in_array($r['estado'], ['pagador', 'festejado', 'hogar'], true) ? $simbolo.' '.number_format($r['pendiente'], 2) : '' }}</td>
                            <td class="px-3 py-3 text-right whitespace-nowrap">
                                @can('eventos.edit')
                                    @if ($resumen['pago_el_hogar'] && $p->deuda_id && $r['pendiente'] > 0)
                                        <x-secondary-button type="button" class="py-1.5" x-on:click="$dispatch('load-cobro-modal-form', '{{ route('eventos.cobro.create', [$evento, $p]) }}'); $dispatch('open-modal', 'cobro-modal')">Registrar pago</x-secondary-button>
                                    @elseif (! $resumen['pago_el_hogar'] && in_array($r['estado'], ['pendiente', 'parcial'], true))
                                        <x-secondary-button type="button" class="py-1.5" x-on:click="$dispatch('load-cobro-modal-form', '{{ route('eventos.pagado.create', [$evento, $p]) }}'); $dispatch('open-modal', 'cobro-modal')">Ya pagó</x-secondary-button>
                                    @elseif (! $resumen['pago_el_hogar'] && $p->fecha_pago)
                                        <x-secondary-button type="button" class="py-1.5" x-on:click="showConfirm('¿Quitar el pago?', {{ \Illuminate\Support\Js::from('Se quitará el pago de '.$p->nombre().($p->movimiento() ? ' y su movimiento.' : '.')) }}, () => performDeleteUrl('{{ route('eventos.pagado.destroy', [$evento, $p]) }}'))">Quitar pago</x-secondary-button>
                                    @endif
                                @endcan
                                @foreach ($p->deuda?->pagos ?? [] as $pago)
                                    <span class="mt-1 block text-xs text-gray-500">
                                        {{ $pago->fecha->format('d/m') }} · {{ $simbolo }} {{ number_format((float) $pago->monto, 2) }}
                                        @can('deudores.delete')
                                            <button type="button" class="ml-1 text-red-600 hover:underline" x-on:click="deleteItem({{ $pago->id }}, 'este cobro', '{{ route('deuda-pagos.destroy', $pago) }}')">quitar</button>
                                        @endcan
                                    </span>
                                @endforeach
                            </td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot class="font-semibold text-gray-900 dark:text-gray-100">
                    <tr class="border-t-2 border-gray-300 dark:border-gray-600">
                        <td class="sticky left-0 z-10 bg-white px-4 py-3 dark:bg-gray-800">Total</td>
                        @foreach ($evento->consumos as $c)<td class="px-2 py-3 text-right whitespace-nowrap">{{ number_format($c->total(), 2) }}</td>@endforeach
                        <td class="px-3 py-3 text-right whitespace-nowrap">{{ $simbolo }} {{ number_format($resumen['subtotal'], 2) }}</td>
                        @if ($hayPropina)
                            <td class="px-3 py-3 text-right whitespace-nowrap">{{ $simbolo }} {{ number_format($resumen['propina'], 2) }}</td>
                            <td class="px-3 py-3 text-right whitespace-nowrap">{{ $simbolo }} {{ number_format($resumen['total'], 2) }}</td>
                        @endif
                        @if ($hayFestejo)<td class="px-3 py-3 text-right whitespace-nowrap">{{ $simbolo }} {{ number_format(collect($resumen['participantes'])->sum('festejo'), 2) }}</td>@endif
                        <td class="px-3 py-3 text-right whitespace-nowrap">{{ $simbolo }} {{ number_format($resumen['total'], 2) }}</td>
                        <td colspan="3"></td>
                    </tr>
                </tfoot>
            </table>
        </div>

        @if ($evento->foto)
            <div class="rounded-lg bg-white p-5 shadow dark:bg-gray-800">
                <h2 class="mb-3 text-base font-semibold text-gray-900 dark:text-gray-100">Foto de la cuenta</h2>
                <a href="{{ route('eventos.foto', [$evento, 'v' => $evento->versionFoto()]) }}" target="_blank"><img src="{{ route('eventos.foto', [$evento, 'v' => $evento->versionFoto()]) }}" alt="Foto de la cuenta" class="max-h-96 rounded-lg border border-gray-200 dark:border-gray-700"></a>
            </div>
        @endif

        @if ($evento->comentario)
            <p class="rounded-lg bg-white p-4 text-sm text-gray-700 shadow dark:bg-gray-800 dark:text-gray-300">{{ $evento->comentario }}</p>
        @endif
    </div>
    <x-ajax-modal name="cobro" />
</x-app-layout>
