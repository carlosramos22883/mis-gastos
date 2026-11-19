{{-- «Pagar aquí»: lo del carrito en la tienda donde se está, más los extras. $lista, $sugerido, $origenes, $categorias, $categoria --}}
@php($simbolo = auth()->user()->monedaPreferida?->simbolo ?? '$')
@php($tienda = $lista->tiendaActual)
@php($hayItems = $sugerido['items']->isNotEmpty())
<form x-data="ajaxForm(async function(data) { $dispatch('close-modal', 'pagar-modal'); await showAlert('success', '¡Pagado!', data.message); window.location.reload(); })"
    @submit.prevent="submit" method="POST" action="{{ route('compras.pagar.store', $lista) }}" class="space-y-4" novalidate>
    @csrf
    <div x-data="{
        modo: 'ninguno',
        lista: {{ \Illuminate\Support\Js::from($hayItems ? number_format($sugerido['total'], 2, '.', '') : '0') }},
        extrasTotal: '',
        filas: [{ descripcion: '', precio: '', categoria: '', nueva: '' }],
        num(v) { return Number(String(v ?? '').replace(/[^0-9.]/g, '')) || 0; },
        categoriaDe(f) { return f.categoria === '__nueva' ? (f.nueva.trim() ? 'nueva:' + f.nueva.trim() : '') : f.categoria; },
        get extras() {
            if (this.modo === 'total') return this.num(this.extrasTotal);
            if (this.modo === 'detalle') return this.filas.reduce((t, f) => t + this.num(f.precio), 0);
            return 0;
        },
        get total() { return ({{ $hayItems ? 'true' : 'false' }} ? this.num(this.lista) : 0) + this.extras; },
    }" class="space-y-4">
        <div class="flex items-center justify-between">
            <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">Pagar en {{ $tienda->nombre }}</h2>
            <button type="button" x-on:click="$dispatch('close-modal', 'pagar-modal')" class="text-gray-400 hover:text-gray-500" aria-label="Cerrar">
                <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
            </button>
        </div>

        @if ($hayItems)
            <div class="rounded-lg bg-gray-50 p-3 text-sm dark:bg-gray-700/50">
                <ul class="max-h-40 space-y-1 overflow-y-auto">
                    @foreach ($sugerido['items'] as $item)
                        <li class="flex justify-between gap-3">
                            <span class="min-w-0 truncate text-gray-700 dark:text-gray-200">{{ $item->descripcion }} <span class="text-gray-500 dark:text-gray-400">× {{ $item->cantidad }}</span></span>
                            <span class="shrink-0 {{ $item->precio === null ? 'text-amber-700 dark:text-amber-300' : 'text-gray-900 dark:text-gray-100' }}">{{ $item->precio === null ? 'sin precio' : $simbolo.' '.number_format($item->subtotal(), 2) }}</span>
                        </li>
                    @endforeach
                </ul>
                <p class="mt-2 flex justify-between border-t border-gray-200 pt-2 font-semibold text-gray-900 dark:border-gray-600 dark:text-gray-100">
                    <span>Suma de lo anotado</span><span>{{ $simbolo }} {{ number_format($sugerido['total'], 2) }}</span>
                </p>
                @if ($sugerido['sin_precio'])
                    <p class="mt-1 text-xs text-amber-700 dark:text-amber-300">{{ $sugerido['sin_precio'] === 1 ? '1 cosa no tiene precio' : $sugerido['sin_precio'].' cosas no tienen precio' }}: escribe abajo el total del ticket.</p>
                @endif
            </div>
            <x-floating-money id="monto_lista" label="Total de lo de la lista" :symbol="$simbolo" :value="number_format($sugerido['total'], 2, '.', '')" x-on:input="lista = $event.target.value" required />
            <p class="-mt-2 text-xs text-gray-500 dark:text-gray-400">Si el ticket dice otra cosa, escribe lo del ticket.</p>
            <x-floating-select id="categoria" label="Categoría de lo de la lista" :options="$categorias" :value="$categoria" searchable creatable required />
        @else
            <p class="rounded-lg bg-gray-50 px-4 py-3 text-sm text-gray-600 dark:bg-gray-700/50 dark:text-gray-300">No hay nada del carrito por pagar en {{ $tienda->nombre }}. Puedes registrar solo los extras.</p>
        @endif

        {{-- Lo que se compró y no estaba en la lista --}}
        <fieldset class="min-w-0 space-y-3">
            <legend class="text-sm font-medium text-gray-700 dark:text-gray-200">¿Compraste algo que no estaba en la lista?</legend>
            <input type="hidden" name="extras_modo" :value="modo">
            <div class="inline-flex flex-wrap rounded-lg bg-gray-100 p-1 text-sm dark:bg-gray-700">
                @foreach (['ninguno' => 'No', 'total' => 'Sí, un solo total', 'detalle' => 'Sí, cada cosa'] as $valor => $texto)
                    <button type="button" class="rounded-md px-3 py-1.5" x-on:click="modo = '{{ $valor }}'"
                        :class="modo === '{{ $valor }}' ? 'bg-white font-semibold text-gray-900 shadow dark:bg-gray-800 dark:text-gray-100' : 'text-gray-600 dark:text-gray-300'">{{ $texto }}</button>
                @endforeach
            </div>
            <div x-show="modo === 'total'" x-cloak class="grid gap-3 sm:grid-cols-2">
                <x-floating-money id="extras_total" label="Total de lo extra" :symbol="$simbolo" x-on:input="extrasTotal = $event.target.value" />
                <x-floating-select id="extras_categoria" label="Categoría de lo extra" :options="$categorias" searchable creatable />
            </div>
            <div x-show="modo === 'detalle'" x-cloak class="space-y-3">
                <template x-for="(f, i) in filas" :key="i">
                    <div class="space-y-2 rounded-lg border border-gray-200 p-3 dark:border-gray-600">
                        <div class="flex gap-2">
                            <input type="text" maxlength="200" placeholder="¿Qué fue? Ej. Jalea" x-model="f.descripcion" :name="`extras[${i}][descripcion]`"
                                class="h-[42px] min-w-0 flex-1 rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-900 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white">
                            <input type="text" inputmode="decimal" maxlength="10" placeholder="{{ $simbolo }} 0.00" x-model="f.precio" :name="`extras[${i}][precio]`" aria-label="Precio"
                                class="h-[42px] w-28 rounded-lg border border-gray-300 bg-white px-3 text-right text-sm text-gray-900 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white">
                        </div>
                        <div class="flex gap-2">
                            <select x-model="f.categoria" aria-label="Categoría"
                                class="h-[42px] min-w-0 flex-1 rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-900 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white">
                                <option value="">Categoría…</option>
                                @foreach ($categorias as $id => $nombre)
                                    <option value="{{ $id }}">{{ $nombre }}</option>
                                @endforeach
                                <option value="__nueva">+ Nueva categoría…</option>
                            </select>
                            <input type="text" x-show="f.categoria === '__nueva'" maxlength="100" placeholder="Nombre, ej. Ropa" x-model="f.nueva" aria-label="Nueva categoría"
                                class="h-[42px] min-w-0 flex-1 rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-900 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white">
                            <input type="hidden" :name="`extras[${i}][categoria]`" :value="categoriaDe(f)">
                            <button type="button" x-show="filas.length > 1" x-on:click="filas.splice(i, 1)" class="px-2 text-sm font-medium text-red-600 hover:underline dark:text-red-400" aria-label="Quitar">Quitar</button>
                        </div>
                    </div>
                </template>
                <button type="button" x-on:click="filas.push({ descripcion: '', precio: '', categoria: '', nueva: '' })" class="text-sm font-medium text-primary-600 hover:underline dark:text-primary-400">+ Otra cosa</button>
            </div>
        </fieldset>

        <p class="flex items-baseline justify-between rounded-lg bg-primary-50 px-4 py-3 text-primary-900 dark:bg-gray-700 dark:text-primary-200">
            <span class="text-sm font-medium">Total a pagar</span>
            <span class="text-2xl font-bold" x-text="'{{ $simbolo }} ' + total.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })"></span>
        </p>

        <x-floating-select id="origen" label="¿Con qué pagas?" :allowEmpty="false" :options="$origenes" :value="array_key_first($origenes)" required />
        <x-floating-date id="fecha" label="Fecha" :value="now(auth()->user()->zona_horaria)" :min="$cycleStart" :max="min(now(auth()->user()->zona_horaria), $cycleEnd)" required />
        @include('hogar._quien', ['label' => '¿Quién paga?'])
        <p class="text-xs text-gray-500 dark:text-gray-400">Se registra un gasto por cada categoría, con las reglas de siempre (saldo, disponible y presupuesto). Lo pagado queda ligado a esta lista.</p>

        <div class="flex justify-end gap-3">
            <x-secondary-button type="button" x-on:click="$dispatch('close-modal', 'pagar-modal')">Cancelar</x-secondary-button>
            <x-primary-button type="submit" x-bind:disabled="loading || total <= 0">Pagar</x-primary-button>
        </div>
    </div>
</form>
