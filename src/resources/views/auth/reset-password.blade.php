<x-guest-layout>
    <!-- Título y botón de modo oscuro -->
    <div class="flex items-center justify-between mb-6">
        <h1 class="font-semibold text-xl text-gray-800 dark:text-gray-100 leading-tight">Restablecer contraseña</h1>

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
        {{ __('Digita tu nueva contraseña y presiona el botón de restablecer.') }}
    </div>

    <form method="POST" action="{{ route('password.store') }}" novalidate id="reset-form">
        @csrf

        <!-- Token de restablecimiento de contraseña -->
        <input type="hidden" name="token" value="{{ $request->route('token') }}">

        <!-- Correo electrónico (Solo lectura) -->
        <div class="mb-6">
            <x-floating-input id="email" name="email" label="Correo electrónico" type="email" :value="$request->email"
                :error="$errors->first('email')" readonly
                class="bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-400 cursor-not-allowed"
                autocomplete="username" />
        </div>

        <!-- Contraseña -->
        <div class="mb-6">
            <x-floating-input id="password" name="password" label="Nueva contraseña" type="password" :error="$errors->first('password')"
                required autofocus autocomplete="new-password" />
        </div>

        <!-- Confirmar Contraseña -->
        <div class="mb-6">
            <x-floating-input id="password_confirmation" name="password_confirmation" label="Confirmar contraseña"
                type="password" :error="$errors->first('password_confirmation')" required autocomplete="new-password" />
        </div>

        <!-- Botones -->
        <div class="flex items-center justify-end gap-4 mt-8">
            <!-- Botón secundario redondo y reutilizable -->
            <x-secondary-button onclick="window.location.href='{{ route('login') }}'">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-2" fill="none" viewBox="0 0 24 24"
                    stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                </svg>
                {{ __('Ir al Login') }}
            </x-secondary-button>

            <!-- Botón principal -->
            <x-primary-button>
                {{ __('Restablecer') }}
            </x-primary-button>
        </div>
    </form>
</x-guest-layout>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const form = document.getElementById('reset-form');
        if (!form) return;

        form.addEventListener('submit', function(e) {
            const password = form.querySelector('#password');
            const passwordConfirmation = form.querySelector('#password_confirmation');
            let errors = [];

            if (!password.value) {
                errors.push('La nueva contraseña es obligatoria.');
            } else if (password.value.length < 8) {
                errors.push('La contraseña debe tener al menos 8 caracteres.');
            }
            if (password.value !== passwordConfirmation.value) {
                errors.push('Las contraseñas no coinciden.');
            }

            if (errors.length > 0) {
                e.preventDefault();
                showAlert('warning', 'Revisa los siguientes errores', errors.join('\n'));
                return false;
            }
        });
    });
</script>
