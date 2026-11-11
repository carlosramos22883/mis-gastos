@php
    $simbolo = auth()->user()->monedaPreferida?->simbolo ?? '$';
    $estado = $compromiso->estadoVisual($hoy, $inicio);
@endphp
<x-app-layout>
    <x-slot name="header"><h1>{{ $compromiso->descripcion }}</h1></x-slot>
    <div class="max-w-7xl mx-auto space-y-6" x-data x-on:refresh-table.window="$event.detail?.redirect ? window.location = $event.detail.redirect : window.location.reload()">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap items-center gap-3 text-sm text-gray-600 dark:text-gray-300">
                @include('compromisos._estado', ['estado' => $estado])
                <span>{{ $compromiso->fecha_esperada ? 'Fecha de pago: '.$compromiso->fecha_esperada->format('d/m/Y') : 'Sin fecha de pago' }}</span>
                @if ($compromiso->categoria)<span>· {{ $compromiso->categoria->nombre }}</span>@endif
                @if ($compromiso->numero_cuota)<span>· Cuota {{ $compromiso->numero_cuota }}{{ $compromiso->total_cuotas ? ' de '.$compromiso->total_cuotas : '' }}</span>@endif
                @if ($compromiso->recurrente)<span>· {{ $compromiso->recurrente->descripcionFrecuencia() }}</span>@endif
            </div>
            <div class="flex gap-2">
                <x-volver />
                <x-secondary-button type="button" onclick="window.location='{{ route('compromisos.index') }}'">Todos los compromisos</x-secondary-button>
                @if ($compromiso->recurrente)
                    @can('compromisos.edit')
                        <x-secondary-button type="button" x-data x-on:click="$dispatch('load-compromiso-modal-form', '{{ route('compromisos.serie.edit', $compromiso) }}'); $dispatch('open-modal', 'compromiso-modal')">Editar la serie</x-secondary-button>
                    @endcan
                @endif
                @if ($compromiso->estado === \App\Models\Compromiso::PENDIENTE && ! $compromiso->es_presupuesto)
                    @canany(['efectivo.create', 'cuentas.create'])
                        <x-primary-button type="button" x-on:click="$dispatch('load-pago-modal-form', '{{ route('compromisos.pagar', $compromiso) }}'); $dispatch('open-modal', 'pago-modal')">Pagar</x-primary-button>
                    @endcanany
                @endif
            </div>
        </div>

        @if ($compromiso->comentario)
            <p class="rounded-lg bg-white p-4 text-sm text-gray-700 shadow dark:bg-gray-800 dark:text-gray-300">{{ $compromiso->comentario }}</p>
        @endif

        <div class="grid gap-4 md:grid-cols-3">
            @php($resumenP = $compromiso->es_presupuesto ? app(\App\Services\PresupuestoService::class)->resumen($compromiso) : null)
            @php($pasado = $resumenP && $resumenP['queda'] < 0)
            @foreach ($resumenP
                ? [['Presupuesto del ciclo', $resumenP['monto'], 'text-gray-900 dark:text-gray-100'], ['Gastado en «'.($compromiso->categoria?->nombre ?? 'su categoría').'»', $resumenP['gastado'], $pasado ? 'text-red-600 dark:text-red-400' : 'text-amber-600 dark:text-amber-400'], [$pasado ? 'Te pasaste por' : 'Te queda', abs($resumenP['queda']), $pasado ? 'text-red-600 dark:text-red-400' : 'text-green-600 dark:text-green-400']]
                : [['Valor del compromiso', $compromiso->monto, 'text-gray-900 dark:text-gray-100'], ['Pagado', $compromiso->montoPagado(), 'text-green-600 dark:text-green-400'], ['Falta por pagar', $compromiso->estado === \App\Models\Compromiso::CANCELADO ? 0 : $compromiso->saldo_pendiente, 'text-red-600 dark:text-red-400']]
                as [$label, $value, $color])
                <div class="rounded-lg bg-white p-5 shadow dark:bg-gray-800">
                    <span class="text-sm text-gray-500 dark:text-gray-400">{{ $label }}</span>
                    <strong class="mt-1 block text-2xl {{ $color }}">{{ $simbolo }} {{ number_format((float) $value, 2) }}</strong>
                </div>
            @endforeach
        </div>

        @if ($compromiso->tarjeta_credito_id && ($excedente = app(\App\Services\TarjetaService::class)->excedente($compromiso)) > 0)
            <p class="rounded-lg bg-green-50 px-4 py-3 text-sm text-green-700 dark:bg-gray-800 dark:text-green-400">Se pagaron {{ $simbolo }} {{ number_format($excedente, 2) }} de más: quedaron como crédito en la tarjeta {{ $compromiso->tarjeta?->nombre }} (subió su disponible y se descuenta del próximo corte).</p>
        @endif

        @if ($resumenP)
            @if ($resumenP['comprometido'] > 0)
                <p class="rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:bg-gray-800 dark:text-amber-300">Además hay {{ $simbolo }} {{ number_format($resumenP['comprometido'], 2) }} por pagar este ciclo en compromisos de «{{ $compromiso->categoria?->nombre }}»: ya están apartados, por eso no cuentan dos veces.</p>
            @endif
            {{-- Todo lo que descuenta el presupuesto, salga de donde salga --}}
            @php($movsP = app(\App\Services\PresupuestoService::class)->movimientos($compromiso))
            <div class="overflow-x-auto rounded-lg bg-white shadow dark:bg-gray-800">
                <h2 class="px-6 pt-5 text-base font-semibold text-gray-900 dark:text-gray-100">Gastos que lo descuentan</h2>
                <p class="px-6 text-xs text-gray-500 dark:text-gray-400">Egresos de «{{ $compromiso->categoria?->nombre }}» del ciclo, desde efectivo, cuentas o tarjetas.</p>
                <table class="mt-3 min-w-full text-sm text-left text-gray-700 dark:text-gray-300">
                    <thead class="text-xs uppercase bg-cabecera text-gray-600 dark:bg-gray-700 dark:text-gray-400">
                        <tr><th class="px-6 py-3">Fecha</th><th class="px-6 py-3">Descripción</th><th class="px-6 py-3">Desde</th><th class="px-6 py-3 text-right">Monto</th></tr>
                    </thead>
                    <tbody>
                        @forelse ($movsP as $m)
                            <tr class="border-t border-gray-200 dark:border-gray-700">
                                <td class="px-6 py-3 whitespace-nowrap">{{ $m->fecha->format('d/m/Y') }}</td>
                                <td class="px-6 py-3"><a href="{{ route('movimientos.ver', [$m->clase, $m->id, 'desde' => 'compromiso:'.$compromiso->id]) }}" class="hover:underline">{{ $m->descripcion }}</a></td>
                                <td class="px-6 py-3">{{ $m->origen }}</td>
                                <td class="px-6 py-3 text-right whitespace-nowrap">{{ $simbolo }} {{ number_format((float) $m->monto, 2) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-6 py-10 text-center text-gray-500">Aún no hay gastos de esta categoría en el ciclo.</td></tr>
                        @endforelse
                    </tbody>
                    @if ($movsP->isNotEmpty())
                        <tfoot class="font-semibold"><tr class="border-t border-gray-200 dark:border-gray-700"><td colspan="3" class="px-6 py-3 text-right">Total</td><td class="px-6 py-3 text-right whitespace-nowrap">{{ $simbolo }} {{ number_format($resumenP['gastado'], 2) }}</td></tr></tfoot>
                    @endif
                </table>
            </div>
        @else
        <div class="overflow-x-auto rounded-lg bg-white shadow dark:bg-gray-800">
            <h2 class="px-6 pt-5 text-base font-semibold text-gray-900 dark:text-gray-100">Abonos</h2>
            <table class="mt-3 min-w-full text-sm text-left text-gray-700 dark:text-gray-300">
                <thead class="text-xs uppercase bg-cabecera text-gray-600 dark:bg-gray-700 dark:text-gray-400">
                    <tr><th class="px-6 py-3">Fecha</th><th class="px-6 py-3">Descripción</th><th class="px-6 py-3">Medio</th><th class="px-6 py-3 text-right">Monto</th></tr>
                </thead>
                <tbody>
                    @forelse($pagos as $pago)
                        <tr class="border-t border-gray-200 dark:border-gray-700">
                            <td class="px-6 py-4">{{ $pago->fecha->format('d/m/Y') }}</td>
                            <td class="px-6 py-4">{{ $pago->descripcion }}</td>
                            <td class="px-6 py-4">{{ match (true) { $pago instanceof \App\Models\MovimientoCuenta => $pago->cuenta->nombre, $pago instanceof \App\Models\MovimientoTarjeta => 'Tarjeta '.$pago->tarjeta->nombre, default => 'Efectivo' } }}</td>
                            <td class="px-6 py-4 text-right">{{ $simbolo }} {{ number_format((float) $pago->monto, 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-6 py-10 text-center text-gray-500">Aún no hay abonos.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @endif
    </div>
    @include('compromisos._modales')
</x-app-layout>
