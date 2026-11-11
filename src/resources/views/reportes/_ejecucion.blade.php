@php
    $t = $d['totales'];
    $maxGasto = max(1, collect($d['gastos'])->max('monto') ?? 1);
    $th = 'px-4 py-3';
    $td = 'px-4 py-2';
@endphp
{{-- Lo grande primero: cómo cerró el período --}}
<div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
    @foreach ([
        ['Ingresos', $dinero($t['ingresos']), count($d['ingresos']).' movimientos', 'text-green-700 dark:text-green-400'],
        ['Gastos', $dinero($t['gastos']), 'efectivo, cuentas y tarjetas', 'text-gray-900 dark:text-gray-100'],
        ['Ahorro', $dinero($t['ahorro']), 'ingresos − gastos', $t['ahorro'] < 0 ? 'text-red-600 dark:text-red-400' : 'text-green-700 dark:text-green-400'],
        ['Al pagar los compromisos quedaría', $dinero($t['liquidez']), 'efectivo + cuentas − por pagar', $t['liquidez'] < 0 ? 'text-red-600 dark:text-red-400' : 'text-gray-900 dark:text-gray-100'],
    ] as [$label, $valor, $nota, $color])
        <div class="rounded-lg bg-white p-4 shadow dark:bg-gray-800">
            <p class="text-xs text-gray-500 dark:text-gray-400">{{ $label }}</p>
            <p class="mt-1 text-2xl font-bold {{ $color }}">{{ $valor }}</p>
            <p class="text-xs text-gray-500 dark:text-gray-400">{{ $nota }}</p>
        </div>
    @endforeach
</div>

