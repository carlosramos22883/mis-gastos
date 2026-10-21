@props(['contacto' => null, 'size' => 'md'])
{{-- Foto del contacto (privada) o sus iniciales. Uso: <x-avatar-contacto :contacto="$c" size="sm" /> --}}
@php($medida = ['xs' => 'h-5 w-5 text-[9px]', 'sm' => 'h-7 w-7 text-[10px]', 'md' => 'h-10 w-10 text-sm', 'lg' => 'h-20 w-20 text-2xl'][$size] ?? 'h-10 w-10 text-sm')
@if ($contacto?->foto)
    <img src="{{ $contacto->urlFoto() }}" alt="{{ $contacto->nombreCompleto() }}" title="{{ $contacto->nombreCompleto() }}" loading="lazy"
        {{ $attributes->merge(['class' => "$medida shrink-0 rounded-full object-cover ring-1 ring-black/5"]) }}>
@elseif ($contacto)
    <span title="{{ $contacto->nombreCompleto() }}" {{ $attributes->merge(['class' => "$medida inline-flex shrink-0 items-center justify-center rounded-full bg-amber-100 font-semibold text-amber-800 dark:bg-amber-900/40 dark:text-amber-200"]) }}>{{ $contacto->iniciales() }}</span>
@endif
