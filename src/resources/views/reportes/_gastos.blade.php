@php
    $varios = count($d['ciclos']) > 1;
    $maxGasto = max(1, collect($d['categorias'])->max('monto') ?? 1);
    $corto = fn ($c) => \Carbon\Carbon::parse($c['inicio'])->format('d/m').' – '.\Carbon\Carbon::parse($c['fin'])->format('d/m');
@endphp
<div class="grid gap-4 sm:grid-cols-3">
    @foreach ([
        ['Gastado en el período', $dinero($d['total'])],
        [$varios ? 'Promedio por ciclo' : 'Categorías con gasto', $varios ? $dinero($d['promedio_por_ciclo']) : count($d['categorias'])],
        ['Mayor gasto', $d['categorias'] ? $d['categorias'][0]['nombre'].' · '.$d['categorias'][0]['porcentaje'].'%' : '—'],
    ] as [$label, $valor])
        <div class="rounded-lg bg-white p-4 shadow dark:bg-gray-800">
            <p class="text-xs text-gray-500 dark:text-gray-400">{{ $label }}</p>
            <p class="mt-1 text-xl font-bold text-gray-900 dark:text-gray-100">{{ $valor }}</p>
        </div>
    @endforeach
</div>

@if (! $d['categorias'])
    <p class="rounded-lg bg-white p-6 text-sm text-gray-500 shadow dark:bg-gray-800 dark:text-gray-400">No hubo gastos en este período.</p>
@else
    {{-- Comparación por ciclo (solo si el período abarca varios) --}}
    @if ($varios)
        <section class="overflow-hidden rounded-lg bg-white shadow dark:bg-gray-800">
            <div class="border-b border-gray-200 px-6 py-4 dark:border-gray-700">
                <h2 class="font-semibold text-gray-900 dark:text-gray-100">Cada categoría ciclo por ciclo</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-cabecera text-xs uppercase text-gray-600 dark:bg-gray-700/50 dark:text-gray-400">
                        <tr>
                            <th class="sticky left-0 bg-cabecera px-4 py-3 text-left dark:bg-gray-700">Categoría</th>
                            @foreach ($d['ciclos'] as $c)<th class="whitespace-nowrap px-4 py-3 text-right">{{ $corto($c) }}</th>@endforeach
                            <th class="px-4 py-3 text-right">Total</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 text-gray-700 dark:divide-gray-700 dark:text-gray-300">
                        @foreach ($d['categorias'] as $c)
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/40">
                                <td class="sticky left-0 bg-white px-4 py-2 dark:bg-gray-800"><span class="mr-2 inline-block h-2.5 w-2.5 rounded-full" style="background-color: {{ $c['color'] ?: '#9ca3af' }}"></span>{{ $c['nombre'] }}</td>
                                @foreach ($c['por_ciclo'] as $v)<td class="whitespace-nowrap px-4 py-2 text-right {{ $v == 0 ? 'text-gray-300 dark:text-gray-600' : '' }}">{{ $v == 0 ? '—' : number_format($v, 2) }}</td>@endforeach
                                <td class="whitespace-nowrap px-4 py-2 text-right font-semibold text-gray-900 dark:text-gray-100">{{ number_format($c['monto'], 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="border-t-2 border-gray-200 font-semibold text-gray-900 dark:border-gray-600 dark:text-gray-100">
                        <tr>
                            <td class="sticky left-0 bg-white px-4 py-3 dark:bg-gray-800">Total</td>
                            @foreach ($d['por_ciclo'] as $v)<td class="whitespace-nowrap px-4 py-3 text-right">{{ number_format($v, 2) }}</td>@endforeach
                            <td class="whitespace-nowrap px-4 py-3 text-right">{{ number_format($d['total'], 2) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </section>
    @endif

    {{-- Cada categoría con su barra; al abrirla, sus movimientos --}}
    <section class="rounded-lg bg-white p-6 shadow dark:bg-gray-800">
        <h2 class="font-semibold text-gray-900 dark:text-gray-100">Detalle por categoría</h2>
        <div class="mt-4 divide-y divide-gray-100 dark:divide-gray-700">
            @foreach ($d['categorias'] as $c)
                <details class="group py-2">
                    <summary class="grid cursor-pointer list-none grid-cols-12 items-center gap-2 text-sm">
                        <span class="col-span-4 flex items-center gap-2 truncate text-gray-800 dark:text-gray-200">
                            <svg class="h-4 w-4 shrink-0 text-gray-400 transition group-open:rotate-90" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                            {{ $c['nombre'] }} <span class="text-xs text-gray-500 dark:text-gray-400">({{ $c['cantidad'] }})</span>
                        </span>
                        <span class="col-span-5 h-2.5 rounded-full bg-gray-100 dark:bg-gray-700">
                            <span class="barra-crece block h-2.5 rounded-full" style="--retraso: {{ $loop->index * 50 }}ms; width: {{ max(2, $c['monto'] / $maxGasto * 100) }}%; background-color: {{ $c['color'] ?: '#2a78d6' }}"></span>
                        </span>
                        <span class="col-span-3 text-right font-medium text-gray-900 dark:text-gray-100">{{ $dinero($c['monto']) }} <span class="text-xs font-normal text-gray-500 dark:text-gray-400">{{ $c['porcentaje'] }}%</span></span>
                    </summary>
                    <table class="mt-2 min-w-full text-sm">
                        <tbody class="divide-y divide-gray-50 text-gray-700 dark:divide-gray-700/50 dark:text-gray-300">
                            @foreach ($c['movimientos'] as $m)
                                <tr>
                                    <td class="whitespace-nowrap py-1.5 pl-6 pr-3 text-gray-500 dark:text-gray-400">{{ \Carbon\Carbon::parse($m['fecha'])->format('d/m/Y') }}</td>
                                    <td class="px-3 py-1.5"><a href="{{ route('movimientos.ver', [$m['clase'], $m['id']]) }}" class="hover:underline">@include('hogar._por', ['id' => $m['user_id']]){{ $m['descripcion'] }}</a></td>
                                    <td class="px-3 py-1.5 text-gray-500 dark:text-gray-400">{{ $m['donde'] }}</td>
                                    <td class="whitespace-nowrap px-3 py-1.5 text-right">{{ $dinero($m['monto']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </details>
            @endforeach
        </div>
    </section>
@endif
