@php($simbolo = auth()->user()->monedaPreferida?->simbolo ?? '$')
@php($estados = ['activa' => ['Pendiente', 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300'], 'parcial' => ['Abonada', 'bg-sky-100 text-sky-800 dark:bg-sky-900/40 dark:text-sky-300'], 'pagada' => ['Pagada', 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300']])
<x-app-layout>
    <x-slot name="header"><h1>{{ $deudor->nombre }}</h1></x-slot>
    <div class="max-w-7xl mx-auto space-y-6" x-data x-on:refresh-table.window="$event.detail?.redirect ? window.location = $event.detail.redirect : window.location.reload()">
        <div class="flex flex-wrap gap-2">
            <x-secondary-button type="button" onclick="window.location='{{ route('deudores.index') }}'">Todos los deudores</x-secondary-button>
            @can('deudores.create')
                <x-primary-button type="button" x-on:click="$dispatch('load-deuda-modal-form', '{{ route('deudores.deudas.create', $deudor) }}'); $dispatch('open-modal', 'deuda-modal')">Prestar / anotar deuda</x-primary-button>
                @if ($deudor->pendiente > 0)
                    <x-primary-button type="button" x-on:click="$dispatch('load-pago-modal-form', '{{ route('deudores.pagos.create', $deudor) }}'); $dispatch('open-modal', 'pago-modal')">Registrar pago</x-primary-button>
                @endif
            @endcan
            @can('deudores.edit')
                <x-secondary-button type="button" x-on:click="$dispatch('load-deudor-modal-form', '{{ route('deudores.edit', $deudor) }}'); $dispatch('open-modal', 'deudor-modal')">Editar</x-secondary-button>
            @endcan
            @can('deudores.delete')
                <x-danger-button type="button" x-on:click="deleteItem({{ $deudor->id }}, @js($deudor->nombre), '{{ route('deudores.destroy', $deudor) }}')">Eliminar</x-danger-button>
            @endcan
        </div>

        <div class="grid gap-4 sm:grid-cols-3">
            @foreach ([['Prestado', $deudor->prestado], ['Ha pagado', $deudor->pagado], ['Te debe', $deudor->pendiente]] as [$label, $valor])
                <div class="rounded-lg bg-white p-4 shadow dark:bg-gray-800">
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ $label }}</p>
                    <p class="mt-1 text-xl font-semibold text-gray-900 dark:text-gray-100">{{ $simbolo }} {{ number_format($valor, 2) }}</p>
                </div>
            @endforeach
        </div>
        @if ($deudor->contacto || $deudor->notas)
            <p class="text-sm text-gray-600 dark:text-gray-300">{{ collect([$deudor->contacto, $deudor->notas])->filter()->implode(' · ') }}</p>
        @endif

        <div class="overflow-hidden rounded-lg bg-white shadow dark:bg-gray-800">
            <h2 class="border-b border-gray-200 px-6 py-4 font-semibold text-gray-900 dark:border-gray-700 dark:text-gray-100">Deudas</h2>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                    <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500 dark:bg-gray-700/50 dark:text-gray-400">
                        <tr><th class="px-6 py-3">Fecha</th><th class="px-6 py-3">Descripción</th><th class="px-6 py-3">Salió de</th><th class="px-6 py-3 text-right">Monto</th><th class="px-6 py-3 text-right">Pendiente</th><th class="px-6 py-3">Estado</th><th class="px-6 py-3"></th></tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                        @forelse ($deudor->deudas->sortByDesc('fecha') as $deuda)
                            @php([$etiqueta, $clase] = $estados[$deuda->estado()])
                            <tr class="text-gray-700 dark:text-gray-300">
                                <td class="whitespace-nowrap px-6 py-3">{{ $deuda->fecha->format('d/m/Y') }}</td>
                                <td class="px-6 py-3">@include('hogar._por', ['id' => $deuda->user_id]){{ $deuda->descripcion }}@if ($deuda->comentario)<span class="block text-xs text-gray-400">{{ $deuda->comentario }}</span>@endif</td>
                                <td class="px-6 py-3">{{ ['efectivo' => 'Efectivo', 'cuenta' => 'Cuenta', 'tarjeta' => 'Tarjeta', 'ninguno' => '—'][$deuda->origen] }}</td>
                                <td class="whitespace-nowrap px-6 py-3 text-right">{{ $simbolo }} {{ number_format((float) $deuda->monto, 2) }}</td>
                                <td class="whitespace-nowrap px-6 py-3 text-right font-medium">{{ $simbolo }} {{ number_format($deuda->pendiente(), 2) }}</td>
                                <td class="px-6 py-3"><span class="rounded-full px-2 py-1 text-xs font-medium {{ $clase }}">{{ $etiqueta }}</span></td>
                                <td class="px-6 py-3 text-right">
                                    @can('deudores.delete')
                                        @if ($deuda->pagado() <= 0)
                                            <button type="button" class="text-xs font-medium text-red-600 hover:underline dark:text-red-400" x-on:click="deleteItem({{ $deuda->id }}, @js($deuda->descripcion), '{{ route('deudas.destroy', $deuda) }}')">Eliminar</button>
                                        @endif
                                    @endcan
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-6 py-8 text-center text-gray-500 dark:text-gray-400">Sin deudas registradas.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="overflow-hidden rounded-lg bg-white shadow dark:bg-gray-800">
            <h2 class="border-b border-gray-200 px-6 py-4 font-semibold text-gray-900 dark:border-gray-700 dark:text-gray-100">Pagos recibidos</h2>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                    <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500 dark:bg-gray-700/50 dark:text-gray-400">
                        <tr><th class="px-6 py-3">Fecha</th><th class="px-6 py-3">Aplicado a</th><th class="px-6 py-3">Entró a</th><th class="px-6 py-3 text-right">Monto</th><th class="px-6 py-3"></th></tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                        @forelse ($pagos as $pago)
                            <tr class="text-gray-700 dark:text-gray-300">
                                <td class="whitespace-nowrap px-6 py-3">{{ $pago->fecha->format('d/m/Y') }}</td>
                                <td class="px-6 py-3">@include('hogar._por', ['id' => $pago->user_id]){{ $pago->deuda->descripcion }}@if ($pago->comentario)<span class="block text-xs text-gray-400">{{ $pago->comentario }}</span>@endif</td>
                                <td class="px-6 py-3">{{ $pago->destino === 'efectivo' ? 'Efectivo' : ($pago->destino === 'cuenta' ? ($pago->movimientoCuenta?->cuenta?->nombre ?? 'Cuenta') : '—') }}</td>
                                <td class="whitespace-nowrap px-6 py-3 text-right">{{ $simbolo }} {{ number_format((float) $pago->monto, 2) }}</td>
                                <td class="px-6 py-3 text-right">
                                    @can('deudores.delete')
                                        <button type="button" class="text-xs font-medium text-red-600 hover:underline dark:text-red-400" x-on:click="deleteItem({{ $pago->id }}, @js('el pago del '.$pago->fecha->format('d/m/Y')), '{{ route('deuda-pagos.destroy', $pago) }}')">Eliminar</button>
                                    @endcan
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-6 py-8 text-center text-gray-500 dark:text-gray-400">Aún no ha pagado nada.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <x-ajax-modal name="deudor" />
    <x-ajax-modal name="deuda" />
    <x-ajax-modal name="pago" />
</x-app-layout>
