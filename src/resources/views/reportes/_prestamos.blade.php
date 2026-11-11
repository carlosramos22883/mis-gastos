@php($t = $d['totales'])
<div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
    @foreach ([
        ['Saldo de los préstamos', $dinero($t['saldo']), 'cuotas al mes: '.$dinero($t['cuotas_al_mes'])],
        ['Capital pagado', $dinero($t['pagado_capital']), 'con los pagos registrados'],
        ['Intereses pagados', $dinero($t['pagado_intereses']), 'cargos y mora: '.$dinero($t['pagado_cargos'])],
        ['Intereses que faltan', $dinero($t['intereses_restantes']), 'si se paga la cuota hasta el final'],
    ] as [$label, $valor, $nota])
        <div class="rounded-lg bg-white p-4 shadow dark:bg-gray-800">
            <p class="text-xs text-gray-500 dark:text-gray-400">{{ $label }}</p>
            <p class="mt-1 text-2xl font-bold text-gray-900 dark:text-gray-100">{{ $valor }}</p>
            <p class="text-xs text-gray-500 dark:text-gray-400">{{ $nota }}</p>
        </div>
    @endforeach
</div>

@forelse ($d['prestamos'] as $p)
    <section class="overflow-hidden rounded-lg bg-white shadow dark:bg-gray-800 {{ $p['activo'] ? '' : 'opacity-75' }}">
        <div class="flex flex-wrap items-start justify-between gap-3 border-l-4 px-6 py-4" style="border-color: {{ $p['color'] ?: '#7C3AED' }}">
            <div>
                <a href="{{ route('prestamos.show', $p['id']) }}" class="font-semibold text-gray-900 hover:underline dark:text-gray-100">@include('hogar._por', ['id' => $p['user_id']]){{ $p['nombre'] }}</a>
                @unless ($p['activo'])<span class="ml-2 rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-600 dark:bg-gray-700 dark:text-gray-300">Pagado</span>@endunless
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    {{ $p['acreedor'] }} · {{ $dinero($p['monto_original']) }}
                    @if ($p['tasa_anual'] !== null) · {{ rtrim(rtrim(number_format($p['tasa_anual'], 2), '0'), '.') }} % anual @endif
                    @if ($p['plazo_meses']) · {{ $p['plazo_meses'] }} meses @endif
                    @if ($p['fecha_otorgamiento']) · desde {{ $fecha($p['fecha_otorgamiento']) }} @endif
                </p>
            </div>
            <div class="text-right">
                <p class="text-xs text-gray-500 dark:text-gray-400">Saldo</p>
                <p class="text-xl font-bold text-gray-900 dark:text-gray-100">{{ $dinero($p['saldo']) }}</p>
            </div>
        </div>
        <div class="px-6 pb-4">
            <div class="h-2 rounded-full bg-gray-100 dark:bg-gray-700" role="progressbar" aria-valuenow="{{ $p['avance'] }}" aria-valuemin="0" aria-valuemax="100" aria-label="Avance del préstamo">
                <div class="barra-crece h-2 rounded-full" style="width: {{ $p['avance'] }}%; background-color: {{ $p['color'] ?: '#7C3AED' }}"></div>
            </div>
            <dl class="mt-3 grid grid-cols-2 gap-3 text-sm sm:grid-cols-3 lg:grid-cols-6">
                @foreach ([
                    ['Pagado', $p['avance'].' %'],
                    ['Cuota al mes', $p['cuota_total'] !== null ? $dinero($p['cuota_total']) : '—'],
                    ['Pagos registrados', $p['pagos_realizados']],
                    ['Capital pagado', $dinero($p['pagado_capital'])],
                    ['Intereses pagados', $dinero($p['pagado_intereses'])],
                    ['Faltan', $p['meses_restantes'] !== null ? $p['meses_restantes'].' meses · '.$fecha($p['fecha_fin']) : '—'],
                ] as [$label, $valor])
                    <div><dt class="text-xs text-gray-500 dark:text-gray-400">{{ $label }}</dt><dd class="font-medium text-gray-900 dark:text-gray-100">{{ $valor }}</dd></div>
                @endforeach
            </dl>
            @if ($p['intereses_restantes'])
                <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">Intereses que faltan por pagar: <strong class="text-red-700 dark:text-red-400">{{ $dinero($p['intereses_restantes']) }}</strong></p>
            @endif
            @if ($p['pagos'])
                <details class="mt-3">
                    <summary class="cursor-pointer text-sm text-primary-600 dark:text-primary-400">Ver los {{ count($p['pagos']) }} pagos</summary>
                    <table class="mt-2 min-w-full text-sm">
                        <thead class="bg-cabecera text-xs uppercase text-gray-600 dark:bg-gray-700/50 dark:text-gray-400">
                            <tr><th class="px-3 py-2 text-left">Fecha</th><th class="px-3 py-2 text-right">Capital</th><th class="px-3 py-2 text-right">Interés</th><th class="px-3 py-2 text-right">Cargos y mora</th><th class="px-3 py-2 text-right">Total</th></tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 text-gray-700 dark:divide-gray-700 dark:text-gray-300">
                            @foreach ($p['pagos'] as $pg)
                                <tr><td class="px-3 py-1.5">{{ $fecha($pg['fecha']) }}</td><td class="px-3 py-1.5 text-right">{{ number_format($pg['capital'], 2) }}</td><td class="px-3 py-1.5 text-right">{{ number_format($pg['interes'], 2) }}</td><td class="px-3 py-1.5 text-right">{{ number_format($pg['cargos'], 2) }}</td><td class="px-3 py-1.5 text-right font-medium">{{ number_format($pg['total'], 2) }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </details>
            @endif
        </div>
    </section>
@empty
    <p class="rounded-lg bg-white p-6 text-sm text-gray-500 shadow dark:bg-gray-800 dark:text-gray-400">No hay préstamos registrados.</p>
@endforelse
