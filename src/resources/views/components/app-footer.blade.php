<footer class="fixed bottom-0 left-0 right-0 z-40 bg-white dark:bg-gray-800 border-t border-gray-200 dark:border-gray-700 shadow-lg">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4">
        <div class="flex items-center justify-center gap-3">
            <!-- Logo -->
            <img src="{{ asset('images/logo-light.png') }}" alt="{{ config('app.name') }}" class="h-8 w-auto dark:hidden">
            <img src="{{ asset('images/logo-dark.png') }}" alt="{{ config('app.name') }}" class="h-8 w-auto hidden dark:block">
            
            <p class="text-sm text-gray-600 dark:text-gray-400 sm:hidden">
                {{ config('app.name') }}
            </p>
            <p class="text-sm text-gray-600 dark:text-gray-400 hidden sm:block">
                {{ config('app.name') }}. Controla tus finanzas
            </p>
        </div>
    </div>
</footer>