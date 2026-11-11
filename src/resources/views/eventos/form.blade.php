@php
    $simbolo = auth()->user()->monedaPreferida?->simbolo ?? '$';
    $yo = 'miembro:'.auth()->id();
    // Estado inicial: uid = id del participante (o 1 para "yo" en uno nuevo); en cada producto, "cant" = { uid: cantidad }
    $inicial = $evento
        ? [
            'participantes' => $evento->participantes->map(fn ($p) => ['uid' => $p->id, 'quien' => $p->quien(), 'nuevo' => '', 'es_festejado' => $p->es_festejado])->values(),
            'pagador' => $evento->participantes->firstWhere('es_pagador', true)?->id,
            'productos' => $evento->consumos->map(fn ($c) => ['uid' => -$c->id, 'descripcion' => $c->descripcion, 'precio' => number_format((float) $c->precio, 2, '.', ''),
                'cant' => (object) $c->participantes->mapWithKeys(fn ($p) => [$p->id => (string) $p->pivot->cantidad])->all()])->values(),
            'propina_tipo' => $evento->propina_tipo, 'propina_valor' => $evento->propina_tipo === 'ninguna' ? '' : number_format((float) $evento->propina_valor, 2, '.', ''),
        ]
        : [
            'participantes' => [['uid' => 1, 'quien' => $yo, 'nuevo' => '', 'es_festejado' => false]], 'pagador' => 1,
            'productos' => [['uid' => 2, 'descripcion' => '', 'precio' => '', 'cant' => (object) []]],
            'propina_tipo' => 'porcentaje', 'propina_valor' => '10',
        ];
    $inicial += ['opciones' => $opciones, 'simbolo' => $simbolo];
    $origen = $evento?->movimiento() ? match (true) {
        $evento->movimiento() instanceof \App\Models\MovimientoCuenta => 'cuenta:'.$evento->movimiento()->cuenta_id,
        $evento->movimiento() instanceof \App\Models\MovimientoTarjeta => 'tarjeta:'.$evento->movimiento()->tarjeta_credito_id,
        default => 'efectivo',
    } : ($evento ? 'ninguno' : array_key_first($origenes));
    $campo = 'block w-full rounded-lg border-gray-300 bg-white text-sm text-gray-900 focus:border-primary-500 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100';
    $celda = 'w-16 rounded-md border-gray-300 bg-white px-2 py-1 text-right text-sm text-gray-900 focus:border-primary-500 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100';
