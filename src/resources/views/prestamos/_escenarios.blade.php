{{-- Resultado del simulador. $escenarios viene de PrestamoService::escenarios() --}}
@php($simbolo = auth()->user()->monedaPreferida?->simbolo ?? '$')
@php($base = $escenarios['base'])
@php($mes = fn ($f) => $f ? \Carbon\Carbon::parse($f)->translatedFormat('M Y') : '—')
@php($dinero = fn ($v) => $v === null ? '—' : $simbolo.' '.number_format($v, 2))
<div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
    <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-700">
        <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">Si sigo igual</p>
        @if ($base)
            <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">Terminas en <strong>{{ $mes($base['fecha_fin']) }}</strong> ({{ $base['meses'] }} cuotas).</p>
            <p class="text-sm text-gray-600 dark:text-gray-300">Pagarás <strong>{{ $dinero($base['total_intereses']) }}</strong> de intereses y {{ $dinero($base['total_cargos']) }} de seguros y cargos.</p>
        @else
            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Sin cuota fija: escribe cuánto puedes pagar al mes.</p>
        @endif
    </div>
    <div class="rounded-lg border border-green-200 bg-green-50 p-4 dark:border-green-800 dark:bg-green-900/20">
        <p class="text-sm font-semibold text-green-800 dark:text-green-300">Si pago todo hoy</p>
        <p class="mt-2 text-sm text-green-800 dark:text-green-200">Necesitas <strong>{{ $dinero($escenarios['pagar_hoy']['monto']) }}</strong> (saldo + {{ $dinero($escenarios['pagar_hoy']['interes_a_hoy']) }} de interés a hoy).</p>
        @if ($escenarios['pagar_hoy']['ahorro'] !== null)
            <p class="text-sm text-green-800 dark:text-green-200">Te ahorras <strong>{{ $dinero($escenarios['pagar_hoy']['ahorro']) }}</strong>.</p>
        @endif
    </div>
    @isset($escenarios['extra_mensual'])
        @php($e = $escenarios['extra_mensual'])
        <div class="rounded-lg border border-primary-200 bg-primary-50 p-4 dark:border-primary-800 dark:bg-primary-900/20">
            <p class="text-sm font-semibold text-primary-800 dark:text-primary-300">Abonando {{ $dinero($e['monto']) }} extra al mes</p>
            <p class="mt-2 text-sm text-primary-800 dark:text-primary-200">Terminas en <strong>{{ $mes($e['tabla']['fecha_fin']) }}</strong>@if ($e['meses_menos']) ({{ $e['meses_menos'] }} {{ $e['meses_menos'] === 1 ? 'mes' : 'meses' }} antes)@endif.</p>
            @if ($e['ahorro'] !== null)<p class="text-sm text-primary-800 dark:text-primary-200">Te ahorras <strong>{{ $dinero($e['ahorro']) }}</strong>.</p>@endif
        </div>
    @endisset
    @isset($escenarios['abono'])
        @php($a = $escenarios['abono'])
        <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 dark:border-amber-800 dark:bg-amber-900/20">
            <p class="text-sm font-semibold text-amber-800 dark:text-amber-300">Abono de {{ $dinero($a['monto']) }} hoy · {{ $a['modo'] === 'cuota' ? 'bajar la cuota' : 'terminar antes' }}</p>
            @if ($a['modo'] === 'cuota')
                <p class="mt-2 text-sm text-amber-800 dark:text-amber-200">La cuota baja a <strong>{{ $dinero($a['cuota_nueva']) }}</strong> (capital + interés), terminando en {{ $mes($a['tabla']['fecha_fin']) }}.</p>
            @else
                <p class="mt-2 text-sm text-amber-800 dark:text-amber-200">Terminas en <strong>{{ $mes($a['tabla']['fecha_fin']) }}</strong>@if ($a['meses_menos']) ({{ $a['meses_menos'] }} meses antes)@endif.</p>
            @endif
            @if ($a['ahorro'] !== null)<p class="text-sm text-amber-800 dark:text-amber-200">Te ahorras <strong>{{ $dinero($a['ahorro']) }}</strong>.</p>@endif
        </div>
    @endisset
</div>
<p class="mt-3 text-xs text-gray-500 dark:text-gray-400">Estimado con interés = saldo × tasa × días / 365 y la cuota fija; los seguros se toman como fijos. Algunos bancos cobran comisión por pago anticipado: consúltalo antes de abonar.</p>
