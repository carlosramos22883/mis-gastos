@props([
    'size' => 'md', // md, lg, sm
    'alignment' => 'center' // center, left, right
])

@php
    $sizeClasses = [
        'sm' => 'p-1.5 rounded-md',
        'md' => 'p-2 rounded-lg',
        'lg' => 'p-3 rounded-xl',
    ];
    
    $alignmentClasses = [
        'center' => 'flex justify-center',
        'left' => 'flex justify-start',
        'right' => 'flex justify-end',
    ];
@endphp

<button 
    onclick="toggleDarkMode()"
    class="{{ $sizeClasses[$size] ?? $sizeClasses['md'] }} {{ $alignmentClasses[$alignment] ?? $alignmentClasses['center'] }} 
           text-gray-500 dark:text-gray-400 
           hover:bg-gray-100 dark:hover:bg-gray-700 
           focus:outline-none focus:ring-2 focus:ring-primary-500 
           transition-colors"
    aria-label="Alternar modo oscuro">
    <!-- Icono de Sol (se muestra en modo oscuro) -->
    <svg class="w-5 h-5 hidden dark:block" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
            d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z">
        </path>
    </svg>
    <!-- Icono de Luna (se muestra en modo claro) -->
    <svg class="w-5 h-5 block dark:hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
            d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z">
        </path>
    </svg>
</button>