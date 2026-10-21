@php($simbolo = auth()->user()->monedaPreferida?->simbolo ?? '$')
@php($r = $resumen)
@php($dinero = fn ($v) => $v === null ? '—' : $simbolo.' '.number_format($v, 2))
@php($origenes = ['efectivo' => 'Efectivo', 'cuenta' => 'Cuenta', 'planilla' => 'Planilla', 'ninguno' => '—'])
<x-app-layout>
    <x-slot name="header"><h1>{{ $prestamo->nombre }}</h1></x-slot>
    <div class="max-w-7xl mx-auto space-y-6" x-data x-on:refresh-table.window="$event.detail?.redirect ? window.location = $event.detail.redirect : window.location.reload()">
        <div class="flex flex-wrap gap-2">
            <x-volver />
            <x-secondary-button type="button" onclick="window.location='{{ route('prestamos.index') }}'">Todos los préstamos</x-secondary-button>
            @can('prestamos.create')
                @if ($r['saldo'] > 0)
                    <x-primary-button type="button" x-on:click="$dispatch('load-pago-modal-form', '{{ route('prestamos.pagos.create', $prestamo) }}'); $dispatch('open-modal', 'pago-modal')">Registrar pago</x-primary-button>
                @endif
            @endcan
            @can('prestamos.edit')
                <x-secondary-button type="button" x-on:click="$dispatch('load-prestamo-modal-form', '{{ route('prestamos.edit', $prestamo) }}'); $dispatch('open-modal', 'prestamo-modal')">Editar</x-secondary-button>
            @endcan
            @can('prestamos.delete')
                <x-danger-button type="button" x-on:click="deleteItem({{ $prestamo->id }}, {{ \Illuminate\Support\Js::from($prestamo->nombre) }}, '{{ route('prestamos.destroy', $prestamo) }}')">Eliminar</x-danger-button>
            @endcan
        </div>

        <p class="flex flex-wrap items-center gap-x-1 text-sm text-gray-600 dark:text-gray-300">
            <x-logo-banco :banco="$prestamo->banco" class="mr-2" />
            @include('hogar._por', ['id' => $prestamo->user_id]){{ $prestamo->tipoPrestamo?->nombre }} · {{ $prestamo->nombreAcreedor() }}
            · {{ (float) $prestamo->tasa_anual > 0 ? rtrim(rtrim(number_format((float) $prestamo->tasa_anual, 3), '0'), '.').' % anual' : 'sin interés' }}
            · {{ \App\Models\Prestamo::MEDIOS[$prestamo->medio_pago] ?? '' }}@if ((float) $prestamo->monto_planilla > 0) ({{ $simbolo }} {{ number_format((float) $prestamo->monto_planilla, 2) }} por planilla)@endif
            @if ($prestamo->recurrente) · <a class="text-primary-600 hover:underline dark:text-primary-400" href="{{ route('compromisos.index') }}">cuota en Compromisos</a>@endif
        </p>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
            @foreach ([
                ['Debes', $dinero($r['saldo']), 'de '.$dinero((float) $prestamo->monto_original).' · '.$r['avance'].' % pagado'],
                ['Cuota', $dinero($r['cuota_total']), $r['cuota'] ? $dinero($r['cuota']).' + '.$dinero($r['cargos']).' de cargos' : 'sin cuota fija'],
                ['Próximo pago', $r['proxima_fecha'] ? \Carbon\Carbon::parse($r['proxima_fecha'])->format('d/m/Y') : '—', 'interés a hoy '.$dinero($escenarios['pagar_hoy']['interes_a_hoy'])],
                ['Terminas', $r['fecha_fin'] ? \Carbon\Carbon::parse($r['fecha_fin'])->translatedFormat('M Y') : '—', $r['meses_restantes'] ? $r['meses_restantes'].' cuotas' : ''],
                ['Intereses por pagar', $dinero($r['intereses_restantes']), 'pagados: '.$dinero($r['pagado_intereses'])],
            ] as [$label, $valor, $nota])
                <div class="rounded-lg bg-white p-4 shadow dark:bg-gray-800">
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ $label }}</p>
                    <p class="mt-1 text-xl font-semibold text-gray-900 dark:text-gray-100">{{ $valor }}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ $nota }}</p>
                </div>
            @endforeach
        </div>

        @if ($r['saldo'] > 0)
            <div class="space-y-4 rounded-lg bg-white p-6 shadow dark:bg-gray-800"
                x-data="{ cargando: false, async simular(form) { this.cargando = true; const url = new URL(form.action); new FormData(form).forEach((v, k) => v !== '' && url.searchParams.set(k, v.replace(/,/g, ''))); const r = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } }); this.cargando = false; if (r.ok) { $refs.resultado.innerHTML = await r.text(); } else { const d = await r.json().catch(() => ({})); showAlert('error', 'Revisa los datos', d.errors ? Object.values(d.errors)[0][0] : (d.message || 'No se pudo simular.')); } } }">
                <h2 class="font-semibold text-gray-900 dark:text-gray-100">¿Qué pasa si…?</h2>
                <form action="{{ route('prestamos.simular', $prestamo) }}" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5" x-on:submit.prevent="simular($el)">
                    @unless ($r['cuota'])
                        <x-floating-money id="cuota" label="Puedo pagar al mes" :symbol="$simbolo" />
                    @endunless
                    <x-floating-money id="extra_mensual" label="Abono extra cada mes" :symbol="$simbolo" />
                    <x-floating-money id="abono" label="Abono único hoy" :symbol="$simbolo" />
                    <x-floating-select id="modo" label="Con el abono…" :allowEmpty="false" :options="['plazo' => 'Terminar antes', 'cuota' => 'Bajar la cuota']" value="plazo" />
                    <div class="flex items-center"><x-primary-button type="submit" x-bind:disabled="cargando">Calcular</x-primary-button></div>
                </form>
                <div x-ref="resultado">@include('prestamos._escenarios')</div>
            </div>
        @endif

        @if ($escenarios['base'] && $escenarios['base']['filas'])
            <div class="overflow-hidden rounded-lg bg-white shadow dark:bg-gray-800" x-data="{ todas: false }">
                <div class="flex items-center justify-between border-b border-gray-200 px-6 py-4 dark:border-gray-700">
                    <h2 class="font-semibold text-gray-900 dark:text-gray-100">Tabla de pagos que faltan</h2>
                    @if (count($escenarios['base']['filas']) > 12)
                        <button type="button" class="text-sm text-primary-600 hover:underline dark:text-primary-400" x-on:click="todas = !todas" x-text="todas ? 'Ver menos' : 'Ver las {{ count($escenarios['base']['filas']) }}'"></button>
                    @endif
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                        <thead class="bg-gray-50 text-right text-xs uppercase text-gray-500 dark:bg-gray-700/50 dark:text-gray-400">
                            <tr><th class="px-4 py-3 text-left">#</th><th class="px-4 py-3 text-left">Fecha</th><th class="px-4 py-3">Interés</th><th class="px-4 py-3">Capital</th><th class="px-4 py-3">Cargos</th><th class="px-4 py-3">Total</th><th class="px-4 py-3">Saldo</th></tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 text-right text-gray-700 dark:divide-gray-700 dark:text-gray-300">
                            @foreach ($escenarios['base']['filas'] as $i => $f)
                                <tr @if ($i >= 12) x-show="todas" x-cloak @endif>
                                    <td class="px-4 py-2 text-left">{{ $f['n'] }}</td>
                                    <td class="px-4 py-2 text-left">{{ \Carbon\Carbon::parse($f['fecha'])->format('d/m/Y') }}</td>
                                    <td class="px-4 py-2">{{ number_format($f['interes'], 2) }}</td>
                                    <td class="px-4 py-2">{{ number_format($f['capital'], 2) }}</td>
                                    <td class="px-4 py-2">{{ number_format($f['cargos'], 2) }}</td>
                                    <td class="px-4 py-2 font-medium">{{ number_format($f['total'], 2) }}</td>
                                    <td class="px-4 py-2">{{ number_format($f['saldo'], 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        <div class="overflow-hidden rounded-lg bg-white shadow dark:bg-gray-800">
            <h2 class="border-b border-gray-200 px-6 py-4 font-semibold text-gray-900 dark:border-gray-700 dark:text-gray-100">Pagos registrados</h2>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                    <thead class="bg-gray-50 text-right text-xs uppercase text-gray-500 dark:bg-gray-700/50 dark:text-gray-400">
                        <tr><th class="px-4 py-3 text-left">Fecha</th><th class="px-4 py-3 text-left">Desde</th><th class="px-4 py-3">Capital</th><th class="px-4 py-3">Interés</th><th class="px-4 py-3">Cargos</th><th class="px-4 py-3">Mora</th><th class="px-4 py-3">Total</th><th class="px-4 py-3"></th></tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 text-right text-gray-700 dark:divide-gray-700 dark:text-gray-300">
                        @forelse ($prestamo->pagos->sortByDesc(fn ($p) => $p->fecha->toDateString().'-'.str_pad($p->id, 10, '0', STR_PAD_LEFT))->values() as $i => $pago)
                            <tr>
                                <td class="px-4 py-2 text-left">{{ $pago->fecha->format('d/m/Y') }}</td>
                                <td class="px-4 py-2 text-left">@include('hogar._por', ['id' => $pago->user_id]){{ $pago->origen === 'cuenta' ? ($pago->movimientoCuenta?->cuenta?->nombre ?? 'Cuenta') : $origenes[$pago->origen] }}</td>
                                <td class="px-4 py-2">{{ number_format((float) $pago->capital, 2) }}</td>
                                <td class="px-4 py-2">{{ number_format((float) $pago->interes, 2) }}</td>
                                <td class="px-4 py-2">{{ number_format((float) $pago->cargos, 2) }}</td>
                                <td class="px-4 py-2">{{ number_format((float) $pago->mora, 2) }}</td>
                                <td class="px-4 py-2 font-medium">{{ number_format($pago->total(), 2) }}</td>
                                <td class="px-4 py-2">
                                    @if ($i === 0)
                                        @can('prestamos.delete')
                                            <button type="button" class="text-xs font-medium text-red-600 hover:underline dark:text-red-400" x-on:click="deleteItem({{ $pago->id }}, @js('el pago del '.$pago->fecha->format('d/m/Y')), '{{ route('prestamo-pagos.destroy', $pago) }}')">Eliminar</button>
                                        @endcan
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="px-6 py-8 text-center text-gray-500 dark:text-gray-400">Aún no hay pagos registrados desde que lo agregaste.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <x-ajax-modal name="prestamo" />
    <x-ajax-modal name="pago" />
</x-app-layout>
