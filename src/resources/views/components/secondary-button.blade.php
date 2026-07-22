@props(['type' => 'button'])

<button 
    {{ $attributes->merge(['type' => $type, 'class' => 'btn-secondary-custom']) }}
>
    {{ $slot }}
</button>