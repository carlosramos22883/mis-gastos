@php($editable = $lista->abierta() && auth()->user()->can('despensa.edit'))
@php($total = $partes['pendientes']->count() + $partes['comprados']->count())
<x-app-layout>
    <x-slot name="header"><h1>{{ $lista->nombre }}</h1></x-slot>
    <div class="max-w-4xl mx-auto space-y-6" x-data="{ resumen: {{ \Illuminate\Support\Js::from($resumen) }} }"
        x-on:compra-resumen.window="resumen = $event.detail" x-on:refresh-table.window="$event.detail?.redirect ? window.location = $event.detail.redirect : window.location.reload()">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <a href="{{ route('compras.index') }}" class="text-sm font-medium text-primary-600 hover:underline dark:text-primary-400">← Listas de compras</a>
            @if ($lista->abierta())
                <p class="text-sm text-gray-600 dark:text-gray-300"><strong class="text-gray-900 dark:text-gray-100" x-text="resumen.en_carrito"></strong> de <span x-text="resumen.total_items"></span> en el carrito</p>
            @else
                <p class="text-sm text-gray-600 dark:text-gray-300">Terminada el {{ $lista->terminada_at->timezone(auth()->user()->zona_horaria ?: config('app.timezone'))->format('d/m/Y') }}</p>
            @endif
        </div>

        @if ($editable)
            <div class="rounded-xl bg-white p-4 shadow dark:bg-gray-800">
                @if ($tiendas)
                    <div class="flex flex-wrap items-end gap-4">
                        <div class="min-w-48 flex-1">
                            <label for="tienda_actual" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">¿En qué tienda estás?</label>
                            <select id="tienda_actual" class="block h-[42px] w-full rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-900 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                                x-on:change="enviarDatos('{{ route('compras.tienda', $lista) }}', 'PATCH', { tienda_id: $event.target.value }).then(({ ok }) => ok && window.location.reload())">
                                <option value="">Elige la tienda…</option>
                                @foreach ($tiendas as $id => $nombre)
                                    <option value="{{ $id }}" @selected($lista->tienda_actual_id === $id)>{{ $nombre }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="text-right">
                            <p class="text-xs text-gray-500 dark:text-gray-400">En el carrito</p>
                            <p class="text-2xl font-bold text-gray-900 dark:text-gray-100" x-text="'$ ' + Number(resumen.total).toFixed(2)"></p>
                            <p x-show="resumen.sin_precio > 0" x-cloak class="text-xs text-amber-700 dark:text-amber-300" x-text="resumen.sin_precio === 1 ? '1 sin precio' : resumen.sin_precio + ' sin precio'"></p>
                            <p x-show="resumen.pagado > 0" x-cloak class="text-xs font-medium text-green-700 dark:text-green-400" x-text="'Pagado: $ ' + Number(resumen.pagado).toFixed(2)"></p>
                        </div>
                    </div>
                    @if ($lista->tienda_actual_id)
                        <div x-show="resumen.por_pagar > 0" x-cloak class="mt-3 flex flex-wrap items-center justify-between gap-3 border-t border-gray-100 pt-3 dark:border-gray-700">
                            <p class="text-sm text-gray-600 dark:text-gray-300">Por pagar aquí: <strong class="text-gray-900 dark:text-gray-100" x-text="resumen.por_pagar === 1 ? '1 cosa' : resumen.por_pagar + ' cosas'"></strong>
                                · <strong class="text-gray-900 dark:text-gray-100" x-text="'$ ' + Number(resumen.por_pagar_total).toFixed(2)"></strong></p>
                            <x-primary-button type="button" x-on:click="$dispatch('load-pagar-modal-form', '{{ route('compras.pagar', $lista) }}'); $dispatch('open-modal', 'pagar-modal')">Pagar aquí</x-primary-button>
                        </div>
                        <p x-show="resumen.por_pagar === 0" x-cloak class="mt-2 text-right text-xs">
                            <button type="button" class="font-medium text-primary-600 hover:underline dark:text-primary-400" x-on:click="$dispatch('load-pagar-modal-form', '{{ route('compras.pagar', $lista) }}'); $dispatch('open-modal', 'pagar-modal')">Registrar un pago aquí</button>
                        </p>
                    @endif
                    @unless ($lista->tienda_actual_id)
                        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">Al elegirla, lo que eches al carrito queda con esa tienda y con su último precio conocido, y puedes marcar lo que no había.</p>
                    @endunless
                @else
                    <p class="text-sm text-gray-600 dark:text-gray-300">Para anotar precios y lo que no había, agrega tus tiendas en <a href="{{ route('tiendas.index') }}" class="font-medium text-primary-600 hover:underline dark:text-primary-400">Tiendas</a>.</p>
                @endif
            </div>
            @if ($tiendas && $partes['pendientes']->isNotEmpty())
                <div x-data="consultaPrecios({ url: '{{ route('compras.consultas.store', $lista) }}' })" class="flex flex-wrap items-center justify-between gap-3 rounded-xl bg-white px-4 py-3 shadow dark:bg-gray-800">
                    <div class="text-sm">
                        <p class="font-medium text-gray-900 dark:text-gray-100">Precios y ruta</p>
                        @if ($consultas['fuentes'])
                            <p class="text-xs text-gray-500 dark:text-gray-400" x-show="!avance">Consulta en línea Walmart, Don Juan y Selectos, de lo que falta comprar. {{ $consultas['restantes'] === 1 ? 'Te queda 1 consulta hoy.' : 'Te quedan '.$consultas['restantes'].' consultas hoy.' }}</p>
                        @else
                            <p class="text-xs text-gray-500 dark:text-gray-400">En qué tiendas comprar, con los precios que has anotado.</p>
                        @endif
                        <p class="text-xs font-medium text-primary-700 dark:text-primary-300" x-show="avance" x-cloak x-text="avance"></p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        @if ($consultas['fuentes'])
                            <x-secondary-button type="button" x-on:click="iniciar()" x-bind:disabled="corriendo">Consultar precios</x-secondary-button>
                        @endif
                        <a href="{{ route('compras.ruta', $lista) }}" class="inline-flex items-center rounded-full border border-primary-600 px-4 py-2 text-sm font-semibold text-primary-700 hover:bg-primary-50 dark:border-primary-400 dark:text-primary-300 dark:hover:bg-primary-900/30">Ruta de compra</a>
                    </div>
                </div>
            @endif
            <details class="group rounded-xl bg-white shadow dark:bg-gray-800" @if ($total === 0) open @endif>
            <summary class="flex cursor-pointer list-none items-center justify-between px-4 py-3 text-sm font-semibold text-primary-700 dark:text-primary-300">
                Agregar a la lista
                <svg class="h-4 w-4 transition-transform group-open:rotate-180" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" /></svg>
            </summary>
            <div class="grid gap-4 border-t border-gray-100 p-4 md:grid-cols-2 dark:border-gray-700">
                <form x-data="ajaxForm(async function(data) { await showAlert('success', '¡Listo!', data.message); window.location.reload(); })" @submit.prevent="submit"
                    method="POST" action="{{ route('compras.items.store', $lista) }}" class="space-y-3" novalidate>
                    @csrf
                    <p class="text-sm font-medium text-gray-700 dark:text-gray-200">Agregar de tu despensa</p>
                    @if ($opciones)
                        <x-floating-select id="producto_id" label="Producto" :options="$opciones" searchable />
                        <div class="flex gap-3">
                            <div class="w-28"><x-floating-input id="cantidad" type="number" min="1" max="999" inputmode="numeric" label="Cantidad" /></div>
                            <x-primary-button type="submit" x-bind:disabled="loading">Agregar</x-primary-button>
                        </div>
                    @else
                        <p class="text-sm text-gray-500 dark:text-gray-400">Todos tus productos ya están en la lista.</p>
                    @endif
                </form>
                <form x-data="ajaxForm(async function(data) { await showAlert('success', '¡Listo!', data.message); window.location.reload(); })" @submit.prevent="submit"
                    method="POST" action="{{ route('compras.items.store', $lista) }}" class="space-y-3" novalidate>
                    @csrf
                    <p class="text-sm font-medium text-gray-700 dark:text-gray-200">Anotar algo que no está en tu despensa</p>
                    <x-floating-input id="descripcion" label="Ej. Piñata para el cumpleaños" maxlength="200" />
                    <div class="flex gap-3">
                        <div class="w-28"><x-floating-input id="cantidad_anotada" name="cantidad" type="number" min="1" max="999" inputmode="numeric" label="Cantidad" /></div>
                        <x-primary-button type="submit" x-bind:disabled="loading">Anotar</x-primary-button>
                    </div>
                </form>
            </div>
            </details>
        @endif

        @if ($total === 0)
            <div class="rounded-xl bg-white p-10 text-center shadow dark:bg-gray-800">
                <p class="text-gray-600 dark:text-gray-300">La lista está vacía.</p>
                @if ($editable)<p class="mt-1 text-sm text-gray-500 dark:text-gray-400">No falta nada en tu despensa. Agrega arriba lo que quieras comprar.</p>@endif
            </div>
        @else
            @if ($partes['pendientes']->isNotEmpty())
                <div>
                    <h2 class="mb-2 text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $lista->abierta() ? 'Por comprar' : 'No se compró' }}</h2>
                    <ul class="divide-y divide-gray-100 overflow-hidden rounded-xl bg-white shadow dark:divide-gray-700 dark:bg-gray-800">
                        @foreach ($partes['pendientes'] as $item)
                            @include('compras._item')
                        @endforeach
                    </ul>
                </div>
            @endif
            @if ($partes['comprados']->isNotEmpty())
                <div>
                    <h2 class="mb-2 text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $lista->abierta() ? 'En el carrito' : 'Comprado' }}</h2>
                    <ul class="divide-y divide-gray-100 overflow-hidden rounded-xl bg-white shadow dark:divide-gray-700 dark:bg-gray-800">
                        @foreach ($partes['comprados'] as $item)
                            @include('compras._item')
                        @endforeach
                    </ul>
                </div>
            @endif
        @endif

        @if ($pagos->isNotEmpty())
            <div>
                <h2 class="mb-2 text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Pagos</h2>
                <ul class="divide-y divide-gray-100 overflow-hidden rounded-xl bg-white shadow dark:divide-gray-700 dark:bg-gray-800">
                    @foreach ($pagos as $pago)
                        <li class="flex flex-wrap items-start justify-between gap-3 px-4 py-3 text-sm">
                            <div class="min-w-0">
                                <p class="font-semibold text-gray-900 dark:text-gray-100">$ {{ number_format((float) $pago->total, 2) }} en {{ $pago->tienda?->nombre ?? 'una tienda' }}</p>
                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ $pago->fecha->format('d/m/Y') }} · {{ app(\App\Services\CompraPagoService::class)->nombreOrigen($pago) }}{{ $pago->user ? ' · '.$pago->user->name : '' }}</p>
                                <p class="mt-1 text-xs text-gray-600 dark:text-gray-300">{{ $pago->movimientos->map(fn ($m) => ($m->categoria?->nombre ?? 'Sin categoría').' $ '.number_format((float) $m->monto, 2))->implode(' · ') }}</p>
                            </div>
                            @if ($editable)
                                <button type="button" class="text-xs font-medium text-red-600 hover:underline dark:text-red-400"
                                    x-on:click="accionLista('{{ route('compras.pagos.destroy', [$lista, $pago]) }}', { metodo: 'DELETE', titulo: '¿Deshacer este pago?', confirmar: 'Se eliminan sus gastos y lo comprado vuelve al carrito sin pagar. Los extras se quitan de la lista.' })">Deshacer</button>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap gap-3">
                @if ($editable)
                    <x-secondary-button type="button" x-on:click="accionLista('{{ route('compras.faltantes', $lista) }}')">Agregar lo que falta</x-secondary-button>
                    <x-primary-button type="button" x-on:click="accionLista('{{ route('compras.terminar', $lista) }}', { titulo: '¿Terminar la compra?', confirmar: (resumen.por_pagar > 0 ? 'Aún no has pagado ' + (resumen.por_pagar === 1 ? '1 cosa' : resumen.por_pagar + ' cosas') + ' del carrito: si terminas, no se registrará su gasto. ' : '') + 'Lo que está en el carrito se sumará a tu despensa. Lo demás queda en la lista como no comprado.' })">Terminar compra</x-primary-button>
                @endif
            </div>
            @can('despensa.delete')
                <button type="button" class="text-sm font-medium text-red-600 hover:underline dark:text-red-400" x-on:click="deleteItem({{ $lista->id }}, {{ \Illuminate\Support\Js::from($lista->nombre) }}, '{{ route('compras.destroy', $lista) }}')">Eliminar lista</button>
            @endcan
        </div>
    </div>
    <x-ajax-modal name="pagar" />
</x-app-layout>
