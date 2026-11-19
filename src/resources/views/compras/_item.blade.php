{{-- Una cosa de la lista. $item, $lista, $editable (lo ya pagado no se cambia: se deshace el pago) --}}
@php($p = $item->producto)
@php($editable = $editable && ! $item->compra_pago_id)
<li x-data="itemCompra({ url: '{{ route('compras.items.update', [$lista, $item]) }}', item: {{ \Illuminate\Support\Js::from(\App\Http\Controllers\ListaCompraController::datosItem($item)) }} })"
    class="px-4 py-3" :class="item.comprado ? 'bg-green-50/60 dark:bg-green-900/10' : (item.no_habia ? 'bg-amber-50/50 dark:bg-amber-900/10' : '')">
    <div class="flex items-center gap-3">
        @if ($editable)
            <button type="button" x-on:click="alCarrito()" :disabled="enviando" :aria-pressed="item.comprado.toString()"
                class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border-2 transition"
                :class="item.comprado ? 'border-green-600 bg-green-600 text-white' : 'border-gray-300 text-transparent hover:border-green-500 dark:border-gray-500'"
                :aria-label="item.comprado ? 'Sacar del carrito' : 'Al carrito'">
                <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
            </button>
        @else
            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full {{ $item->comprado ? 'bg-green-600 text-white' : 'border-2 border-gray-300 text-transparent dark:border-gray-600' }}" title="{{ $item->comprado ? 'Comprado' : ($item->no_habia_tienda_id ? 'No había' : 'No se compró') }}">
                <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
            </span>
        @endif
        @if ($p?->foto)
            <img src="{{ $p->urlFoto() }}" alt="" loading="lazy" class="h-12 w-12 shrink-0 rounded-md object-cover ring-1 ring-black/10">
        @else
            <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-md bg-gray-100 text-gray-400 dark:bg-gray-700">
                <svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 7h12l-1 13H7L6 7zM9 7V5a3 3 0 016 0v2" /></svg>
            </div>
        @endif
        <div class="min-w-0 flex-1">
            <p class="leading-snug text-gray-900 dark:text-gray-100" :class="item.comprado ? 'line-through opacity-60' : ''">{{ $item->descripcion }}</p>
            <p class="text-xs text-gray-500 dark:text-gray-400">
                @if ($p)
                    {{ $p->categoria->nombre }}{{ $item->automatico ? ' · faltaba' : '' }}{{ $p->esBasico() ? ' · básico' : '' }}
                @else
                    {{ $item->extra ? 'Extra' : 'Anotado' }}
                @endif
                @if ($item->compra_pago_id)
                    · <span class="font-medium text-green-700 dark:text-green-400">Pagado</span>
                @endif
            </p>
            <p x-show="item.no_habia" x-cloak class="text-xs font-medium text-amber-800 dark:text-amber-300" x-text="item.no_habia"></p>
            @if ($editable)
                <div class="mt-0.5 flex gap-4 text-xs font-medium">
                    @if ($lista->tienda_actual_id)
                        <button type="button" x-show="!item.comprado" class="text-amber-700 hover:underline dark:text-amber-300" :disabled="enviando" x-on:click="noHabia()" x-text="item.no_habia ? 'Sí había' : 'No había'"></button>
                    @endif
                    <button type="button" class="text-red-600 hover:underline dark:text-red-400" x-on:click="quitar('{{ route('compras.items.destroy', [$lista, $item]) }}', {{ \Illuminate\Support\Js::from($item->descripcion) }})">Quitar</button>
                </div>
            @endif
        </div>
        @if ($editable)
            <div class="flex shrink-0 items-center gap-1.5">
                <button type="button" class="h-8 w-8 rounded-full bg-gray-100 font-semibold text-gray-700 disabled:opacity-40 dark:bg-gray-700 dark:text-gray-200" :disabled="enviando || item.cantidad <= 1" x-on:click="menos()" aria-label="Uno menos">−</button>
                <span class="w-7 text-center font-bold text-gray-900 dark:text-gray-100" x-text="item.cantidad"></span>
                <button type="button" class="h-8 w-8 rounded-full bg-gray-100 font-semibold text-gray-700 disabled:opacity-40 dark:bg-gray-700 dark:text-gray-200" :disabled="enviando" x-on:click="mas()" aria-label="Uno más">+</button>
            </div>
        @else
            <span class="shrink-0 text-right font-semibold text-gray-700 dark:text-gray-200">× {{ $item->cantidad }}
                @if ($item->precio !== null)<span class="block text-xs font-normal text-gray-500 dark:text-gray-400">$ {{ number_format($item->subtotal(), 2) }}{{ $item->tienda ? ' · '.$item->tienda->nombre : '' }}</span>@endif
            </span>
        @endif
    </div>
    @if (! empty($comparacion[$item->id]))
        {{-- Dónde está más barato (por unidad), con la antigüedad de cada precio --}}
        <ul class="mt-2 space-y-0.5 pl-12 text-xs text-gray-600 dark:text-gray-300">
            @foreach ($comparacion[$item->id] as $k => $c)
                <li class="flex items-baseline gap-2 {{ $k === 0 && count($comparacion[$item->id]) > 1 ? 'font-semibold text-green-700 dark:text-green-400' : '' }} {{ $c['viejo'] ? 'opacity-70' : '' }}">
                    <span class="min-w-0 flex-1 truncate">{{ $c['tienda'] }}</span><span class="shrink-0">${{ number_format($c['por_unidad'], 2) }}</span><span class="w-10 shrink-0 text-right font-normal text-gray-400">{{ $c['cuando'] }}</span>
                </li>
            @endforeach
        </ul>
    @endif
    @if ($editable)
        {{-- En el carrito: precio por unidad (sugerido con el último de la tienda) y subtotal --}}
        <div x-show="item.comprado" x-cloak class="mt-2 flex flex-wrap items-center gap-2 pl-12 text-sm">
            <label class="text-gray-600 dark:text-gray-300" for="precio-{{ $item->id }}">Precio c/u $</label>
            <input id="precio-{{ $item->id }}" type="text" inputmode="decimal" maxlength="10" placeholder="0.00" :value="item.precio ?? ''"
                x-on:change="guardarPrecio($event.target.value)" x-on:keydown.enter.prevent="$event.target.blur()"
                class="h-9 w-24 rounded-lg border border-gray-300 bg-white px-2 text-right text-sm text-gray-900 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white">
            <span x-show="subtotal() !== null" class="text-gray-600 dark:text-gray-300" x-text="'= $ ' + subtotal()"></span>
            <span x-show="item.tienda" class="text-xs text-gray-500 dark:text-gray-400" x-text="'en ' + item.tienda"></span>
        </div>
    @endif
</li>
