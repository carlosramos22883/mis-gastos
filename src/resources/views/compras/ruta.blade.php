@php($dinero = fn ($v) => '$ '.number_format((float) $v, 2))
@php($plan = $ruta['plan'])
<x-app-layout>
    <x-slot name="header"><h1>Ruta de compra</h1></x-slot>
    <div class="max-w-4xl mx-auto space-y-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <a href="{{ route('compras.show', $lista) }}" class="text-sm font-medium text-primary-600 hover:underline dark:text-primary-400">← {{ $lista->nombre }}</a>
        </div>

        {{-- Lo que cuesta ir a una tienda más (gasolina, tiempo, parqueo). Se recuerda en este navegador. --}}
        <form method="GET" action="{{ route('compras.ruta', $lista) }}" class="flex flex-wrap items-end gap-3 rounded-xl bg-white p-4 shadow dark:bg-gray-800"
            x-data x-init="(() => { try { const guardado = localStorage.getItem('ruta-costo'); if (guardado !== null && !new URLSearchParams(location.search).has('costo') && guardado !== '{{ number_format($ruta['costo_visita'], 2, '.', '') }}') { location.replace('?costo=' + encodeURIComponent(guardado)); } } catch {} })()"
            x-on:submit="(() => { try { localStorage.setItem('ruta-costo', $el.costo.value) } catch {} })()">
            <div class="w-56">
                <label for="costo" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">Ir a otra tienda me cuesta</label>
                <div class="flex h-[42px] items-center overflow-hidden rounded-lg border border-gray-300 bg-white dark:border-gray-600 dark:bg-gray-700">
                    <span class="pl-3 text-sm text-gray-500 dark:text-gray-300">$</span>
                    <input id="costo" name="costo" type="text" inputmode="decimal" maxlength="6" value="{{ number_format($ruta['costo_visita'], 2, '.', '') }}"
                        class="h-full w-full border-0 bg-transparent px-2 text-sm text-gray-900 focus:ring-0 dark:text-white">
                </div>
            </div>
            <x-secondary-button type="submit">Recalcular</x-secondary-button>
            <p class="w-full text-xs text-gray-500 dark:text-gray-400">Gasolina, tiempo, parqueo… Solo conviene ir a una tienda más si lo que te ahorras ahí pasa de esto.</p>
        </form>

        @if (! $plan)
            <div class="rounded-xl bg-white p-10 text-center shadow dark:bg-gray-800">
                <p class="text-gray-600 dark:text-gray-300">Aún no hay precios de lo que falta comprar.</p>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Consulta los precios en línea desde la lista o anótalos en Despensa → Precios.</p>
            </div>
        @else
            <div data-resumen class="rounded-xl bg-primary-50 p-4 text-primary-900 dark:bg-gray-800 dark:text-gray-100">
                @if (count($plan['tiendas']) === 1)
                    <p class="text-lg font-semibold">Compra todo en {{ $plan['tiendas'][0]['tienda']->nombre }}: {{ $dinero($plan['total']) }}</p>
                    <p class="text-sm">Con los precios que hay, ir a otra tienda no te ahorra más de lo que cuesta ir.</p>
                @else
                    <p class="text-lg font-semibold">Ve a {{ count($plan['tiendas']) }} tiendas: {{ $dinero($plan['total']) }}</p>
                    @if ($ruta['ahorro'] === null)
                        <p class="text-sm">Ninguna tienda tiene precio de todo: así cubres lo más que se puede.</p>
                    @else
                        <p class="text-sm">Todo en {{ $ruta['una_tienda']['tienda']->nombre }} saldría en {{ $dinero($ruta['una_tienda']['total']) }}: te ahorras <strong>{{ $dinero($ruta['ahorro']) }}</strong>{{ $plan['costo_ir'] > 0 ? ', y '.$dinero($ruta['ahorro'] - $plan['costo_ir']).' ya contando lo que cuesta ir ('.$dinero($plan['costo_ir']).')' : '' }}.</p>
                    @endif
                @endif
                @if ($ruta['viejos'])
                    <p class="mt-1 text-xs text-amber-800 dark:text-amber-300">{{ $ruta['viejos'] === 1 ? '1 precio tiene' : $ruta['viejos'].' precios tienen' }} más de {{ \App\Services\RutaCompraService::DIAS_VIEJO }} días: puede haber cambiado.</p>
                @endif
            </div>

            @foreach ($plan['tiendas'] as $parada)
                <div>
                    <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                        <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $parada['tienda']->nombre }} · {{ $dinero($parada['subtotal']) }}</h2>
                        @can('despensa.edit')
                            <button type="button" class="text-sm font-medium text-primary-600 hover:underline dark:text-primary-400"
                                x-data x-on:click="enviarDatos('{{ route('compras.tienda', $lista) }}', 'PATCH', { tienda_id: {{ $parada['tienda']->id }} }).then(({ ok }) => ok && (window.location = '{{ route('compras.show', $lista) }}'))">Estoy aquí →</button>
                        @endcan
                    </div>
                    <ul class="divide-y divide-gray-100 overflow-hidden rounded-xl bg-white shadow dark:divide-gray-700 dark:bg-gray-800">
                        @foreach ($parada['items'] as $l)
                            <li class="flex items-center justify-between gap-3 px-4 py-3 text-sm">
                                <div class="min-w-0">
                                    <p class="text-gray-900 dark:text-gray-100">{{ $l['item']->descripcion }} <span class="text-gray-500 dark:text-gray-400">× {{ $l['item']->cantidad }}</span></p>
                                    <p class="text-xs {{ $l['viejo'] ? 'text-amber-700 dark:text-amber-300' : 'text-gray-500 dark:text-gray-400' }}">{{ $dinero($l['por_unidad']) }} c/u · {{ $l['cuando'] }}</p>
                                </div>
                                <span class="shrink-0 font-semibold text-gray-900 dark:text-gray-100">{{ $dinero($l['subtotal']) }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        @endif

        @php($aparte = collect($plan['sin_cubrir'] ?? [])->merge($ruta['sin_precio']))
        @if ($aparte->isNotEmpty())
            <div>
                <h2 class="mb-2 text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Sin precio</h2>
                <ul class="divide-y divide-gray-100 overflow-hidden rounded-xl bg-white shadow dark:divide-gray-700 dark:bg-gray-800">
                    @foreach ($aparte as $item)
                        <li class="px-4 py-3 text-sm text-gray-700 dark:text-gray-200">{{ $item->descripcion }} <span class="text-gray-500 dark:text-gray-400">× {{ $item->cantidad }}</span></li>
                    @endforeach
                </ul>
                <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">Cómpralas donde te quede mejor: aún no hay precio de estas en tus tiendas.</p>
            </div>
        @endif
    </div>
</x-app-layout>
