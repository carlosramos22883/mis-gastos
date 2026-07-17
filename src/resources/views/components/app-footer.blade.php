<!-- Footer -->
<footer class="bg-white dark:bg-gray-800 border-t border-gray-200 dark:border-gray-700 mt-auto">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4">
        <div class="flex items-center justify-center gap-3">
            <!-- Logo -->
            <img src="{{ asset('images/logo-light.png') }}" alt="Mis Gastos" class="h-8 w-auto dark:hidden">
            <img src="{{ asset('images/logo-dark.png') }}" alt="Mis Gastos" class="h-8 w-auto hidden dark:block">
            
            <!-- Texto corto (solo en móvil) -->
            <p class="text-sm text-gray-600 dark:text-gray-400 sm:hidden">
                Mis Gastos
            </p>
            
            <!-- Texto completo (en pantallas medianas y grandes) -->
            <p class="text-sm text-gray-600 dark:text-gray-400 hidden sm:block">
                Mis Gastos. Controla tus finanzas
            </p>
        </div>
    </div>
</footer>