@php
    $simbolo = auth()->user()->monedaPreferida?->simbolo ?? '$';
    $estado = $compromiso->estadoVisual($hoy, $inicio);
@endphp
<x-app-layout>
    <x-slot name="header"><h1>{{ $compromiso->descripcion }}</h1></x-slot>
    <div class="max-w-7xl mx-auto space-y-6" x-data x-on:refresh-table.window="window.location.reload()">
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
                @if ($compromiso->estado === \App\Models\Compromiso::PENDIENTE)
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
            @foreach ([['Valor del compromiso', $compromiso->monto, 'text-gray-900 dark:text-gray-100'], ['Pagado', $compromiso->montoPagado(), 'text-green-600 dark:text-green-400'], ['Falta por pagar', $compromiso->estado === \App\Models\Compromiso::CANCELADO ? 0 : $compromiso->saldo_pendiente, 'text-red-600 dark:text-red-400']] as [$label, $value, $color])
                <div class="rounded-lg bg-white p-5 shadow dark:bg-gray-800">
                    <span class="text-sm text-gray-500 dark:text-gray-400">{{ $label }}</span>
                    <strong class="mt-1 block text-2xl {{ $color }}">{{ $simbolo }} {{ number_format((float) $value, 2) }}</strong>
                </div>
            @endforeach
        </div>

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
    </div>
    @include('compromisos._modales')
</x-app-layout>
