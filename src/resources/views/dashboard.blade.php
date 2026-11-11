@php
    $simbolo = auth()->user()->monedaPreferida?->simbolo ?? '$';
    $dinero = fn ($v) => $simbolo.' '.number_format((float) $v, 2);
    $fecha = fn ($f) => $f ? \Carbon\Carbon::parse($f)->format('d/m') : '—';
    $t = $d['tarjetas_totales'];
    // Gastos por categoría: las 8 mayores y el resto en "Otras" (nunca más series que colores)
    $categorias = collect($d['gastos_por_categoria']);
    $top = $categorias->take(8);
    if ($categorias->count() > 8) {
        $top->push(['nombre' => 'Otras', 'color' => null, 'monto' => round($categorias->slice(8)->sum('monto'), 2)]);
    }
    $maxCategoria = max(1, $top->max('monto') ?? 1);
    $historial = collect($d['historial']);
    $maxHistorial = max(1, $historial->max(fn ($h) => max($h['ingresos'], $h['gastos'])) ?? 1);
    $niveles = [
        'critico' => ['Vencido', 'border-red-300 bg-red-50 text-red-800 dark:border-red-800 dark:bg-red-900/30 dark:text-red-200', 'M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z'],
        'serio' => ['Atención', 'border-orange-300 bg-orange-50 text-orange-800 dark:border-orange-800 dark:bg-orange-900/30 dark:text-orange-200', 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z'],
        'aviso' => ['Pronto', 'border-amber-300 bg-amber-50 text-amber-800 dark:border-amber-800 dark:bg-amber-900/30 dark:text-amber-200', 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z'],
        'info' => ['Ingreso', 'border-sky-300 bg-sky-50 text-sky-800 dark:border-sky-800 dark:bg-sky-900/30 dark:text-sky-200', 'M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z'],
    ];
@endphp
<x-app-layout>
    <x-slot name="header"><h1>Inicio</h1></x-slot>

    <style>
        /* Colores de los gráficos (paleta validada: azul = ingresos / una sola serie, naranja = gastos) */
        .viz-root { --series-1: #2a78d6; --series-2: #eb6834; --grid: #e5e7eb; }
        .dark .viz-root { --series-1: #3987e5; --series-2: #d95926; --grid: #374151; }
    </style>

    <div class="viz-root max-w-7xl mx-auto space-y-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <p class="text-sm text-gray-600 dark:text-gray-300">
                Ciclo del <strong>{{ \Carbon\Carbon::parse($d['ciclo']['inicio'])->format('d/m/Y') }}</strong> al <strong>{{ \Carbon\Carbon::parse($d['ciclo']['fin'])->format('d/m/Y') }}</strong>
                · {{ match ($d['ciclo']['dias_restantes']) { 0 => 'hoy es el corte', 1 => 'mañana es el corte', default => 'faltan '.$d['ciclo']['dias_restantes'].' días para el corte' } }}
            </p>
            <div class="flex flex-wrap gap-2">
                @can('efectivo.view')<x-secondary-button type="button" onclick="window.location='{{ route('efectivo.index') }}'">Efectivo</x-secondary-button>@endcan
                @can('compromisos.view')<x-secondary-button type="button" onclick="window.location='{{ route('compromisos.index') }}'">Compromisos</x-secondary-button>@endcan
                @can('ingresos.view')<x-secondary-button type="button" onclick="window.location='{{ route('ingresos.index') }}'">Registrar sueldo</x-secondary-button>@endcan
                @can('cuentas.view')<x-primary-button type="button" onclick="window.location='{{ route('cuentas.index') }}'">Cuentas y transferencias</x-primary-button>@endcan
            </div>
        </div>

        {{-- Se muestran 5 segundos al entrar, se desvanecen y el espacio se cierra suave; siguen en la campana de arriba --}}
        @if ($d['alertas'])
            <div x-data="{ desvanecer: false, mostrar: true }" x-init="setTimeout(() => desvanecer = true, 5000); setTimeout(() => mostrar = false, 6200)"
                x-show="mostrar" x-collapse.duration.600ms>
            <div class="space-y-2 transition-all duration-1000 ease-out" x-bind:class="desvanecer ? 'opacity-0 -translate-y-2' : 'opacity-100 translate-y-0'">
                @foreach ($d['alertas'] as $a)
                    @php([, $clase, $icono] = $niveles[$a['nivel']])
                    @php($etiqueta = $a['etiqueta'])
                    <a href="{{ route('avisos.abrir', $a['clave']) }}" class="flex items-center gap-3 rounded-lg border px-4 py-2 text-sm hover:opacity-90 {{ $clase }}">
                        <svg class="h-5 w-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $icono }}" /></svg>
                        <span class="shrink-0 rounded-full bg-white/60 px-2 py-0.5 text-xs font-semibold dark:bg-black/20">{{ $etiqueta }}</span>
                        <span>{{ $a['texto'] }}</span>
                    </a>
                @endforeach
            </div>
            </div>
        @endif

        {{-- Termómetro del ciclo: tope de gasto y presupuestos (súper, gasolina…) que se van gastando --}}
        @php($barra = fn (float $gastado, float $monto) => $gastado > $monto ? 'bg-red-500' : ($gastado >= $monto * 0.8 ? 'bg-amber-500' : 'bg-green-500'))
        <section class="rounded-lg bg-white p-6 shadow dark:bg-gray-800">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100">Gasto del ciclo</h2>
                @can('compromisos.edit')
                    <x-secondary-button type="button" class="py-1.5" x-data x-on:click="$dispatch('editar-tope')">{{ $d['tope'] ? 'Cambiar tope' : 'Ponerle un tope' }}</x-secondary-button>
                @endcan
            </div>
            @can('compromisos.edit')
                <div x-data="{ editando: false }" x-on:editar-tope.window="editando = true" x-show="editando" x-cloak>
                <form x-data="ajaxForm(async function(data) { await showAlert('success', '¡Listo!', data.message); window.location.reload(); })"
                    @submit.prevent="submit" method="POST" action="{{ route('hogar.tope') }}" class="mt-3 flex flex-wrap items-start gap-3" novalidate>
                    @csrf
                    @method('PATCH')
                    <div class="w-48"><x-floating-money id="tope_gasto_ciclo" label="Tope del ciclo" :symbol="$simbolo" :value="$d['tope']['tope'] ?? ''" /></div>
                    <x-secondary-button type="button" x-on:click="editando = false">Cancelar</x-secondary-button>
                    <x-primary-button type="submit" x-bind:disabled="loading">Guardar</x-primary-button>
                </form>
                </div>
            @endcan
            @if ($tope = $d['tope'])
                <div class="mt-3 flex flex-wrap items-baseline justify-between gap-2 text-sm">
                    <span class="text-gray-700 dark:text-gray-300">Llevas <strong>{{ $dinero($tope['gastado']) }}</strong> de {{ $dinero($tope['tope']) }}</span>
                    <span class="{{ $tope['queda'] < 0 ? 'font-semibold text-red-600 dark:text-red-400' : 'text-gray-600 dark:text-gray-300' }}">
                        {{ $tope['queda'] < 0 ? 'Te pasaste por '.$dinero(-$tope['queda']) : 'Te quedan '.$dinero($tope['queda']).' · '.$dinero($tope['por_dia']).' por día ('.($tope['dias_restantes'] + 1).' días)' }}
                    </span>
                </div>
                <div class="mt-2 h-3 overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700" role="progressbar" aria-valuenow="{{ $tope['porcentaje'] }}" aria-valuemin="0" aria-valuemax="100" aria-label="Gasto del ciclo">
                    <div class="barra-crece h-full rounded-full {{ $barra($tope['gastado'], $tope['tope']) }}" style="width: {{ min(100, $tope['porcentaje']) }}%"></div>
                </div>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Cuenta todos los gastos del ciclo menos las cuotas de préstamos.</p>
            @else
                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Sin tope: ponle uno para saber cuánto puedes gastar por día.</p>
            @endif
            @if ($d['presupuestos'])
                <ul class="mt-4 grid gap-3 sm:grid-cols-2">
                    @foreach ($d['presupuestos'] as $p)
                        <li>
                            <a href="{{ route('compromisos.show', $p['id']) }}" class="flex items-baseline justify-between gap-2 text-sm hover:underline">
                                <span class="font-medium text-gray-800 dark:text-gray-200">{{ $p['descripcion'] }}</span>
                                <span class="{{ $p['queda'] < 0 ? 'text-red-600 dark:text-red-400' : 'text-gray-600 dark:text-gray-300' }}">{{ $dinero($p['gastado']) }} de {{ $dinero($p['monto']) }}{{ $p['comprometido'] > 0 ? ' + '.$dinero($p['comprometido']).' por pagar' : '' }} · {{ $p['queda'] < 0 ? 'te pasaste '.$dinero(-$p['queda']) : 'quedan '.$dinero($p['queda']) }}</span>
                            </a>
                            <div class="mt-1 h-2 overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700"><div class="barra-crece h-full rounded-full {{ $barra($p['gastado'] + $p['comprometido'], $p['monto']) }}" style="--retraso: {{ 150 + $loop->index * 60 }}ms; width: {{ $p['monto'] > 0 ? min(100, round(($p['gastado'] + $p['comprometido']) / $p['monto'] * 100)) : 0 }}%"></div></div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        {{-- Dónde está el dinero y qué se debe --}}
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ([
                ['Efectivo', $dinero($d['efectivo']), 'en mano', null],
                ['En cuentas', $dinero($d['total_cuentas']), count($d['cuentas']).' cuentas y billeteras', null],
                ['Por pagar este ciclo', $dinero($d['por_pagar_ciclo']), 'compromisos hasta el corte', null],
                ['Al pagar mis compromisos tendré', $dinero($d['liquidez']), 'efectivo + cuentas − por pagar', $d['liquidez'] < 0 ? 'text-red-600 dark:text-red-400' : 'text-green-700 dark:text-green-400'],
                ['Disponible en tarjetas', $dinero($t['disponible']), 'de las que sí puedo sacar: '.$dinero($t['disponible_avances']), null],
                ['Deuda en tarjetas', $dinero($t['deuda']), 'de '.$dinero($t['limite']).' de límite', null],
                ['Préstamos', $dinero($d['deuda_prestamos']), 'cuotas al mes: '.$dinero($d['cuotas_prestamos']), null],
                ['Nos deben', $dinero($d['nos_deben']), 'pendiente de cobrar', null],
            ] as [$label, $valor, $nota, $color])
                <div class="rounded-lg bg-white p-4 shadow dark:bg-gray-800">
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ $label }}</p>
                    <p class="mt-1 text-2xl font-bold {{ $color ?? 'text-gray-900 dark:text-gray-100' }}">{{ $valor }}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ $nota }}</p>
                </div>
            @endforeach
        </div>

        {{-- El cuadro de tarjetas (como en la hoja) --}}
        @if ($d['tarjetas'])
            <div class="overflow-hidden rounded-lg bg-white shadow dark:bg-gray-800">
                <div class="flex items-center justify-between border-b border-gray-200 px-6 py-4 dark:border-gray-700">
                    <h2 class="font-semibold text-gray-900 dark:text-gray-100">Tarjetas</h2>
                    @can('tarjetas.view')<a href="{{ route('tarjetas.index') }}" class="text-sm text-primary-600 hover:underline dark:text-primary-400">Ver todas</a>@endcan
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-cabecera text-xs uppercase text-gray-600 dark:bg-gray-700/50 dark:text-gray-400">
                            <tr><th class="px-4 py-3 text-left">Tarjeta</th><th class="px-4 py-3 text-center">Corte</th><th class="px-4 py-3 text-center">Pago</th><th class="px-4 py-3 text-right">Límite</th><th class="px-4 py-3 text-right">Disponible</th><th class="px-4 py-3 text-right">Por pagar</th></tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 text-gray-700 dark:divide-gray-700 dark:text-gray-300">
                            @foreach ($d['tarjetas'] as $tj)
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/40">
                                    <td class="px-4 py-2">
                                        <a href="{{ route('tarjetas.show', $tj['id']) }}" class="flex items-center gap-3">
                                            <span class="h-6 w-1.5 shrink-0 rounded-full" style="background-color: {{ $tj['color'] }}"></span>
                                            <x-logo-banco :banco="$tj['banco']" size="sm" espacio />
                                            <span class="font-medium text-gray-900 dark:text-gray-100">@include('hogar._por', ['id' => $tj['user_id']]){{ $tj['nombre'] }}</span>
                                            @unless ($tj['permite_avances'])<span class="text-xs text-gray-400" title="No sirve para avances ni recargas">(tienda)</span>@endunless
                                        </a>
                                    </td>
                                    <td class="px-4 py-2 text-center">{{ $tj['dia_corte'] }}</td>
                                    <td class="px-4 py-2 text-center">{{ $tj['dia_pago'] }}</td>
                                    <td class="px-4 py-2 text-right">{{ number_format($tj['limite'], 2) }}</td>
                                    <td class="px-4 py-2 text-right font-semibold {{ $tj['disponible'] < 0 ? 'text-red-600 dark:text-red-400' : 'text-gray-900 dark:text-gray-100' }}">{{ number_format($tj['disponible'], 2) }}</td>
                                    <td class="px-4 py-2 text-right">{{ $tj['por_pagar'] > 0 ? number_format($tj['por_pagar'], 2).' · '.$fecha($tj['vence']) : '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot class="border-t-2 border-gray-200 font-semibold text-gray-900 dark:border-gray-600 dark:text-gray-100">
                            <tr><td class="px-4 py-3" colspan="3">Total · de las que sí puedo sacar: {{ $dinero($t['disponible_avances']) }}</td><td class="px-4 py-3 text-right">{{ number_format($t['limite'], 2) }}</td><td class="px-4 py-3 text-right">{{ number_format($t['disponible'], 2) }}</td><td class="px-4 py-3 text-right">{{ number_format($t['por_pagar'], 2) }}</td></tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        @endif

        <div class="grid gap-6 lg:grid-cols-2">
            {{-- Gastos del ciclo por categoría: barras horizontales, una sola serie --}}
            <section class="rounded-lg bg-white p-6 shadow dark:bg-gray-800">
                <div class="flex items-baseline justify-between">
                    <h2 class="font-semibold text-gray-900 dark:text-gray-100">Gastos del ciclo por categoría</h2>
                    <span class="text-sm text-gray-500 dark:text-gray-400">{{ $dinero($categorias->sum('monto')) }}</span>
                </div>
                @if ($top->isEmpty())
                    <p class="mt-6 text-sm text-gray-500 dark:text-gray-400">Aún no hay gastos en este ciclo.</p>
                @else
                    <ul class="mt-4 space-y-2" role="list">
                        @foreach ($top as $c)
                            <li class="grid grid-cols-12 items-center gap-2 text-sm" title="{{ $c['nombre'] }}: {{ $dinero($c['monto']) }}">
                                <span class="col-span-4 flex min-w-0 items-center gap-2 text-gray-600 dark:text-gray-300">
                                    @if ($c['color'])<span class="h-2.5 w-2.5 shrink-0 rounded-full" style="background-color: {{ $c['color'] }}"></span>@endif
                                    <span class="truncate">{{ $c['nombre'] }}</span>
                                </span>
                                <span class="col-span-5 h-3.5">
                                    <span class="barra-crece block h-full rounded-r" style="--retraso: {{ $loop->index * 50 }}ms; width: {{ max(1, $c['monto'] / $maxCategoria * 100) }}%; background-color: var(--series-1)"></span>
                                </span>
                                <span class="col-span-3 text-right font-medium tabular-nums text-gray-900 dark:text-gray-100">{{ $dinero($c['monto']) }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            {{-- Ingresos vs gastos por ciclo: columnas agrupadas, dos series, leyenda + tabla --}}
            <section class="rounded-lg bg-white p-6 shadow dark:bg-gray-800" x-data="{ tabla: false, tip: null }">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h2 class="font-semibold text-gray-900 dark:text-gray-100">Ingresos y gastos por ciclo</h2>
                    <div class="flex items-center gap-4 text-xs text-gray-600 dark:text-gray-300">
                        <span class="flex items-center gap-1"><span class="h-2.5 w-2.5 rounded-sm" style="background-color: var(--series-1)"></span>Ingresos</span>
                        <span class="flex items-center gap-1"><span class="h-2.5 w-2.5 rounded-sm" style="background-color: var(--series-2)"></span>Gastos</span>
                        <button type="button" class="text-primary-600 hover:underline dark:text-primary-400" x-on:click="tabla = !tabla" x-text="tabla ? 'Ver gráfico' : 'Ver tabla'"></button>
                    </div>
                </div>
                <p class="mt-1 h-5 text-xs text-gray-600 dark:text-gray-300" x-text="tip ?? 'Pasa el cursor sobre un ciclo para ver sus montos.'"></p>
                <div x-show="!tabla" class="mt-2 flex h-48 items-end gap-3 border-b" style="border-color: var(--grid)">
                    @foreach ($historial as $h)
                        @php($etiqueta = \Carbon\Carbon::parse($h['inicio'])->translatedFormat('M'))
                        <div class="flex h-full flex-1 cursor-default flex-col justify-end" x-on:mouseenter="tip = @js('Ciclo '.\Carbon\Carbon::parse($h['inicio'])->format('d/m').'–'.\Carbon\Carbon::parse($h['fin'])->format('d/m').': ingresos '.$dinero($h['ingresos']).' · gastos '.$dinero($h['gastos']))" x-on:mouseleave="tip = null">
                            <div class="flex h-full items-end justify-center gap-0.5">
                                <span class="columna-crece w-full max-w-6 rounded-t" style="--retraso: {{ $loop->index * 80 }}ms; height: {{ $h['ingresos'] / $maxHistorial * 100 }}%; background-color: var(--series-1)"></span>
                                <span class="columna-crece w-full max-w-6 rounded-t" style="--retraso: {{ $loop->index * 80 + 40 }}ms; height: {{ $h['gastos'] / $maxHistorial * 100 }}%; background-color: var(--series-2)"></span>
                            </div>
                        </div>
                    @endforeach
                </div>
                <div x-show="!tabla" class="mt-1 flex gap-3 text-center text-xs text-gray-500 dark:text-gray-400">
                    @foreach ($historial as $h)<span class="flex-1">{{ \Carbon\Carbon::parse($h['inicio'])->translatedFormat('M') }}</span>@endforeach
                </div>
                <table x-show="tabla" x-cloak class="mt-2 w-full text-sm text-gray-700 dark:text-gray-300">
                    <thead class="text-xs uppercase text-gray-600"><tr><th class="py-1 text-left">Ciclo</th><th class="py-1 text-right">Ingresos</th><th class="py-1 text-right">Gastos</th><th class="py-1 text-right">Diferencia</th></tr></thead>
                    <tbody>
                        @foreach ($historial as $h)
                            <tr class="border-t border-gray-100 dark:border-gray-700">
                                <td class="py-1">{{ \Carbon\Carbon::parse($h['inicio'])->format('d/m') }}–{{ \Carbon\Carbon::parse($h['fin'])->format('d/m') }}</td>
                                <td class="py-1 text-right tabular-nums">{{ number_format($h['ingresos'], 2) }}</td>
                                <td class="py-1 text-right tabular-nums">{{ number_format($h['gastos'], 2) }}</td>
                                <td class="py-1 text-right tabular-nums {{ $h['ingresos'] - $h['gastos'] < 0 ? 'text-red-600 dark:text-red-400' : '' }}">{{ number_format($h['ingresos'] - $h['gastos'], 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">No cuentan como ingreso los avances de tarjeta, préstamos recibidos ni cobros de deudas; ni como gasto las transferencias, los préstamos a terceros ni el pago de avances.</p>
            </section>
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <section class="overflow-hidden rounded-lg bg-white shadow dark:bg-gray-800">
                <h2 class="border-b border-gray-200 px-6 py-4 font-semibold text-gray-900 dark:border-gray-700 dark:text-gray-100">Próximos compromisos</h2>
                <ul class="divide-y divide-gray-100 text-sm dark:divide-gray-700">
                    @forelse ($d['proximos'] as $p)
                        <li>
                            <a href="{{ route('compromisos.show', $p['id']) }}" class="flex items-center justify-between gap-3 px-6 py-2 hover:bg-gray-50 dark:hover:bg-gray-700/40">
                                <span class="flex min-w-0 items-center gap-2 text-gray-700 dark:text-gray-300">
                                    @if ($p['color'])<span class="h-2.5 w-2.5 shrink-0 rounded-full" style="background-color: {{ $p['color'] }}"></span>@endif
                                    <span class="truncate">{{ $p['descripcion'] }}</span>
                                </span>
                                <span class="shrink-0 text-right">
                                    <span class="font-medium text-gray-900 dark:text-gray-100">{{ $dinero($p['monto']) }}</span>
                                    <span class="ml-2 text-xs {{ $p['vencido'] ? 'font-semibold text-red-600 dark:text-red-400' : 'text-gray-500' }}">{{ $p['vencido'] ? 'vencido ' : '' }}{{ $fecha($p['fecha']) }}</span>
                                </span>
                            </a>
                        </li>
                    @empty
                        <li class="px-6 py-6 text-center text-gray-500 dark:text-gray-400">No hay compromisos pendientes.</li>
                    @endforelse
                </ul>
            </section>
            <section class="overflow-hidden rounded-lg bg-white shadow dark:bg-gray-800">
                <h2 class="border-b border-gray-200 px-6 py-4 font-semibold text-gray-900 dark:border-gray-700 dark:text-gray-100">Cuentas</h2>
                <ul class="divide-y divide-gray-100 text-sm dark:divide-gray-700">
                    @forelse ($d['cuentas'] as $c)
                        <li>
                            <a href="{{ route('cuentas.show', $c['id']) }}" class="flex items-center justify-between gap-3 px-6 py-2 hover:bg-gray-50 dark:hover:bg-gray-700/40">
                                <span class="flex items-center gap-3 text-gray-700 dark:text-gray-300"><x-logo-banco :banco="$c['banco']" size="sm" />@include('hogar._por', ['id' => $c['user_id']]){{ $c['nombre'] }}</span>
                                <span class="font-medium text-gray-900 dark:text-gray-100">{{ $dinero($c['saldo']) }}</span>
                            </a>
                        </li>
                    @empty
                        <li class="px-6 py-6 text-center text-gray-500 dark:text-gray-400">Sin cuentas registradas.</li>
                    @endforelse
                </ul>
            </section>
        </div>
    </div>
</x-app-layout>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        @if (session('verification_error'))
            showAlert('warning', 'No se puede verificar esta cuenta', @js(session('verification_error')), { timer: 6000 });
        @endif
        // Intento de resetear contraseña de otra cuenta
        @if (session('password_reset_error'))
            showAlert('warning', 'Atención', @js(session('password_reset_error')), { timer: 6000 });
        @endif
    });
</script>
