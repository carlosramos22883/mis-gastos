@props(['padding' => 'p-6'])

<div {{ $attributes->merge(['class' => "bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 shadow-md hover:shadow-lg transition-shadow duration-300 {$padding}"]) }}>
    {{ $slot }}
</div>