<x-app-layout>
    <x-slot name="header">{{ $compromiso->descripcion }}</x-slot>
    <div class="max-w-7xl mx-auto space-y-6">
        <p class="text-sm text-gray-500">Detalle de pagos del compromiso</p>
        @can('efectivo.create')
            <a class="inline-flex rounded-lg bg-primary-700 px-4 py-2 text-sm font-medium text-white" href="{{ route('efectivo.create', ['compromiso' => $compromiso->id]) }}">Registrar pago</a>
        @endcan
        <div class="grid gap-4 md:grid-cols-3">
            @foreach ([['Valor del compromiso', $compromiso->monto], ['Monto pagado', (float) $compromiso->monto - (float) ($compromiso->saldo_pendiente ?? $compromiso->monto)], ['Falta por pagar', $compromiso->saldo_pendiente ?? $compromiso->monto]] as [$label, $value])
                <div class="rounded-lg bg-white p-5 shadow dark:bg-gray-800"><span class="text-sm text-gray-500">{{ $label }}</span><strong class="mt-1 block text-2xl">{{ auth()->user()->monedaPreferida?->simbolo ?? '$' }} {{ number_format((float) $value, 2) }}</strong></div>
            @endforeach
        </div>
        <div class="overflow-x-auto rounded-lg bg-white shadow dark:bg-gray-800">
            <table class="min-w-full"><thead><tr><th class="px-6 py-3 text-left">Descripción</th><th class="px-6 py-3 text-left">Fecha</th><th class="px-6 py-3 text-left">Valor</th><th class="px-6 py-3 text-left">Cuota</th></tr></thead>
            <tbody>
                @forelse($compromiso->movimientos()->where('tipo', 'egreso')->latest('fecha')->get() as $movimiento)
                    <tr class="border-t"><td class="px-6 py-4">{{ $movimiento->descripcion }}</td><td class="px-6 py-4">{{ $movimiento->fecha->format('d/m/Y') }}</td><td class="px-6 py-4">{{ auth()->user()->monedaPreferida?->simbolo ?? '$' }} {{ number_format((float) $movimiento->monto, 2) }}</td><td class="px-6 py-4">{{ $movimiento->compromiso_cuota ?? '—' }}</td></tr>
                @empty
                    <tr><td colspan="4" class="px-6 py-10 text-center text-gray-500">No hay pagos registrados.</td></tr>
                @endforelse
            </tbody></table>
        </div>
    </div>
</x-app-layout>
