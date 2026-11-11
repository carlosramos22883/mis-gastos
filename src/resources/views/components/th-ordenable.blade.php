{{-- Encabezado que ordena en el navegador una tabla con x-data="tablaOrdenable(...)" (filas con data-orden-{campo}) --}}
@props(['campo'])
<th {{ $attributes->merge(['class' => 'px-6 py-3']) }}>
    <button type="button" x-on:click="ordenar('{{ $campo }}')" class="inline-flex items-center gap-1 uppercase hover:text-gray-700 dark:hover:text-gray-300 transition-colors">
        {{ $slot }}
        <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20" x-bind:class="{ 'opacity-30': campo !== '{{ $campo }}', 'rotate-180': campo === '{{ $campo }}' && direccion === 'desc' }">
            <path fill-rule="evenodd" d="M14.707 12.707a1 1 0 01-1.414 0L10 9.414l-3.293 3.293a1 1 0 01-1.414-1.414l4-4a1 1 0 011.414 0l4 4a1 1 0 010 1.414z" clip-rule="evenodd" />
        </svg>
    </button>
</th>
