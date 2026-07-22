@props(['type' => 'submit'])

<button 
    {{ $attributes->merge(['type' => $type, 'class' => 'btn-primary-custom']) }}
>
    {{ $slot }}
</button>