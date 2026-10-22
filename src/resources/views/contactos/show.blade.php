@php($simbolo = auth()->user()->monedaPreferida?->simbolo ?? '$')
@php($dinero = fn ($v) => $simbolo.' '.number_format((float) $v, 2))
@php($estados = ['activa' => ['Pendiente', 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300'], 'parcial' => ['Abonada', 'bg-sky-100 text-sky-800 dark:bg-sky-900/40 dark:text-sky-300'], 'pagada' => ['Pagada', 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300']])
@php($desde = 'contacto:'.$contacto->id)
<x-app-layout>
    <x-slot name="header"><h1>{{ $contacto->nombreCompleto() }}</h1></x-slot>
    <div class="max-w-7xl mx-auto space-y-6" x-data x-on:refresh-table.window="$event.detail?.redirect ? window.location = $event.detail.redirect : window.location.reload()">
        <div class="flex flex-wrap items-center justify-between gap-4 rounded-lg bg-white p-6 shadow dark:bg-gray-800">
            <div class="flex items-center gap-4">
                <x-avatar-contacto :contacto="$contacto" size="xl" class="ring-4 ring-white shadow-lg dark:ring-gray-700" />
                <div>
                    <p class="text-xl font-semibold text-gray-900 dark:text-gray-100">{{ $contacto->nombreCompleto() }}</p>
                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ $contacto->tipoContacto?->nombre }}{{ $contacto->activo ? '' : ' · inactivo' }}</p>
                    <p class="text-sm">
                        @if ($contacto->telefono)<a href="tel:{{ preg_replace('/[^\d+]/', '', $contacto->telefono) }}">{{ $contacto->telefono }}</a>@endif
                        @if ($contacto->telefono && $contacto->email) · @endif
                        @if ($contacto->email)<a href="mailto:{{ $contacto->email }}">{{ $contacto->email }}</a>@endif
                    </p>
                    @if ($contacto->notas)<p class="mt-1 whitespace-pre-line text-xs text-gray-500 dark:text-gray-400">{{ $contacto->notas }}</p>@endif
                </div>
            </div>
            <div class="flex flex-wrap gap-2">
                <x-secondary-button type="button" onclick="window.location='{{ route('contactos.index') }}'">Todos los contactos</x-secondary-button>
                @can('deudores.create')
                    <x-primary-button type="button" x-on:click="$dispatch('load-deuda-modal-form', '{{ route('contactos.deudas.create', $contacto) }}'); $dispatch('open-modal', 'deuda-modal')">Prestar / anotar deuda</x-primary-button>
                    @if ($contacto->pendiente > 0)
                        <x-primary-button type="button" x-on:click="$dispatch('load-pago-modal-form', '{{ route('contactos.pagos.create', $contacto) }}'); $dispatch('open-modal', 'pago-modal')">Registrar pago</x-primary-button>
                    @endif
                @endcan
                @can('contactos.edit')
                    <x-secondary-button type="button" x-on:click="$dispatch('load-contacto-modal-form', '{{ route('contactos.edit', $contacto) }}'); $dispatch('open-modal', 'contacto-modal')">Editar</x-secondary-button>
                @endcan
                @can('contactos.delete')
                    <x-danger-button type="button" x-on:click="deleteItem({{ $contacto->id }}, {{ \Illuminate\Support\Js::from($contacto->nombreCompleto()) }}, '{{ route('contactos.destroy', $contacto) }}')">Eliminar</x-danger-button>
                @endcan
            </div>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ([
                ['Te debe', $dinero($contacto->pendiente), 'de '.$dinero($contacto->prestado).' prestados'],
                ['Le debes', $dinero(collect($ficha['prestamos'])->sum('saldo')), count($ficha['prestamos']).' préstamo(s)'],
                ['Le pagaste en '.$hoy->year, $dinero($ficha['pagado_anio']), 'gastos y pagos con este contacto'],
                ['Te pagó en '.$hoy->year, $dinero($ficha['recibido_anio']), 'ingresos de este contacto'],
            ] as [$label, $valor, $nota])
                <div class="rounded-lg bg-white p-4 shadow dark:bg-gray-800">
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ $label }}</p>
                    <p class="mt-1 text-xl font-semibold text-gray-900 dark:text-gray-100">{{ $valor }}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ $nota }}</p>
                </div>
            @endforeach
        </div>

        @if ($ficha['prestamos'])
            <section class="overflow-hidden rounded-lg bg-white shadow dark:bg-gray-800">
                <h2 class="border-b border-gray-200 px-6 py-4 font-semibold text-gray-900 dark:border-gray-700 dark:text-gray-100">Le debes</h2>
                <ul class="divide-y divide-gray-100 text-sm dark:divide-gray-700">
                    @foreach ($ficha['prestamos'] as $p)
                        <li><a href="{{ route('prestamos.show', [$p['prestamo'], 'desde' => $desde]) }}" class="flex justify-between px-6 py-3 hover:bg-gray-50 dark:hover:bg-gray-700/40"><span class="text-gray-700 dark:text-gray-300">{{ $p['prestamo']->nombre }}</span><span class="font-semibold text-gray-900 dark:text-gray-100">{{ $dinero($p['saldo']) }}</span></a></li>
                    @endforeach
                </ul>
            </section>
        @endif

        @if ($contacto->deudas->isNotEmpty())
            <section class="overflow-hidden rounded-lg bg-white shadow dark:bg-gray-800">
                <h2 class="border-b border-gray-200 px-6 py-4 font-semibold text-gray-900 dark:border-gray-700 dark:text-gray-100">Lo que te debe</h2>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                        <thead class="bg-cabecera text-left text-xs uppercase text-gray-600 dark:bg-gray-700/50 dark:text-gray-400">
                            <tr><th class="px-6 py-3">Fecha</th><th class="px-6 py-3">Descripción</th><th class="px-6 py-3">Salió de</th><th class="px-6 py-3 text-right">Monto</th><th class="px-6 py-3 text-right">Pendiente</th><th class="px-6 py-3">Estado</th><th class="px-6 py-3"></th></tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                            @foreach ($contacto->deudas->sortByDesc('fecha') as $deuda)
                                @php([$etiqueta, $clase] = $estados[$deuda->estado()])
                                <tr class="text-gray-700 dark:text-gray-300">
                                    <td class="whitespace-nowrap px-6 py-3">{{ $deuda->fecha->format('d/m/Y') }}</td>
                                    <td class="px-6 py-3">@include('hogar._por', ['id' => $deuda->user_id])@include('contactos._ir_movimiento', ['registro' => $deuda, 'texto' => $deuda->descripcion])@if ($deuda->comentario)<span class="block text-xs text-gray-400">{{ $deuda->comentario }}</span>@endif</td>
                                    <td class="px-6 py-3">{{ ['efectivo' => 'Efectivo', 'cuenta' => 'Cuenta', 'tarjeta' => 'Tarjeta', 'ninguno' => '—'][$deuda->origen] }}</td>
                                    <td class="whitespace-nowrap px-6 py-3 text-right">{{ $dinero($deuda->monto) }}</td>
                                    <td class="whitespace-nowrap px-6 py-3 text-right font-medium">{{ $dinero($deuda->pendiente()) }}</td>
                                    <td class="px-6 py-3"><span class="rounded-full px-2 py-1 text-xs font-medium {{ $clase }}">{{ $etiqueta }}</span></td>
                                    <td class="px-6 py-3 text-right">
                                        @can('deudores.delete')
                                            @if ($deuda->pagado() <= 0)
                                                <button type="button" class="text-xs font-medium text-red-600 hover:underline dark:text-red-400" x-on:click="deleteItem({{ $deuda->id }}, @js($deuda->descripcion), '{{ route('deudas.destroy', $deuda) }}')">Eliminar</button>
                                            @endif
                                        @endcan
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if ($pagos->isNotEmpty())
                    <h3 class="border-t border-gray-200 px-6 pt-4 text-sm font-semibold text-gray-900 dark:border-gray-700 dark:text-gray-100">Pagos recibidos</h3>
                    <ul class="divide-y divide-gray-100 text-sm dark:divide-gray-700">
                        @foreach ($pagos as $pago)
                            <li class="flex flex-wrap items-center justify-between gap-2 px-6 py-2 text-gray-700 dark:text-gray-300">
                                <span>@include('contactos._ir_movimiento', ['registro' => $pago, 'texto' => $pago->fecha->format('d/m/Y').' · '.$pago->deuda->descripcion.' · '.($pago->destino === 'efectivo' ? 'Efectivo' : ($pago->destino === 'cuenta' ? ($pago->movimientoCuenta?->cuenta?->nombre ?? 'Cuenta') : 'anotado'))])</span>
                                <span class="flex items-center gap-3"><strong>{{ $dinero($pago->monto) }}</strong>
                                    @can('deudores.delete')<button type="button" class="text-xs font-medium text-red-600 hover:underline dark:text-red-400" x-on:click="deleteItem({{ $pago->id }}, @js('el pago del '.$pago->fecha->format('d/m/Y')), '{{ route('deuda-pagos.destroy', $pago) }}')">Eliminar</button>@endcan
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        @endif

        <div class="grid gap-6 lg:grid-cols-2">
            <section class="overflow-hidden rounded-lg bg-white shadow dark:bg-gray-800">
                <h2 class="border-b border-gray-200 px-6 py-4 font-semibold text-gray-900 dark:border-gray-700 dark:text-gray-100">Compromisos</h2>
                <ul class="divide-y divide-gray-100 text-sm dark:divide-gray-700">
                    @forelse ($ficha['compromisos'] as $c)
                        <li><a href="{{ route('compromisos.show', [$c, 'desde' => $desde]) }}" class="flex justify-between gap-3 px-6 py-2 hover:bg-gray-50 dark:hover:bg-gray-700/40">
                            <span class="truncate text-gray-700 dark:text-gray-300">{{ $c->descripcion }}</span>
                            <span class="shrink-0 text-right"><strong class="text-gray-900 dark:text-gray-100">{{ $dinero($c->estado === 'pendiente' ? $c->saldo_pendiente : $c->monto) }}</strong>
                                <span class="ml-2 text-xs text-gray-500">{{ $c->estado === 'pendiente' ? 'pendiente' : 'pagado' }}{{ $c->fecha_esperada ? ' · '.$c->fecha_esperada->format('d/m') : '' }}</span></span>
                        </a></li>
                    @empty
                        <li class="px-6 py-6 text-center text-gray-500 dark:text-gray-400">Sin compromisos con este contacto.</li>
                    @endforelse
                </ul>
            </section>
            <section class="overflow-hidden rounded-lg bg-white shadow dark:bg-gray-800">
                <h2 class="border-b border-gray-200 px-6 py-4 font-semibold text-gray-900 dark:border-gray-700 dark:text-gray-100">Movimientos</h2>
                <ul class="divide-y divide-gray-100 text-sm dark:divide-gray-700">
                    @forelse ($ficha['movimientos'] as $m)
                        <li><a href="{{ route('movimientos.ver', [$m['clase'], $m['id'], 'desde' => $desde]) }}" class="flex justify-between gap-3 px-6 py-2 hover:bg-gray-50 dark:hover:bg-gray-700/40">
                            <span class="min-w-0 truncate text-gray-700 dark:text-gray-300">@include('hogar._por', ['id' => $m['user_id']]){{ $m['fecha']->format('d/m/Y') }} · {{ $m['descripcion'] }} <span class="text-xs text-gray-400">({{ $m['donde'] }})</span></span>
                            <span class="shrink-0 font-medium {{ $m['tipo'] === 'ingreso' ? 'text-green-700 dark:text-green-400' : 'text-gray-900 dark:text-gray-100' }}">{{ $m['tipo'] === 'ingreso' ? '+' : '−' }}{{ $dinero($m['monto']) }}</span>
                        </a></li>
                    @empty
                        <li class="px-6 py-6 text-center text-gray-500 dark:text-gray-400">Sin movimientos con este contacto.</li>
                    @endforelse
                </ul>
            </section>
        </div>
    </div>
    <x-ajax-modal name="contacto" />
    <x-ajax-modal name="deuda" />
    <x-ajax-modal name="pago" />
</x-app-layout>
