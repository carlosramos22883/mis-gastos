@props(['type' => 'button'])

<button 
    {{ $attributes->merge(['type' => $type, 'class' => 'btn-danger-custom']) }}
>
    {{ $slot }}
</button>