@php($pestana = fn (bool $activa) => $activa ? 'bg-white font-semibold text-gray-900 shadow dark:bg-gray-800 dark:text-gray-100' : 'text-gray-600 dark:text-gray-300')
<x-app-layout>
    <x-slot name="header"><h1>Despensa</h1></x-slot>
    <div class="max-w-7xl mx-auto space-y-6" x-data x-on:refresh-table.window="window.location.reload()">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div class="inline-flex flex-wrap rounded-lg bg-gray-100 p-1 text-sm dark:bg-gray-700">
                <a href="{{ route('productos.index') }}" class="rounded-md px-3 py-1.5 {{ $pestana(! $filtros['archivados'] && ! $filtros['faltan']) }}">Todos</a>
                <a href="{{ route('productos.index', ['faltan' => 1]) }}" class="rounded-md px-3 py-1.5 {{ $pestana($filtros['faltan']) }}">Faltan @if ($resumen['faltan'])<span class="ml-1 rounded-full bg-red-600 px-1.5 text-xs font-semibold text-white">{{ $resumen['faltan'] }}</span>@endif</a>
                <a href="{{ route('productos.index', ['archivados' => 1]) }}" class="rounded-md px-3 py-1.5 {{ $pestana($filtros['archivados']) }}">Ya no los compro</a>
            </div>
            <div class="flex flex-wrap items-center gap-3">
            <a href="{{ route('compras.index') }}" class="inline-flex items-center rounded-full border border-primary-600 px-4 py-2 text-sm font-semibold text-primary-700 hover:bg-primary-50 dark:border-primary-400 dark:text-primary-300 dark:hover:bg-primary-900/30">Lista de compras</a>
            @can('despensa.create')
                <x-primary-button type="button" x-on:click="$dispatch('load-producto-modal-form', '{{ route('productos.create') }}'); $dispatch('open-modal', 'producto-modal')">Nuevo producto</x-primary-button>
            @endcan
            </div>
        </div>

        @if ($resumen['por_vencer'] && ! $filtros['archivados'])
            <p class="rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:bg-amber-900/30 dark:text-amber-200">
                {{ $resumen['por_vencer'] === 1 ? 'Un producto vence pronto o ya venció.' : $resumen['por_vencer'].' productos vencen pronto o ya vencieron.' }} Están marcados en amarillo.
            </p>
        @endif

        <form method="GET" action="{{ route('productos.index') }}" class="grid gap-3 rounded-lg bg-white p-4 shadow sm:grid-cols-4 dark:bg-gray-800">
            @if ($filtros['archivados'])<input type="hidden" name="archivados" value="1">@endif
            @if ($filtros['faltan'])<input type="hidden" name="faltan" value="1">@endif
            <div class="sm:col-span-2"><x-floating-input id="buscar" label="Buscar por producto, marca o código" :value="$filtros['buscar']" /></div>
            <x-floating-select id="categoria" label="Categoría" :options="$categorias" :value="$filtros['categoria']" submitForm />
            <x-floating-select id="importancia" label="Importancia" :options="\App\Models\Producto::IMPORTANCIAS" :value="$filtros['importancia']" submitForm />
        </form>

        @if ($productos->isEmpty())
            <div class="rounded-lg bg-white p-10 text-center shadow dark:bg-gray-800">
                @if ($filtros['buscar'] || $filtros['categoria'] || $filtros['importancia'])
                    <p class="text-gray-600 dark:text-gray-300">Ningún producto coincide con la búsqueda.</p>
                @elseif ($filtros['archivados'])
                    <p class="text-gray-600 dark:text-gray-300">No hay productos archivados.</p>
                @elseif ($filtros['faltan'])
                    <p class="text-gray-600 dark:text-gray-300">No falta nada. La despensa está completa.</p>
                @else
                    <p class="text-gray-600 dark:text-gray-300">Aún no tienes productos en la despensa.</p>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Agrega lo que compras siempre (leche, cereal, atún…) con su marca, presentación y una foto. Con eso armaremos tu lista de compras y compararemos precios.</p>
                @endif
            </div>
        @else
            @php($basicos = $productos->filter->esBasico()->count())
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ $productos->count() }} {{ $productos->count() === 1 ? 'producto' : 'productos' }} · {{ $basicos }} {{ $basicos === 1 ? 'básico' : 'básicos' }}</p>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($productos as $p)
                    <div x-data="despensaProducto({ url: '{{ route('productos.despensa', $p) }}', estado: {{ \Illuminate\Support\Js::from($estados[$p->id]) }} })"
                        class="flex flex-col gap-3 rounded-xl bg-white p-4 shadow ring-2 transition dark:bg-gray-800"
                        :class="estado.falta ? 'ring-red-400/70 dark:ring-red-500/60' : (estado.vencimiento?.aviso ? 'ring-amber-400/80' : 'ring-transparent')"
                        data-record-id="{{ $p->id }}">
                        <div class="flex gap-4">
                            @if ($p->foto)
                                <img src="{{ $p->urlFoto() }}" alt="" loading="lazy" class="h-20 w-20 shrink-0 rounded-lg object-cover ring-1 ring-black/10">
                            @else
                                <div class="flex h-20 w-20 shrink-0 items-center justify-center rounded-lg bg-gray-100 text-gray-400 dark:bg-gray-700">
                                    <svg class="h-8 w-8" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 7h12l-1 13H7L6 7zM9 7V5a3 3 0 016 0v2" /></svg>
                                </div>
                            @endif
                            <div class="min-w-0 flex-1">
                                <p class="font-semibold leading-snug text-gray-900 dark:text-gray-100">{{ $p->nombre }}</p>
                                <p class="truncate text-sm text-gray-600 dark:text-gray-300">{{ collect([$p->marca, $p->presentacion()])->filter()->implode(' · ') ?: 'Sin marca' }}</p>
                                <div class="mt-2 flex flex-wrap gap-1.5 text-xs">
                                    <span class="rounded-full px-2 py-0.5 {{ $p->esBasico() ? 'bg-primary-100 text-primary-800 dark:bg-primary-900/40 dark:text-primary-300' : 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300' }}">{{ $p->esBasico() ? 'Básico' : 'De vez en cuando' }}</span>
                                    <span class="rounded-full bg-gray-100 px-2 py-0.5 text-gray-700 dark:bg-gray-700 dark:text-gray-300">{{ $p->categoria->nombre }}</span>
                                    @if ($p->acepta_otra_marca)
                                        <span class="rounded-full bg-sky-100 px-2 py-0.5 text-sky-800 dark:bg-sky-900/40 dark:text-sky-300">Otra marca sirve</span>
                                    @endif
                                    <span x-show="estado.falta" x-cloak class="rounded-full bg-red-100 px-2 py-0.5 font-semibold text-red-800 dark:bg-red-900/40 dark:text-red-300" x-text="'Falta · comprar ' + estado.por_comprar"></span>
                                </div>
                            </div>
                        </div>

                        @if ($p->activo)
                            {{-- Lo que hay en casa --}}
                            <div class="flex flex-wrap items-center gap-x-4 gap-y-2 border-t border-gray-100 pt-3 text-sm dark:border-gray-700">
                                @can('despensa.edit')
                                    <div class="flex items-center gap-2">
                                        <span class="text-gray-500 dark:text-gray-400">En casa</span>
                                        <button type="button" class="h-9 w-9 rounded-full bg-gray-100 text-lg font-semibold text-gray-700 hover:bg-gray-200 disabled:opacity-40 dark:bg-gray-700 dark:text-gray-200" :disabled="enviando || !estado.en_casa" x-on:click="cambiar('restar')" aria-label="Uno menos">−</button>
                                        <button type="button" class="min-w-10 rounded-md px-1 text-center text-lg font-bold text-gray-900 underline decoration-dotted underline-offset-4 dark:text-gray-100" x-on:click="escribirCantidad()" x-text="estado.en_casa ?? '?'" title="Escribir cuántos hay"></button>
                                        <button type="button" class="h-9 w-9 rounded-full bg-gray-100 text-lg font-semibold text-gray-700 hover:bg-gray-200 disabled:opacity-40 dark:bg-gray-700 dark:text-gray-200" :disabled="enviando" x-on:click="cambiar('sumar')" aria-label="Uno más">+</button>
                                        @if ($p->cantidad_ideal)<span class="text-xs text-gray-500 dark:text-gray-400">de {{ $p->cantidad_ideal }}</span>@endif
                                    </div>
                                    <div class="flex flex-wrap gap-3 text-xs font-medium">
                                        <button type="button" class="rounded-full px-2.5 py-1" :disabled="enviando"
                                            :class="estado.por_acabarse ? 'bg-amber-500 text-white' : 'bg-amber-50 text-amber-800 hover:bg-amber-100 dark:bg-amber-900/30 dark:text-amber-200'"
                                            x-on:click="cambiar('por_acabarse', estado.por_acabarse ? 0 : 1)" x-text="estado.por_acabarse ? 'Se está acabando ✓' : 'Se está acabando'"></button>
                                        <button type="button" x-show="estado.en_casa !== 0" class="rounded-full bg-red-50 px-2.5 py-1 text-red-700 hover:bg-red-100 dark:bg-red-900/30 dark:text-red-300" :disabled="enviando" x-on:click="cambiar('se_acabo')">Se acabó</button>
                                    </div>
                                @else
                                    <span class="text-gray-500 dark:text-gray-400">En casa: <strong class="text-gray-900 dark:text-gray-100" x-text="estado.en_casa ?? 'sin contar'"></strong></span>
                                @endcan
                            </div>
                            @if ($p->duracion === 'perecedero')
                                <div class="flex items-center gap-3 text-xs">
                                    <span x-show="estado.vencimiento" x-cloak class="rounded-full px-2 py-0.5 font-medium"
                                        :class="estado.vencimiento?.aviso ? 'bg-amber-100 text-amber-900 dark:bg-amber-900/40 dark:text-amber-200' : 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300'"
                                        x-text="estado.vencimiento?.texto"></span>
                                    @can('despensa.edit')
                                        <button type="button" x-show="estado.en_casa !== 0" class="font-medium text-primary-600 hover:underline dark:text-primary-400" x-on:click="escribirVence()" x-text="estado.vence_el ? 'Cambiar vencimiento' : 'Anotar vencimiento'"></button>
                                    @endcan
                                </div>
                            @endif
                        @endif

                        <div class="flex gap-4 text-xs font-medium">
                            <button type="button" class="text-primary-600 hover:underline dark:text-primary-400" x-on:click="$dispatch('load-producto-modal-form', '{{ route('productos.precios', $p) }}'); $dispatch('open-modal', 'producto-modal')">Precios</button>
                            @can('despensa.edit')
                                <button type="button" class="text-primary-600 hover:underline dark:text-primary-400" x-on:click="$dispatch('load-producto-modal-form', '{{ route('productos.edit', $p) }}'); $dispatch('open-modal', 'producto-modal')">Editar</button>
                            @endcan
                            @can('despensa.delete')
                                <button type="button" class="text-red-600 hover:underline dark:text-red-400" x-on:click="deleteItem({{ $p->id }}, {{ \Illuminate\Support\Js::from($p->nombreCompleto()) }}, '{{ route('productos.destroy', $p) }}')">Eliminar</button>
                            @endcan
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
    <x-ajax-modal name="producto" />
</x-app-layout>