@endphp
<x-app-layout>
    <x-slot name="header"><h1>{{ $evento ? 'Editar evento' : 'Nuevo evento' }}</h1></x-slot>
    <div class="max-w-7xl mx-auto" x-data="eventoForm({{ \Illuminate\Support\Js::from($inicial) }})">
        <form x-data="ajaxForm(async function(data) { await showAlert('success', '¡Éxito!', data.message); window.location = data.redirect; })"
            @submit.prevent="submit" method="POST" action="{{ $evento ? route('eventos.update', $evento) : route('eventos.store') }}" enctype="multipart/form-data" class="space-y-6" novalidate>
            @csrf
            @if ($evento) @method('PUT') @endif

            <section class="space-y-4 rounded-lg bg-white p-5 shadow dark:bg-gray-800">
                <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100">¿Qué celebraron?</h2>
                <div class="grid gap-4 sm:grid-cols-3">
                    <div class="sm:col-span-2"><x-floating-input id="nombre" label="Nombre (ej. Cumpleaños de Rocío)" maxlength="150" :value="$evento?->nombre" required /></div>
                    <x-floating-date id="fecha" label="Fecha" :value="$evento?->fecha ?? now(auth()->user()->zona_horaria)" :max="now(auth()->user()->zona_horaria)" required />
                </div>
                <x-floating-input id="lugar" label="Lugar (opcional)" maxlength="150" :value="$evento?->lugar" />
            </section>

            {{-- La cuenta como en la hoja: una fila por persona, una columna por producto (con su precio) y en cada celda cuántos pidió --}}
            <section class="space-y-3 rounded-lg bg-white p-5 shadow dark:bg-gray-800">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100">La cuenta</h2>
                    <div class="flex gap-2">
                        <x-secondary-button type="button" x-on:click="agregarParticipante()">Agregar persona</x-secondary-button>
                        <x-secondary-button type="button" x-on:click="agregarProducto()">Agregar producto</x-secondary-button>
                    </div>
                </div>
                <p class="text-xs text-gray-500 dark:text-gray-400">Agrega a las personas (filas) y los productos con su precio (columnas); en cada celda escribe cuántos pidió esa persona. Un festejado no paga: lo suyo se reparte entre los demás.</p>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm text-gray-700 dark:text-gray-300">
                        <thead>
                            <tr class="align-bottom">
                                <th class="sticky left-0 z-10 min-w-[15rem] bg-white px-2 py-2 text-left text-xs font-semibold uppercase text-gray-500 dark:bg-gray-800">Persona</th>
                                <template x-for="(c, ci) in productos" :key="c.uid">
                                    <th class="px-1 py-2 align-bottom">
                                        <div class="w-28 space-y-1">
                                            <div class="flex justify-end">
                                                <button type="button" class="text-gray-400 hover:text-red-600" title="Quitar producto" x-show="productos.length > 1" x-on:click="quitarProducto(c.uid)">
                                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                                                </button>
                                            </div>
                                            <input type="text" data-producto-nuevo :name="`consumos[${ci}][descripcion]`" x-model="c.descripcion" maxlength="150" placeholder="Producto" class="{{ $campo }} px-2 py-1 text-xs">
                                            <div class="relative">
                                                <span class="pointer-events-none absolute left-2 top-1/2 -translate-y-1/2 text-xs text-gray-500">{{ $simbolo }}</span>
                                                <input type="text" inputmode="decimal" data-money-input :name="`consumos[${ci}][precio]`" x-model="c.precio" placeholder="Precio" class="{{ $campo }} py-1 pl-6 pr-2 text-right text-xs">
                                            </div>
                                        </div>
                                    </th>
                                </template>
                                <th class="px-3 py-2 text-right text-xs font-semibold uppercase text-gray-500">Total</th>
                                <th class="px-3 py-2 text-right text-xs font-semibold uppercase text-gray-500" x-show="hayPropina">Propina</th>
                                <th class="px-3 py-2 text-right text-xs font-semibold uppercase text-gray-500" x-show="hayPropina">Con propina</th>
                                <th class="px-3 py-2 text-right text-xs font-semibold uppercase text-gray-500" x-show="hayFestejados">Cumpleañero</th>
                                <th class="px-3 py-2 text-right text-xs font-semibold uppercase text-gray-500">A pagar</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="(p, i) in participantes" :key="p.uid">
                                <tr class="border-t border-gray-200 dark:border-gray-700" :class="p.es_festejado && 'bg-amber-50 dark:bg-amber-900/10'">
                                    <td class="sticky left-0 z-10 bg-white px-2 py-2 dark:bg-gray-800">
                                        <input type="hidden" :name="`participantes[${i}][quien]`" :value="quien(p)">
                                        <div class="flex items-start gap-1">
                                            <div class="flex-1 space-y-1">
                                                <select x-model="p.quien" class="{{ $campo }} py-1" aria-label="Persona">
                                                    <option value="">Elige a la persona…</option>
                                                    <template x-for="(o, valor) in opciones" :key="valor"><option :value="valor" x-text="o.label" :selected="valor === p.quien"></option></template>
                                                    <option value="__nuevo">+ Nuevo contacto…</option>
                                                </select>
                                                <input x-show="p.quien === '__nuevo'" x-model="p.nuevo" type="text" maxlength="100" placeholder="Nombre del nuevo contacto" class="{{ $campo }} py-1">
                                                <div class="flex gap-3 text-xs text-gray-600 dark:text-gray-400">
                                                    <x-checkbox class="!gap-1 !text-xs" x-bind:name="`participantes[${i}][es_festejado]`" x-model="p.es_festejado">Festejado</x-checkbox>
                                                    <label class="flex items-center gap-1"><input type="radio" name="pagador" :value="i" :checked="pagador === p.uid" x-on:change="pagador = p.uid" class="border-gray-300 text-primary-600 focus:ring-primary-500">Pagó la cuenta</label>
                                                </div>
                                            </div>
                                            <button type="button" class="mt-1 text-gray-400 hover:text-red-600" title="Quitar persona" x-show="participantes.length > 1" x-on:click="quitarParticipante(p.uid)">
                                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                                            </button>
                                        </div>
                                    </td>
                                    <template x-for="(c, ci) in productos" :key="c.uid">
                                        <td class="px-1 py-2 text-center">
                                            <input type="number" min="0" max="999" inputmode="numeric" :name="`consumos[${ci}][cantidades][${i}]`" x-model="c.cant[p.uid]" placeholder="0" class="{{ $celda }}" :aria-label="`Cantidad de ${c.descripcion || 'producto'}`">
                                        </td>
                                    </template>
                                    <td class="px-3 py-2 text-right whitespace-nowrap" x-text="dinero(calculo.por[p.uid]?.consumo ?? 0)"></td>
                                    <td class="px-3 py-2 text-right whitespace-nowrap" x-show="hayPropina" x-text="dinero(calculo.por[p.uid]?.propina ?? 0)"></td>
                                    <td class="px-3 py-2 text-right whitespace-nowrap" x-show="hayPropina" x-text="dinero((calculo.por[p.uid]?.consumo ?? 0) + (calculo.por[p.uid]?.propina ?? 0))"></td>
                                    <td class="px-3 py-2 text-right whitespace-nowrap" x-show="hayFestejados" x-text="p.es_festejado ? '—' : dinero(calculo.por[p.uid]?.festejo ?? 0)"></td>
                                    <td class="px-3 py-2 text-right whitespace-nowrap font-semibold text-gray-900 dark:text-gray-100" x-text="p.es_festejado ? 'Festejado' : dinero(calculo.por[p.uid]?.total ?? 0)"></td>
                                </tr>
                            </template>
                        </tbody>
                        <tfoot class="font-semibold text-gray-900 dark:text-gray-100">
                            <tr class="border-t-2 border-gray-300 dark:border-gray-600">
                                <td class="sticky left-0 z-10 bg-white px-2 py-2 dark:bg-gray-800">Total</td>
                                <template x-for="c in productos" :key="c.uid"><td class="px-1 py-2 text-right text-xs whitespace-nowrap" x-text="dinero(totalProducto(c))"></td></template>
                                <td class="px-3 py-2 text-right whitespace-nowrap" x-text="dinero(calculo.subtotal)"></td>
                                <td class="px-3 py-2 text-right whitespace-nowrap" x-show="hayPropina" x-text="dinero(calculo.propina)"></td>
                                <td class="px-3 py-2 text-right whitespace-nowrap" x-show="hayPropina" x-text="dinero(calculo.total)"></td>
                                <td class="px-3 py-2 text-right whitespace-nowrap" x-show="hayFestejados" x-text="dinero(calculo.festejo)"></td>
                                <td class="px-3 py-2 text-right whitespace-nowrap text-lg" x-text="dinero(calculo.total)"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </section>

            <section class="space-y-4 rounded-lg bg-white p-5 shadow dark:bg-gray-800">
                <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100">Propina</h2>
                <div class="flex flex-wrap items-center gap-3">
                    <select name="propina_tipo" x-model="propinaTipo" class="{{ $campo }} w-56" aria-label="Propina">
                        <option value="ninguna">Sin propina</option>
                        <option value="porcentaje">Porcentaje del total de cada uno</option>
                        <option value="monto">Monto fijo dividido entre todos</option>
                    </select>
                    <div class="relative w-36" x-show="hayPropina">
                        <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-xs text-gray-500" x-text="propinaTipo === 'porcentaje' ? '%' : '{{ $simbolo }}'"></span>
                        <input type="text" inputmode="decimal" data-money-input name="propina_valor" x-model="propinaValor" class="{{ $campo }} pl-7" aria-label="Valor de la propina">
                    </div>
                </div>
            </section>

            {{-- Foto de la cuenta (opcional): va debajo de la tabla en la imagen para compartir --}}
            <section class="space-y-3 rounded-lg bg-white p-5 shadow dark:bg-gray-800" x-data="{ vista: null }">
                <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100">Foto de la cuenta (opcional)</h2>
                <p class="text-xs text-gray-500 dark:text-gray-400">Se comparte junto con la tabla, para que todos vean el total del restaurante.</p>
                <input type="file" name="foto" accept="image/jpeg,image/png" x-on:change="vista = $event.target.files[0] ? URL.createObjectURL($event.target.files[0]) : null"
                    class="block w-full text-sm text-gray-600 file:mr-3 file:rounded-lg file:border-0 file:bg-primary-50 file:px-4 file:py-2 file:text-primary-700 dark:text-gray-300">
                <img x-show="vista" :src="vista" alt="Foto de la cuenta" class="max-h-64 rounded-lg border border-gray-200 dark:border-gray-700">
                @if ($evento?->foto)
                    <div class="flex items-center gap-4" x-show="!vista">
                        <img src="{{ route('eventos.foto', [$evento, 'v' => $evento->versionFoto()]) }}" alt="Foto de la cuenta" class="max-h-40 rounded-lg border border-gray-200 dark:border-gray-700">
                        <x-checkbox name="quitar_foto" peligro>Quitar la foto</x-checkbox>
                    </div>
                @endif
            </section>

            {{-- Si pagó un miembro del hogar: un solo movimiento por el total; lo de cada contacto queda en "Nos deben" --}}
            <section class="space-y-4 rounded-lg bg-white p-5 shadow dark:bg-gray-800" x-show="pagaElHogar">
                <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100">¿Con qué se pagó la cuenta?</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-floating-select id="origen" label="Pagado desde" :allowEmpty="false" :options="$origenes" :value="$origen" />
                    <x-floating-select id="categoria_personal_id" label="Categoría del gasto" :options="$categorias" :value="$evento?->categoria_personal_id" />
                </div>
                <p class="text-xs text-gray-500 dark:text-gray-400">Se registra un solo movimiento por el total de la cuenta. Lo de cada contacto queda en "Nos deben" y, al cobrarle, el dinero entra solo al efectivo o a la cuenta que elijas.</p>
            </section>
            <p class="rounded-lg bg-primary-50 px-4 py-3 text-sm text-primary-700 dark:bg-gray-700 dark:text-primary-300" x-show="!pagaElHogar">
                Pagó otra persona: en el evento podrás marcar quién ya le pagó (y registrar tu parte si fuiste tú).
            </p>

            <div class="sticky bottom-0 flex flex-wrap items-center justify-between gap-4 rounded-lg bg-white p-4 shadow dark:bg-gray-800">
                <p class="text-sm text-gray-600 dark:text-gray-300">
                    Consumo <strong x-text="dinero(calculo.subtotal)"></strong> · Propina <strong x-text="dinero(calculo.propina)"></strong> ·
                    Total <strong class="text-lg text-gray-900 dark:text-gray-100" x-text="dinero(calculo.total)"></strong>
                </p>
                <div class="flex gap-3">
                    <a href="{{ $evento ? route('eventos.show', $evento) : route('eventos.index') }}"><x-secondary-button type="button">Cancelar</x-secondary-button></a>
                    <x-primary-button type="submit" x-bind:disabled="loading">Guardar</x-primary-button>
                </div>
            </div>
        </form>
    </div>
</x-app-layout>
