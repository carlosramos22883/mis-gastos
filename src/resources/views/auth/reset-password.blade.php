<x-guest-layout>
    <!-- Título y botón de modo oscuro -->
    <div class="flex items-center justify-between mb-6">
        <h1>Restablecer contraseña</h1>
        <!-- Botón de Modo Oscuro / Claro -->
        <x-dark-mode-toggle />
    </div>

    <div class="mb-4">
        <p>{{ __('Digita tu nueva contraseña y presiona el botón de restablecer.') }}</p>
    </div>

    <form method="POST" action="{{ route('password.store') }}" novalidate>
        @csrf

        <!-- Token de restablecimiento de contraseña -->
        <input type="hidden" name="token" value="{{ $request->route('token') }}">

        <!-- Correo electrónico (Solo lectura) -->
        <div class="mb-4">
            <x-floating-input id="email" name="email" label="Correo electrónico" type="email" :value="$request->email"
                :error="$errors->first('email')" readonly
                class="bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-400 cursor-not-allowed"
                autocomplete="username" />
        </div>

        <!-- Contraseña -->
        <div class="mb-4">
            <x-floating-input id="password" name="password" label="Nueva contraseña" type="password" :error="$errors->first('password')"
                required autofocus autocomplete="new-password" />
        </div>

        <!-- Confirmar Contraseña -->
        <div class="mb-4">
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