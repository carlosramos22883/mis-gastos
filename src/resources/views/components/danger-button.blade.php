@props(['type' => 'button'])

<button {{ $attributes->merge(['type' => $type, 'class' => 'inline-flex items-center px-6 py-3 bg-red-600 dark:bg-red-700 border border-transparent rounded-full font-semibold text-sm text-white tracking-widest hover:bg-red-500 dark:hover:bg-red-600 focus:bg-red-500 dark:focus:bg-red-600 active:bg-red-700 dark:active:bg-red-500 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 dark:focus:ring-offset-gray-800 transition ease-in-out duration-150']) }}>
    {{ $slot }}
</button>