<x-guest-layout>
    <!-- Título y botón de modo oscuro -->
    <div class="flex items-center justify-between mb-6">
        <h1>Olvidaste tu contraseña</h1>
        <!-- Botón de Modo Oscuro / Claro -->
        <x-dark-mode-toggle />
    </div>

    <div class="mb-4">
        <p>{{ __('¿Olvidaste tu contraseña? No hay problema. Solo necesitas proporcionarnos tu correo electrónico y te enviaremos un enlace para restablecerla.') }}</p>
    </div>

    <form method="POST" action="{{ route('password.email') }}" novalidate id="forgot-form">
        @csrf

        <!-- Email Address con Floating Input -->
        <div class="mb-4">
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
        // Mostrar SweetAlert de éxito y REDIRIGIR al login
        @if (session('status'))
            showAlert('success', '¡Enlace enviado!', '{{ session('status') }}')
                .then(() => {
                    window.location.href = "{{ route('login') }}";
                });
        @endif

        
    });
</script>
