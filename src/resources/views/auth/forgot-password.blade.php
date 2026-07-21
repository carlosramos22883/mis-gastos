<x-guest-layout>
    <!-- Título y botón de modo oscuro -->
    <div class="flex items-center justify-between mb-6">
        <h1 class="font-semibold text-xl text-gray-800 dark:text-gray-100 leading-tight">Olvidaste tu contraseña</h1>

        <button onclick="toggleDarkMode()"
            class="p-2 rounded-lg text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-primary-500 transition-colors"
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
                    d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"></path>
            </svg>
        </button>
    </div>

    <div class="mb-6 text-sm text-gray-600 dark:text-gray-400">
        {{ __('¿Olvidaste tu contraseña? No hay problema. Solo necesitas proporcionarnos tu correo electrónico y te enviaremos un enlace para restablecerla.') }}
    </div>

    <form method="POST" action="{{ route('password.email') }}" novalidate id="forgot-form">
        @csrf

        <!-- Email Address con Floating Input -->
        <div class="mb-6">
            <x-floating-input id="email" label="Correo electrónico" type="email" :error="$errors->first('email')" required
                autofocus />
        </div>

        <!-- Botones con espacio entre ellos -->
        <div class="flex items-center justify-end gap-4">
            <x-secondary-button onclick="window.location.href='{{ route('login') }}'">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-2" fill="none" viewBox="0 0 24 24"
                    stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                </svg>
                {{ __('Ir al Login') }}
            </x-secondary-button>

            <!-- El componente primary-button ya maneja el modo oscuro, así que lo dejamos limpio -->
            <x-primary-button>
                {{ __('Enviar enlace') }}
            </x-primary-button>
        </div>
    </form>
</x-guest-layout>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Validación del formulario
        const form = document.getElementById('forgot-form');
        if (form) {
            form.addEventListener('submit', function(e) {
                const email = form.querySelector('#email');
                let errorMessage = '';

                if (!email.value.trim()) {
                    errorMessage = 'El correo electrónico es obligatorio.';
                } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.value)) {
                    errorMessage = 'Por favor, ingresa un correo electrónico válido.';
                }

                if (errorMessage) {
                    e.preventDefault();
                    showAlert('warning', 'Campo incompleto', errorMessage);
                    return false;
                }
            });
        }

        // Mostrar SweetAlert de éxito y REDIRIGIR al login
        @if (session('status'))
            showAlert('success', '¡Enlace enviado!', '{{ session('status') }}')
                .then(() => {
                    window.location.href = "{{ route('login') }}";
                });
        @endif

        // Mostrar errores
        @if ($errors->has('email'))
            showAlert('error', 'Error', '{{ $errors->first('email') }}');
        @endif
    });
</script>
