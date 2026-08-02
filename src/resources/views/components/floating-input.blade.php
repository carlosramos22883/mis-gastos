@props(['id', 'label', 'type' => 'text', 'name' => null, 'value' => '', 'error' => null])

@php
    $name = $name ?? $id;
    $inputValue = old($name, $value);
@endphp

<div 
    x-data="{ 
        inputValue: '{{ $inputValue }}',
        isFocused: false
    }" 
    class="relative w-full"
>
    <div class="relative w-full">
        <input 
            type="{{ $type }}"
            id="{{ $id }}"
            name="{{ $name }}"
            x-model="inputValue"
            @focus="isFocused = true"
            @blur="isFocused = false"
            placeholder=" "
            {{ $attributes->merge([
                'class' => 'block w-full px-4 py-2.5 text-xs text-gray-900 dark:text-white bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg appearance-none focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-primary-500'
            ]) }}
        >
        
        <!-- Label Flotante -->
        <label 
            for="{{ $id }}"
            :class="{
                'absolute pointer-events-none transition-all duration-200 left-2.5 px-1 z-20': true,
                '-top-2.5 text-xs font-normal bg-white dark:bg-gray-800': isFocused || (inputValue !== null && inputValue !== ''),
                'top-3 text-xs text-gray-500 dark:text-gray-400 bg-transparent': !isFocused && (inputValue === null || inputValue === ''),
                'text-primary-600 dark:text-primary-400': isFocused,
                'text-gray-500 dark:text-gray-400': !isFocused && (inputValue !== null && inputValue !== '')
            }"
        >
            {{ $label }}
        </label>
    </div>

    @if($error)
        <p class="mt-1 text-xs flex items-center gap-1 text-red-600 dark:text-red-400 font-medium" style="color: var(--color-danger);">
            <svg class="w-4 h-4 shrink-0" fill="currentColor" viewBox="0 0 20 20" style="color: var(--color-danger);">
                <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
            </svg>
            {{ $error }}
        </p>
    @endif
</div>