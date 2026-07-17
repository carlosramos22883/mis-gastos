@props(['active'])

@php
$classes = $active ?? false
            ? 'block w-full px-4 py-2 text-start text-sm font-medium text-white bg-primary-600 dark:bg-primary-500 hover:bg-primary-700 dark:hover:bg-primary-400 focus:outline-none focus:bg-primary-700 dark:focus:bg-primary-400 transition duration-150'
            : 'block w-full px-4 py-2 text-start text-sm text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700 focus:outline-none focus:bg-gray-100 dark:focus:bg-gray-700 transition duration-150';
@endphp

<a {{ $attributes->merge(['class' => $classes]) }}>
    {{ $slot }}
</a>