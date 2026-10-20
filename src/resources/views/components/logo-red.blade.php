@props(['marca' => null])
{{-- Logo de la red de la tarjeta (Visa, Mastercard…) sobre una pastilla blanca para que se vea sobre cualquier color. --}}
@if ($marca?->logo)
    <span {{ $attributes->merge(['class' => 'inline-flex h-8 items-center rounded-md bg-white px-1.5 shadow-sm']) }} title="{{ $marca->nombre }}">
        <img src="{{ filter_var($marca->logo, FILTER_VALIDATE_URL) ? $marca->logo : asset('storage/'.$marca->logo) }}" alt="{{ $marca->nombre }}" class="h-7 w-auto max-w-[3.5rem] object-contain">
    </span>
@elseif ($marca)
    <span {{ $attributes->merge(['class' => 'text-xs font-semibold uppercase tracking-wide opacity-90']) }}>{{ $marca->nombre }}</span>
@endif