<div class="grid gap-6 lg:grid-cols-2">
    {{-- Ingresos del período --}}
    <section class="overflow-hidden rounded-lg bg-white shadow dark:bg-gray-800">
        <div class="flex items-baseline justify-between border-b border-gray-200 px-6 py-4 dark:border-gray-700">
            <h2 class="font-semibold text-gray-900 dark:text-gray-100">Ingresos</h2>
            <span class="text-sm font-semibold text-green-700 dark:text-green-400">{{ $dinero($t['ingresos']) }}</span>
        </div>
        @if (! $d['ingresos'])
            <p class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400">No hubo ingresos en este período.</p>
        @else
            <div class="max-h-96 overflow-y-auto">
                <table class="min-w-full text-sm">
                    <tbody class="divide-y divide-gray-100 text-gray-700 dark:divide-gray-700 dark:text-gray-300">
                        @foreach ($d['ingresos'] as $m)
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/40">
                                <td class="{{ $td }} whitespace-nowrap text-gray-500 dark:text-gray-400">{{ \Carbon\Carbon::parse($m['fecha'])->format('d/m') }}</td>
                                <td class="{{ $td }}">
                                    <a href="{{ route('movimientos.ver', [$m['clase'], $m['id']]) }}" class="hover:underline">@include('hogar._por', ['id' => $m['user_id']]){{ $m['descripcion'] }}</a>
                                    <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $m['categoria'] }} · {{ $m['donde'] }}</span>
                                </td>
                                <td class="{{ $td }} text-right font-medium whitespace-nowrap">{{ $dinero($m['monto']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    {{-- Gastos por categoría: barras de una sola serie; el texto en color neutro --}}
    <section class="rounded-lg bg-white p-6 shadow dark:bg-gray-800">
        <div class="flex items-baseline justify-between">
            <h2 class="font-semibold text-gray-900 dark:text-gray-100">Gastos por categoría</h2>
            <span class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $dinero($t['gastos']) }}</span>
        </div>
        @if (! $d['gastos'])
            <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">No hubo gastos en este período.</p>
        @else
            <ul class="mt-4 space-y-2" role="list">
                @foreach ($d['gastos'] as $c)
                    <li class="grid grid-cols-12 items-center gap-2 text-sm" title="{{ $c['nombre'] }}: {{ $dinero($c['monto']) }} ({{ $c['porcentaje'] }} %)">
                        <span class="col-span-4 truncate text-gray-700 dark:text-gray-300">{{ $c['nombre'] }}</span>
                        <span class="col-span-5 h-2.5 rounded-full bg-gray-100 dark:bg-gray-700">
                            <span class="barra-crece block h-2.5 rounded-full" style="--retraso: {{ $loop->index * 50 }}ms; width: {{ max(2, $c['monto'] / $maxGasto * 100) }}%; background-color: {{ $c['color'] ?: '#2a78d6' }}"></span>
                        </span>
                        <span class="col-span-3 text-right text-gray-900 dark:text-gray-100">{{ $dinero($c['monto']) }} <span class="text-xs text-gray-500 dark:text-gray-400">{{ $c['porcentaje'] }}%</span></span>
                    </li>
                @endforeach
            </ul>
            <a href="{{ route('reportes.show', ['gastos'] + $consulta) }}" class="mt-4 inline-block text-sm text-primary-600 hover:underline dark:text-primary-400">Ver el detalle de cada categoría</a>
        @endif
    </section>
</div>

{{-- Efectivo y cuentas: con cuánto empezó y terminó cada una --}}
<section class="overflow-hidden rounded-lg bg-white shadow dark:bg-gray-800">
    <div class="border-b border-gray-200 px-6 py-4 dark:border-gray-700">
        <h2 class="font-semibold text-gray-900 dark:text-gray-100">Efectivo y cuentas</h2>
    </div>
    <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
            <thead class="bg-cabecera text-xs uppercase text-gray-600 dark:bg-gray-700/50 dark:text-gray-400">
                <tr><th class="{{ $th }} text-left">Dónde</th><th class="{{ $th }} text-right">Al inicio</th><th class="{{ $th }} text-right">Entró</th><th class="{{ $th }} text-right">Salió</th><th class="{{ $th }} text-right">Al final</th></tr>
            </thead>
            <tbody class="divide-y divide-gray-100 text-gray-700 dark:divide-gray-700 dark:text-gray-300">
                <tr>
                    <td class="{{ $td }} font-medium text-gray-900 dark:text-gray-100"><a href="{{ route('efectivo.index', ['cycle' => $periodo['desde']->toDateString()]) }}" class="hover:underline">Efectivo</a></td>
                    <td class="{{ $td }} text-right">{{ $dinero($d['efectivo']['saldo_inicial']) }}</td>
                    <td class="{{ $td }} text-right text-green-700 dark:text-green-400">{{ $dinero($d['efectivo']['ingresos']) }}</td>
                    <td class="{{ $td }} text-right text-red-700 dark:text-red-400">{{ $dinero($d['efectivo']['egresos']) }}</td>
                    <td class="{{ $td }} text-right font-semibold">{{ $dinero($d['efectivo']['saldo_final']) }}</td>
                </tr>
                @foreach ($d['cuentas'] as $c)
                    <tr>
                        <td class="{{ $td }}"><a href="{{ route('cuentas.show', [$c['id'], 'cycle' => $periodo['desde']->toDateString()]) }}" class="flex items-center gap-2 hover:underline">
                            <span class="h-5 w-1.5 shrink-0 rounded-full" style="background-color: {{ $c['color'] }}"></span>@include('hogar._por', ['id' => $c['user_id']]){{ $c['nombre'] }}</a></td>
                        <td class="{{ $td }} text-right">{{ $dinero($c['saldo_inicial']) }}</td>
                        <td class="{{ $td }} text-right text-green-700 dark:text-green-400">{{ $dinero($c['ingresos']) }}</td>
                        <td class="{{ $td }} text-right text-red-700 dark:text-red-400">{{ $dinero($c['egresos']) }}</td>
                        <td class="{{ $td }} text-right font-semibold">{{ $dinero($c['saldo_final']) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot class="border-t-2 border-gray-200 font-semibold text-gray-900 dark:border-gray-600 dark:text-gray-100">
                <tr><td class="px-4 py-3" colspan="4">Total al final</td><td class="px-4 py-3 text-right">{{ $dinero($t['efectivo'] + $t['cuentas']) }}</td></tr>
            </tfoot>
        </table>
    </div>
</section>

{{-- Compromisos del período --}}
<section class="overflow-hidden rounded-lg bg-white shadow dark:bg-gray-800">
    <div class="border-b border-gray-200 px-6 py-4 dark:border-gray-700">
        <h2 class="font-semibold text-gray-900 dark:text-gray-100">Compromisos</h2>
    </div>
    @if (! $d['compromisos'])
        <p class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400">No hubo compromisos con fecha en este período.</p>
    @else
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-cabecera text-xs uppercase text-gray-600 dark:bg-gray-700/50 dark:text-gray-400">
                    <tr><th class="{{ $th }} text-left">Fecha</th><th class="{{ $th }} text-left">Compromiso</th><th class="{{ $th }} text-left">Estado</th><th class="{{ $th }} text-right">Monto</th><th class="{{ $th }} text-right">Pagado</th><th class="{{ $th }} text-right">Pendiente</th></tr>
                </thead>
                <tbody class="divide-y divide-gray-100 text-gray-700 dark:divide-gray-700 dark:text-gray-300">
                    @foreach ($d['compromisos'] as $c)
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/40">
                            <td class="{{ $td }} whitespace-nowrap">{{ $fecha($c['fecha']) }}</td>
                            <td class="{{ $td }}"><a href="{{ route('compromisos.show', $c['id']) }}" class="hover:underline">{{ $c['descripcion'] }}</a></td>
                            <td class="{{ $td }}">{{ $c['estado_texto'] }}</td>
                            <td class="{{ $td }} text-right">{{ $dinero($c['monto']) }}</td>
                            <td class="{{ $td }} text-right">{{ $dinero($c['pagado']) }}</td>
                            <td class="{{ $td }} text-right font-medium {{ $c['pendiente'] > 0 ? 'text-gray-900 dark:text-gray-100' : 'text-gray-400' }}">{{ $dinero($c['pendiente']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot class="border-t-2 border-gray-200 font-semibold text-gray-900 dark:border-gray-600 dark:text-gray-100">
                    <tr><td class="px-4 py-3" colspan="3">Total</td><td class="px-4 py-3 text-right">{{ $dinero($t['compromisos']) }}</td><td class="px-4 py-3 text-right">{{ $dinero($t['compromisos_pagado']) }}</td><td class="px-4 py-3 text-right">{{ $dinero($t['por_pagar']) }}</td></tr>
                </tfoot>
            </table>
        </div>
    @endif
</section>

<div class="grid gap-6 lg:grid-cols-3">
    {{-- Tarjetas por fecha límite de pago (como en la hoja) --}}
    <section class="overflow-hidden rounded-lg bg-white shadow dark:bg-gray-800 lg:col-span-2">
        <div class="flex items-baseline justify-between border-b border-gray-200 px-6 py-4 dark:border-gray-700">
            <h2 class="font-semibold text-gray-900 dark:text-gray-100">Tarjetas</h2>
            <span class="text-xs text-gray-500 dark:text-gray-400">cómo están hoy · por fecha límite de pago</span>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-cabecera text-xs uppercase text-gray-600 dark:bg-gray-700/50 dark:text-gray-400">
                    <tr><th class="{{ $th }} text-left">Tarjeta</th><th class="{{ $th }} text-left">Pagar antes del</th><th class="{{ $th }} text-right">Por pagar</th><th class="{{ $th }} text-right">Disponible</th></tr>
                </thead>
                <tbody class="divide-y divide-gray-100 text-gray-700 dark:divide-gray-700 dark:text-gray-300">
                    @foreach ($d['tarjetas'] as $tj)
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/40">
                            <td class="{{ $td }}"><a href="{{ route('tarjetas.show', $tj['id']) }}" class="flex items-center gap-2 hover:underline">
                                <span class="h-5 w-1.5 shrink-0 rounded-full" style="background-color: {{ $tj['color'] }}"></span>@include('hogar._por', ['id' => $tj['user_id']]){{ $tj['nombre'] }}</a></td>
                            <td class="{{ $td }} whitespace-nowrap">{{ $tj['fecha_limite'] ? $fecha($tj['fecha_limite']) : '—' }}</td>
                            <td class="{{ $td }} text-right font-medium">{{ $tj['por_pagar'] > 0 ? $dinero($tj['por_pagar']) : '—' }}</td>
                            <td class="{{ $td }} text-right {{ $tj['disponible'] < 0 ? 'text-red-600 dark:text-red-400' : '' }}">{{ $dinero($tj['disponible']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot class="border-t-2 border-gray-200 font-semibold text-gray-900 dark:border-gray-600 dark:text-gray-100">
                    <tr><td class="px-4 py-3" colspan="2">Total</td><td class="px-4 py-3 text-right">{{ $dinero($t['tarjetas_por_pagar']) }}</td><td class="px-4 py-3 text-right">{{ $dinero($t['tarjetas_disponible']) }}</td></tr>
                </tfoot>
            </table>
        </div>
    </section>

    {{-- Lo que nos deben --}}
    <section class="rounded-lg bg-white p-6 shadow dark:bg-gray-800">
        <div class="flex items-baseline justify-between">
            <h2 class="font-semibold text-gray-900 dark:text-gray-100">Nos deben</h2>
            <span class="text-sm font-semibold text-green-700 dark:text-green-400">{{ $dinero($t['nos_deben']) }}</span>
        </div>
        @forelse ($d['nos_deben'] as $c)
            <a href="{{ route('contactos.show', $c['id']) }}" class="mt-3 flex justify-between text-sm text-gray-700 hover:underline dark:text-gray-300">
                <span>{{ $c['nombre'] }}</span><span class="font-medium">{{ $dinero($c['pendiente']) }}</span>
            </a>
        @empty
            <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">Nadie nos debe. 🎉</p>
        @endforelse
        <p class="mt-4 text-xs text-gray-500 dark:text-gray-400">Tarjetas y deudas muestran cómo están hoy.</p>
    </section>
</div>
