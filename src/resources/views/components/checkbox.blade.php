{{--
    Casilla del proyecto: la etiqueta entera se puede tocar, con los colores del tema y modo oscuro.
    - sinMarcar="0": envía ese valor cuando no está marcada (campos booleanos que se editan).
    - ayuda: texto pequeño debajo. peligro: en rojo (ej. "Quitar foto").
    - title y class van a la etiqueta; lo demás (id, x-model, x-bind:name…) a la casilla.
--}}
@props(['name' => null, 'value' => '1', 'checked' => false, 'sinMarcar' => null, 'ayuda' => null, 'peligro' => false])

<label {{ $attributes->only(['title', 'class'])->merge(['class' => 'flex cursor-pointer items-start gap-2 text-sm text-gray-700 dark:text-gray-300']) }}>
    @if ($name !== null && $sinMarcar !== null)
        <input type="hidden" name="{{ $name }}" value="{{ $sinMarcar }}">
    @endif
    <input type="checkbox" @if ($name !== null) name="{{ $name }}" @endif value="{{ $value }}" @checked($checked)
        {{ $attributes->except(['title', 'class']) }}
        class="mt-0.5 h-4 w-4 shrink-0 rounded border-gray-300 dark:border-gray-600 dark:bg-gray-700 {{ $peligro ? 'text-red-600 focus:ring-red-500' : 'text-primary-600 focus:ring-primary-500' }}">
    <span>{{ $slot }}@if ($ayuda)<span class="block text-xs text-gray-500 dark:text-gray-400">{{ $ayuda }}</span>@endif</span>
</label>
