<x-guest-layout>
    <!-- Título y botón de modo oscuro -->
    <div class="flex items-center justify-between mb-6">
        <h1>Inicia Sesión</h1>
        <!-- Botón de Modo Oscuro / Claro -->
        <x-dark-mode-toggle />
    </div>

    <form method="POST" action="{{ route('login') }}" novalidate>
        @csrf

        <div class="mb-4">
            <x-floating-input id="email" label="Correo electrónico" type="email" :error="$errors->first('email')" />
        </div>

        <div class="mb-4">
            <x-floating-input id="password" label="Contraseña" type="password" :error="$errors->first('password')" />
        </div>

        <div class="flex justify-end mb-4">
            <a href="{{ route('password.request') }}">
                ¿Olvidaste tu contraseña?
            </a>
        </div>

        <div class="mb-4">
            <x-primary-button class="w-full justify-center">
                Iniciar Sesión
            </x-primary-button>
        </div>

        <div class="flex items-center justify-center my-4">
            <div class="flex-1 border-t border-gray-300 dark:border-gray-600"></div>
            <div class="px-4 text-sm">O</div>
            <div class="flex-1 border-t border-gray-300 dark:border-gray-600"></div>
        </div>

        <div class="mb-4">
            <a href="{{ route('auth.google') }}"
                class="w-full flex justify-center items-center gap-2 bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-full py-3 font-medium hover:bg-gray-50 dark:hover:bg-gray-600 transition-colors">
                <svg class="w-5 h-5" viewBox="0 0 24 24">
                    <path
                        d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"
                        fill="#4285F4" />
                    <path
                        d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"
                        fill="#34A853" />
                    <path
                        d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"
                        fill="#FBBC05" />
                    <path
                        d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"
                        fill="#EA4335" />
                </svg>
                Continuar con Google
            </a>
        </div>

        <div class="text-center mt-6">
            <p>
                ¿No tienes cuenta?
                <a href="{{ route('register') }}">
                    Regístrate
                </a>
            </p>
        </div>
    </form>
</x-guest-layout>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Mostrar SweetAlert de éxito si viene de restablecer contraseña
        @if (session('status'))
            showAlert('success', '¡Contraseña restablecida!', '{{ session('status') }}');
        @endif
    });
</script>