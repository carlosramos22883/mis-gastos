<x-app-layout>
    <x-slot name="header"><h1>Lista de compras</h1></x-slot>
    <div class="max-w-4xl mx-auto space-y-6">
        <div class="flex justify-end"><a href="{{ route('tiendas.index') }}" class="text-sm font-medium text-primary-600 hover:underline dark:text-primary-400">Mis tiendas →</a></div>
        @if ($abierta)
            <a href="{{ route('compras.show', $abierta) }}" class="block rounded-xl bg-white p-6 shadow ring-2 ring-primary-500/60 hover:bg-gray-50 dark:bg-gray-800 dark:hover:bg-gray-700/40">
                <p class="text-xs font-semibold uppercase tracking-wide text-primary-600 dark:text-primary-400">Lista abierta</p>
                <p class="mt-1 text-xl font-semibold text-gray-900 dark:text-gray-100">{{ $abierta->nombre }}</p>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $abierta->items_count }} {{ $abierta->items_count === 1 ? 'cosa' : 'cosas' }} · {{ $abierta->comprados_count }} en el carrito</p>
                <p class="mt-3 text-sm font-medium text-primary-600 dark:text-primary-400">Continuar →</p>
            </a>
        @else
            <div class="rounded-xl bg-white p-6 shadow dark:bg-gray-800">
                <p class="text-gray-700 dark:text-gray-200">
                    @if ($faltan)
                        En tu despensa {{ $faltan === 1 ? 'falta 1 producto' : "faltan {$faltan} productos" }}. La lista nueva {{ $faltan === 1 ? 'lo trae' : 'los trae' }} ya {{ $faltan === 1 ? 'agregado' : 'agregados' }}, con cuántos comprar.
                    @else
                        No falta nada en tu despensa. Puedes abrir una lista y agregar lo que quieras.
                    @endif
                </p>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Después quitas lo que no quieras, agregas otros productos o anotas cosas que no están en tu despensa.</p>
                @can('despensa.create')
                    <form method="POST" action="{{ route('compras.store') }}" class="mt-4">
                        @csrf
                        <x-primary-button type="submit">Nueva lista de compras</x-primary-button>
                    </form>
                @endcan
            </div>
        @endif

        <div>
            <h2 class="mb-3 text-lg font-semibold text-gray-900 dark:text-gray-100">Compras anteriores</h2>
            @if ($historial->isEmpty())
                <p class="text-sm text-gray-500 dark:text-gray-400">Aquí aparecerán las listas que termines.</p>
            @else
                <ul class="divide-y divide-gray-100 overflow-hidden rounded-xl bg-white shadow dark:divide-gray-700 dark:bg-gray-800">
                    @foreach ($historial as $lista)
                        <li>
                            <a href="{{ route('compras.show', $lista) }}" class="flex items-center justify-between gap-4 px-5 py-4 hover:bg-gray-50 dark:hover:bg-gray-700/40">
                                <span class="font-medium text-gray-900 dark:text-gray-100">{{ $lista->nombre }}</span>
                                <span class="text-sm text-gray-500 dark:text-gray-400">{{ $lista->comprados_count }} de {{ $lista->items_count }} comprados</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
</x-app-layout>
