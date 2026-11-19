{{-- Precios de un producto (se abre en la ventana "producto" de la despensa) --}}
@php($dinero = fn ($v) => '$ '.number_format((float) $v, 2))
@php($hace = fn ($fecha) => match ($d = (int) $fecha->copy()->startOfDay()->diffInDays($hoy, false)) { 0 => 'hoy', 1 => 'ayer', default => "hace {$d} días" })
@php($url = route('productos.precios', $producto))
<div class="space-y-5">
    <div class="flex items-start justify-between gap-3">
        <div>
            <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">Precios</h2>
            <p class="text-sm text-gray-600 dark:text-gray-300">{{ $producto->nombreCompleto() }}</p>
        </div>
        <button type="button" x-on:click="$dispatch('close-modal', 'producto-modal')" class="text-gray-400 hover:text-gray-500" aria-label="Cerrar">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
        </button>
    </div>

    @if ($ultimos->isEmpty())
        <p class="rounded-lg bg-gray-50 px-4 py-3 text-sm text-gray-600 dark:bg-gray-700/50 dark:text-gray-300">Aún no hay precios. Anótalo cuando lo veas en la tienda, o se guardará solo al comprarlo con la lista.</p>
    @else
        <ul class="divide-y divide-gray-100 rounded-lg border border-gray-100 dark:divide-gray-700 dark:border-gray-700">
            @foreach ($ultimos as $i => $p)
                <li class="flex items-center justify-between gap-3 px-4 py-3 {{ $i === 0 && $ultimos->count() > 1 ? 'bg-green-50 dark:bg-green-900/20' : '' }}">
                    <div>
                        <p class="font-medium text-gray-900 dark:text-gray-100">{{ $p->tienda->nombre }}@if ($i === 0 && $ultimos->count() > 1)<span class="ml-2 rounded-full bg-green-600 px-2 py-0.5 text-xs font-semibold text-white">Más barato</span>@endif</p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ \App\Models\Precio::ORIGENES[$p->origen] }} {{ $hace($p->fecha) }} ({{ $p->fecha->format('d/m/Y') }})</p>
                    </div>
                    <div class="text-right">
                        <p class="font-semibold text-gray-900 dark:text-gray-100">{{ $dinero($p->precio) }}{{ $p->unidades > 1 ? ' × '.$p->unidades : '' }}</p>
                        @if ($p->unidades > 1)<p class="text-xs text-gray-500 dark:text-gray-400">{{ $dinero($p->porUnidad()) }} c/u</p>@endif
                    </div>
                </li>
            @endforeach
        </ul>
    @endif

    @can('despensa.edit')
        @if ($fuentes)
            {{-- Ligar con su producto en cada tienda en línea (una vez): así «Consultar precios» de la lista lo encuentra --}}
            <div class="space-y-2">
                <p class="text-sm font-medium text-gray-700 dark:text-gray-200">En línea</p>
                @foreach ($fuentes as $fuente)
                    @php($enlace = $enlaces[$fuente] ?? null)
                    @php($nombreFuente = preg_replace('/ \(.*$/', '', \App\Models\Tienda::FUENTES[$fuente]))
                    <div x-data="enlaceEnLinea({ buscarUrl: '{{ route('productos.en-linea.buscar', [$producto, $fuente]) }}', guardarUrl: '{{ route('productos.en-linea.store', [$producto, $fuente]) }}', recargar: '{{ $url }}', texto: {{ \Illuminate\Support\Js::from($producto->nombreCompleto()) }} })"
                        class="rounded-lg border border-gray-200 p-3 text-sm dark:border-gray-600">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div class="min-w-0">
                                <p class="font-medium text-gray-900 dark:text-gray-100">{{ $nombreFuente }}</p>
                                @if ($enlace)
                                    <p class="truncate text-xs text-gray-600 dark:text-gray-300">
                                        @if ($enlace->url)<a href="{{ $enlace->url }}" target="_blank" rel="noopener noreferrer" class="text-primary-600 hover:underline dark:text-primary-400">{{ $enlace->nombre }}</a>@else{{ $enlace->nombre }}@endif{{ $enlace->unidades > 1 ? ' · paquete de '.$enlace->unidades : '' }}
                                    </p>
                                    @if ($enlace->error)
                                        <p class="text-xs text-amber-700 dark:text-amber-300">{{ $enlace->error === 'no_encontrado' ? 'En la última consulta la tienda ya no lo tenía: búscalo otra vez.' : 'En la última consulta no mostraba precio.' }}</p>
                                    @endif
                                @else
                                    <p class="text-xs text-gray-500 dark:text-gray-400">Sin ligar</p>
                                @endif
                            </div>
                            <div class="flex gap-3 text-xs font-medium">
                                <button type="button" class="text-primary-600 hover:underline disabled:opacity-50 dark:text-primary-400" :disabled="buscando" x-on:click="buscar()">{{ $enlace ? 'Cambiar' : 'Buscar en '.$nombreFuente }}</button>
                                @if ($enlace)
                                    <button type="button" class="text-red-600 hover:underline dark:text-red-400"
                                        x-on:click="showConfirm('¿Quitar la liga?', 'Ya no se consultará su precio en {{ $nombreFuente }}. Los precios ya leídos se quedan en el historial.', async () => { const { ok } = await enviarDatos('{{ route('productos.en-linea.destroy', [$producto, $fuente]) }}', 'DELETE'); if (ok) $dispatch('load-producto-modal-form', '{{ $url }}'); })">Quitar</button>
                                @endif
                            </div>
                        </div>
                        <div x-show="abierto" x-cloak class="mt-3 space-y-2 border-t border-gray-100 pt-3 dark:border-gray-700">
                            <form class="flex gap-2" x-on:submit.prevent="buscar(true)">
                                <input type="search" x-model="texto" maxlength="100" aria-label="Buscar en {{ $nombreFuente }}"
                                    class="h-9 min-w-0 flex-1 rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-900 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white">
                                <button type="submit" class="rounded-lg bg-gray-100 px-3 text-sm font-medium text-gray-700 disabled:opacity-50 dark:bg-gray-700 dark:text-gray-200" :disabled="buscando">Buscar</button>
                            </form>
                            <p x-show="buscando" class="text-xs text-gray-500 dark:text-gray-400">Buscando en {{ $nombreFuente }}…</p>
                            <p x-show="error" x-text="error" class="text-xs text-red-600 dark:text-red-400"></p>
                            <p x-show="resultados && resultados.length === 0 && !buscando" class="text-xs text-gray-500 dark:text-gray-400">No salió nada. Prueba con menos palabras (ej. solo la marca y el producto).</p>
                            <label x-show="resultados && resultados.length" class="flex items-center gap-2 text-xs text-gray-600 dark:text-gray-300">Allá se vende en paquete de
                                <input type="number" min="1" max="999" x-model.number="unidades" class="h-8 w-16 rounded-md border border-gray-300 px-2 text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-white"> (1 = suelto)</label>
                            <ul class="max-h-72 divide-y divide-gray-100 overflow-y-auto dark:divide-gray-700">
                                <template x-for="r in (resultados || [])" :key="r.codigo">
                                    <li class="flex items-center gap-3 py-2">
                                        <template x-if="r.imagen"><img :src="r.imagen" alt="" loading="lazy" referrerpolicy="no-referrer" class="h-10 w-10 shrink-0 rounded bg-white object-contain"></template>
                                        <div class="min-w-0 flex-1">
                                            <p class="text-sm leading-snug text-gray-900 dark:text-gray-100" x-text="r.nombre"></p>
                                            <p class="text-xs text-gray-500 dark:text-gray-400"><span x-text="dinero(r.precio)"></span><span x-show="r.mismo_codigo" class="ml-2 rounded-full bg-green-600 px-2 py-0.5 font-semibold text-white">Mismo código de barras</span></p>
                                        </div>
                                        <button type="button" class="shrink-0 rounded-full border border-primary-600 px-3 py-1 text-xs font-semibold text-primary-700 hover:bg-primary-50 dark:border-primary-400 dark:text-primary-300" x-on:click="elegir(r)">Es este</button>
                                    </li>
                                </template>
                            </ul>
                        </div>
                    </div>
                @endforeach
                @if (in_array('walmart', $fuentes, true) && in_array('donjuan', $fuentes, true))
                    <p class="text-xs text-gray-500 dark:text-gray-400">Walmart y Don Juan comparten catálogo: al ligar uno se liga también el otro.</p>
                @endif
            </div>
        @endif
    @endcan

    @can('despensa.edit')
        @if ($tiendas)
            <form x-data="ajaxForm(async function(data) { await showAlert('success', '¡Listo!', data.message); $dispatch('load-producto-modal-form', '{{ $url }}'); })"
                @submit.prevent="submit" method="POST" action="{{ route('productos.precios.store', $producto) }}" class="space-y-3 rounded-lg bg-gray-50 p-4 dark:bg-gray-700/40" novalidate>
                @csrf
                <p class="text-sm font-medium text-gray-700 dark:text-gray-200">Anotar un precio que viste</p>
                <div class="grid gap-3 sm:grid-cols-2">
                    <x-floating-select id="tienda_id" label="Tienda" :options="$tiendas" :value="array_key_first($tiendas)" :allowEmpty="false" required />
                    <x-floating-date id="fecha" label="Fecha" :value="$hoy->toDateString()" :max="$hoy->copy()->addDay()" />
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <x-floating-input id="precio" type="text" inputmode="decimal" label="Precio ($)" maxlength="10" required />
                    <x-floating-input id="unidades" type="number" min="1" max="999" inputmode="numeric" label="Paquete de (unidades, opcional)" />
                </div>
                <p class="text-xs text-gray-500 dark:text-gray-400">Si la tienda lo vende en paquete (ej. 3 latas por $5.25), escribe el precio del paquete y cuántas trae: se compara por unidad.</p>
                <div class="flex justify-end"><x-primary-button type="submit" x-bind:disabled="loading">Anotar precio</x-primary-button></div>
            </form>
        @else
            <p class="text-sm text-gray-500 dark:text-gray-400">Para anotar precios, primero agrega tus tiendas en <a href="{{ route('tiendas.index') }}" class="font-medium text-primary-600 hover:underline dark:text-primary-400">Tiendas</a>.</p>
        @endif
    @endcan

    @if ($historial->isNotEmpty())
        <details class="text-sm">
            <summary class="cursor-pointer font-medium text-gray-700 dark:text-gray-200">Historial ({{ $historial->count() }})</summary>
            <ul class="mt-2 divide-y divide-gray-100 dark:divide-gray-700">
                @foreach ($historial as $p)
                    <li class="flex items-center justify-between gap-3 py-2">
                        <span class="text-gray-600 dark:text-gray-300">{{ $p->fecha->format('d/m/Y') }} · {{ $p->tienda->nombre }} · {{ \App\Models\Precio::ORIGENES[$p->origen] }}</span>
                        <span class="flex items-center gap-3">
                            <strong class="text-gray-900 dark:text-gray-100">{{ $dinero($p->precio) }}{{ $p->unidades > 1 ? ' × '.$p->unidades : '' }}</strong>
                            @if ($p->origen === 'anotado')
                                @can('despensa.edit')
                                    <button type="button" class="text-xs font-medium text-red-600 hover:underline dark:text-red-400"
                                        x-on:click="showConfirm('¿Eliminar el precio?', 'Se quitará del historial.', async () => { const { ok } = await enviarDatos('{{ route('productos.precios.destroy', [$producto, $p]) }}', 'DELETE'); if (ok) $dispatch('load-producto-modal-form', '{{ $url }}'); })">Eliminar</button>
                                @endcan
                            @endif
                        </span>
                    </li>
                @endforeach
            </ul>
        </details>
    @endif
</div>
