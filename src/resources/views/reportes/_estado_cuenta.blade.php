@php
    $elegida = $tarjetas->firstWhere('id', (int) request('tarjeta'));
    $select = 'mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100';
@endphp
<form method="GET" action="{{ route('reportes.show', ['estado-cuenta']) }}" class="grid gap-4 rounded-lg bg-white p-4 shadow sm:grid-cols-2 dark:bg-gray-800">
    <label class="text-sm text-gray-600 dark:text-gray-300">Tarjeta
        <select name="tarjeta" class="{{ $select }}" onchange="this.form.estado && (this.form.estado.value = ''); this.form.submit()">
            <option value="">Elegir…</option>
            @foreach ($tarjetas as $t)
                <option value="{{ $t->id }}" @selected($elegida?->id === $t->id)>{{ $t->nombre }}{{ $t->activo ? '' : ' (inactiva)' }}</option>
            @endforeach
        </select>
    </label>
    @if ($elegida)
        <label class="text-sm text-gray-600 dark:text-gray-300">Corte
            <select name="estado" class="{{ $select }}" onchange="this.form.submit()" @disabled($elegida->estadosCuenta->isEmpty())>
                <option value="">{{ $elegida->estadosCuenta->isEmpty() ? 'Aún no tiene estados de cuenta' : 'Elegir…' }}</option>
                @foreach ($elegida->estadosCuenta as $e)
                    <option value="{{ $e->id }}" @selected((int) request('estado') === $e->id)>{{ $e->fecha_corte->format('d/m/Y') }} · {{ $dinero($e->total) }}</option>
                @endforeach
            </select>
        </label>
    @endif
</form>

@if ($elegida && $elegida->estadosCuenta->isEmpty())
    <p class="rounded-lg bg-white p-6 text-sm text-gray-500 shadow dark:bg-gray-800 dark:text-gray-400">Los estados de cuenta se generan solos en cada corte; esta tarjeta aún no ha cortado desde que se registró.</p>
@endif

@if ($d)
    <section class="overflow-hidden rounded-lg bg-white shadow dark:bg-gray-800">
        <div class="flex flex-wrap items-start justify-between gap-4 border-l-4 px-6 py-4" style="border-color: {{ $d['tarjeta']['color'] }}">
            <div>
                <h2 class="font-semibold text-gray-900 dark:text-gray-100">{{ $d['tarjeta']['nombre'] }}{{ $d['tarjeta']['ultimos_digitos'] ? ' •••• '.$d['tarjeta']['ultimos_digitos'] : '' }}</h2>
                <p class="text-sm text-gray-500 dark:text-gray-400">Del {{ $fecha($d['fecha_inicio']) }} al {{ $fecha($d['fecha_corte']) }} · pagar antes del {{ $fecha($d['fecha_limite_pago']) }}</p>
            </div>
            <dl class="flex gap-6 text-right text-sm">
                <div><dt class="text-xs text-gray-500 dark:text-gray-400">Total</dt><dd class="text-lg font-bold text-gray-900 dark:text-gray-100">{{ $dinero($d['total']) }}</dd></div>
                <div><dt class="text-xs text-gray-500 dark:text-gray-400">Pagado</dt><dd class="text-lg font-bold text-green-700 dark:text-green-400">{{ $dinero($d['pagado']) }}</dd></div>
                <div><dt class="text-xs text-gray-500 dark:text-gray-400">Pendiente</dt><dd class="text-lg font-bold text-gray-900 dark:text-gray-100">{{ $dinero($d['pendiente']) }}</dd></div>
            </dl>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-cabecera text-xs uppercase text-gray-600 dark:bg-gray-700/50 dark:text-gray-400">
                    <tr><th class="px-4 py-3 text-left">Fecha</th><th class="px-4 py-3 text-left">Descripción</th><th class="px-4 py-3 text-left">Categoría</th><th class="px-4 py-3 text-center">Cuota</th><th class="px-4 py-3 text-right">Monto</th></tr>
                </thead>
                <tbody class="divide-y divide-gray-100 text-gray-700 dark:divide-gray-700 dark:text-gray-300">
                    @foreach ($d['lineas'] as $l)
                        <tr>
                            <td class="whitespace-nowrap px-4 py-2">{{ $fecha($l['fecha']) }}</td>
                            <td class="px-4 py-2">{{ $l['descripcion'] }}</td>
                            <td class="px-4 py-2 text-gray-500 dark:text-gray-400">{{ $l['categoria'] ?? '—' }}</td>
                            <td class="px-4 py-2 text-center">{{ $l['cuota'] ?? '' }}</td>
                            <td class="whitespace-nowrap px-4 py-2 text-right {{ $l['monto'] < 0 ? 'text-green-700 dark:text-green-400' : '' }}">{{ $dinero($l['monto']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot class="border-t-2 border-gray-200 font-semibold text-gray-900 dark:border-gray-600 dark:text-gray-100">
                    <tr><td class="px-4 py-3" colspan="4">Total del corte</td><td class="px-4 py-3 text-right">{{ $dinero($d['total']) }}</td></tr>
                </tfoot>
            </table>
        </div>
        @if ($d['estado'] === 'pendiente' && $d['compromiso_id'])
            <div class="border-t border-gray-200 px-6 py-3 text-right dark:border-gray-700">
                <a href="{{ route('compromisos.show', $d['compromiso_id']) }}" class="text-sm text-primary-600 hover:underline dark:text-primary-400">Ir a pagarlo</a>
            </div>
        @endif
    </section>
@endif
