@props(['banco' => null, 'size' => 'md'])
{{-- Logo del banco (del catálogo). Sin logo: sus iniciales. Uso: <x-logo-banco :banco="$cuenta->banco" size="sm" /> --}}
@php
    $medida = ['sm' => 'h-6 w-6 text-[9px]', 'md' => 'h-9 w-9 text-xs', 'lg' => 'h-12 w-12 text-sm'][$size] ?? 'h-9 w-9 text-xs';
    $iniciales = $banco ? collect(preg_split('/\s+/', preg_replace('/\b(Banco|de|del|la|el)\b|\(.*?\)/iu', '', $banco->nombre)))->filter()->map(fn ($p) => mb_substr($p, 0, 1))->take(2)->implode('') : '';
@endphp
@if ($banco?->logo)
    <img src="{{ filter_var($banco->logo, FILTER_VALIDATE_URL) ? $banco->logo : asset('storage/'.$banco->logo) }}" alt="{{ $banco->nombre }}" title="{{ $banco->nombre }}"
        {{ $attributes->merge(['class' => "$medida shrink-0 rounded-lg bg-white object-cover shadow-sm ring-1 ring-black/5"]) }}>
@elseif ($banco)
    <span title="{{ $banco->nombre }}" {{ $attributes->merge(['class' => "$medida inline-flex shrink-0 items-center justify-center rounded-lg bg-gray-100 font-bold uppercase text-gray-600 ring-1 ring-black/5 dark:bg-gray-700 dark:text-gray-300"]) }}>{{ mb_strtoupper($iniciales) }}</span>
@endif
