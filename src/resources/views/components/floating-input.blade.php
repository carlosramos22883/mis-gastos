@props(['id', 'label', 'type' => 'text', 'name' => null, 'value' => '', 'error' => null])

@php
    $name = $name ?? $id;
    $hasValue = old($name, $value) !== '';
@endphp

<div class="relative">
    <input 
        type="{{ $type }}"
        id="{{ $id }}"
        name="{{ $name }}"
        value="{{ old($name, $value) }}"
        placeholder=" "
        {{ $attributes->merge([
            'class' => 'block w-full px-4 py-3 text-sm text-gray-900 dark:text-white bg-transparent border border-gray-300 dark:border-gray-600 rounded-lg appearance-none focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-primary-500 peer dark:bg-gray-700'
        ]) }}
    >
    <label 
        for="{{ $id }}"
        class="{{ $hasValue ? 'absolute text-xs text-primary-600 dark:text-primary-400 -translate-y-4 scale-75 top-2 z-10 origin-[0] bg-white dark:bg-gray-800 px-2 rounded left-2' : 'absolute text-sm text-gray-500 dark:text-gray-400 duration-300 transform translate-y-0 scale-100 top-3 z-10 origin-[0] bg-transparent px-0 left-4 peer-focus:-translate-y-4 peer-focus:scale-75 peer-focus:top-2 peer-focus:z-10 peer-focus:bg-white peer-focus:dark:bg-gray-800 peer-focus:px-2 peer-focus:rounded peer-focus:text-primary-600 peer-focus:dark:text-primary-400 peer-not-placeholder-shown:-translate-y-4 peer-not-placeholder-shown:scale-75 peer-not-placeholder-shown:top-2 peer-not-placeholder-shown:z-10 peer-not-placeholder-shown:bg-white peer-not-placeholder-shown:dark:bg-gray-800 peer-not-placeholder-shown:px-2 peer-not-placeholder-shown:rounded peer-not-placeholder-shown:text-xs' }}"
    >
        {{ $label }}
    </label>
    
    @if($error)
        <p class="mt-2 text-sm flex items-center gap-1" style="color: var(--color-danger);">
            <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20" style="color: var(--color-danger);">
                <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
            </svg>
            {{ $error }}
        </p>
    @endif
</div>