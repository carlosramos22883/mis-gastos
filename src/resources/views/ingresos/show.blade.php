@php($simbolo = auth()->user()->monedaPreferida?->simbolo ?? '$')
@php($dinero = fn ($v) => $simbolo.' '.number_format((float) $v, 2))
<x-app-layout>
    <x-slot name="header"><h1>{{ $fuente->nombre }}</h1></x-slot>
    <div class="max-w-7xl mx-auto space-y-6" x-data x-on:refresh-table.window="$event.detail?.redirect ? window.location = $event.detail.redirect : window.location.reload()">
        <div class="flex flex-wrap gap-2">
            <x-secondary-button type="button" onclick="window.location='{{ route('ingresos.index') }}'">Todos los ingresos</x-secondary-button>
            @can('ingresos.create')
                @if ($fuente->activo)
                    <x-primary-button type="button" x-on:click="$dispatch('load-recibo-modal-form', '{{ route('ingresos.recibos.create', $fuente) }}'); $dispatch('open-modal', 'recibo-modal')">Registrar pago recibido</x-primary-button>
                @endif
            @endcan
            @can('ingresos.edit')
                <x-secondary-button type="button" x-on:click="$dispatch('load-fuente-modal-form', '{{ route('ingresos.edit', $fuente) }}'); $dispatch('open-modal', 'fuente-modal')">Editar</x-secondary-button>
            @endcan
            @can('ingresos.delete')
                <x-danger-button type="button" x-on:click="deleteItem({{ $fuente->id }}, @js($fuente->nombre), '{{ route('ingresos.destroy', $fuente) }}')">Eliminar</x-danger-button>
            @endcan
        </div>
        <p class="text-sm text-gray-600 dark:text-gray-300">
            @include('hogar._por', ['id' => $fuente->user_id]){{ \App\Models\FuenteIngreso::TIPOS[$fuente->tipo] ?? $fuente->tipo }}{{ $fuente->pagador ? ' · '.$fuente->pagador : '' }}
            · {{ $fuente->frecuencia === 'quincenal' ? 'quincenal (días '.$fuente->dia_pago.' y '.$fuente->dia_pago_2.')' : 'mensual'.($fuente->dia_pago ? ' (día '.$fuente->dia_pago.')' : '') }}
            @if ($fuente->conceptos->isNotEmpty()) · plantilla: {{ $fuente->conceptos->map(fn ($c) => $c->nombre.($c->porcentaje !== null ? ' '.(float) $c->porcentaje.' %' : ($c->monto !== null ? ' '.$dinero($c->monto) : '')))->implode(', ') }} @endif
        </p>

        <div class="rounded-lg bg-white p-6 shadow dark:bg-gray-800">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="font-semibold text-gray-900 dark:text-gray-100">Resumen {{ $resumen['anio'] }} <span class="text-sm font-normal text-gray-500">· {{ $resumen['pagos'] }} {{ $resumen['pagos'] === 1 ? 'pago' : 'pagos' }}</span></h2>
                @if ($anios->count() > 1)
                    <div class="flex gap-2 text-sm">@foreach ($anios as $a)<a href="?anio={{ $a }}" class="{{ $a === $resumen['anio'] ? 'font-semibold text-primary-600' : 'text-gray-500 hover:underline' }}">{{ $a }}</a>@endforeach</div>
                @endif
            </div>
            <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div><p class="text-xs text-gray-500">Bruto</p><p class="text-xl font-semibold text-gray-900 dark:text-gray-100">{{ $dinero($resumen['bruto']) }}</p></div>
                @foreach ($resumen['conceptos'] as $c)
                    <div><p class="text-xs text-gray-500">{{ $c['tipo'] === 'adicion' ? '+' : '−' }} {{ $c['nombre'] }}</p><p class="text-xl font-semibold {{ $c['tipo'] === 'adicion' ? 'text-green-600' : 'text-gray-900 dark:text-gray-100' }}">{{ $dinero($c['monto']) }}</p></div>
                @endforeach
                <div><p class="text-xs text-gray-500">Neto recibido</p><p class="text-xl font-semibold text-primary-600 dark:text-primary-400">{{ $dinero($resumen['neto']) }}</p></div>
            </div>
        </div>

        <div class="overflow-hidden rounded-lg bg-white shadow dark:bg-gray-800">
            <h2 class="border-b border-gray-200 px-6 py-4 font-semibold text-gray-900 dark:border-gray-700 dark:text-gray-100">Pagos recibidos</h2>
            <ul class="divide-y divide-gray-200 dark:divide-gray-700">
                @forelse ($recibos as $recibo)
                    <li class="flex flex-wrap items-start justify-between gap-3 px-6 py-4 text-sm">
                        <div>
                            <p class="font-medium text-gray-900 dark:text-gray-100">{{ $recibo->fecha->format('d/m/Y') }} · neto {{ $dinero($recibo->neto) }}
                                <span class="font-normal text-gray-500">→ {{ $recibo->destino === 'cuenta' ? ($recibo->movimientoCuenta?->cuenta?->nombre ?? 'Cuenta') : ($recibo->destino === 'efectivo' ? 'Efectivo' : 'solo anotado') }}</span></p>
                            <p class="text-gray-500 dark:text-gray-400">Bruto {{ $dinero($recibo->bruto) }}@foreach ($recibo->lineas as $l) · {{ $l->tipo === 'adicion' ? '+' : '−' }}{{ $l->nombre }} {{ $dinero($l->monto) }}@if ($l->prestamo_pago_id) <span title="Registrado en Préstamos">✓</span>@endif @endforeach</p>
                            @if ($recibo->comentario)<p class="text-xs text-gray-400">{{ $recibo->comentario }}</p>@endif
                        </div>
                        @can('ingresos.delete')
                            <button type="button" class="text-xs font-medium text-red-600 hover:underline dark:text-red-400" x-on:click="deleteItem({{ $recibo->id }}, @js('el pago del '.$recibo->fecha->format('d/m/Y')), '{{ route('recibos.destroy', $recibo) }}')">Eliminar</button>
                        @endcan
                    </li>
                @empty
                    <li class="px-6 py-8 text-center text-gray-500 dark:text-gray-400">Aún no hay pagos registrados.</li>
                @endforelse
            </ul>
        </div>
    </div>
    <x-ajax-modal name="fuente" maxWidth="4xl" />
    <x-ajax-modal name="recibo" maxWidth="3xl" />
</x-app-layout>
