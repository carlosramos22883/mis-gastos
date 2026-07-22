<x-guest-layout>
    <!-- Título y botón de modo oscuro -->
    <div class="flex items-center justify-between mb-6">
        <h1>Registrarse</h1>
        <!-- Botón de Modo Oscuro / Claro -->
        <x-dark-mode-toggle />
    </div>

    <div>
        <p>{{ __('Digita tus datos para registrarte en el sistema.') }}</p>
    </div>

    <form method="POST" action="{{ route('register') }}" class="space-y-2" novalidate>
        @csrf

        <!-- Nombre -->
        <div class="mb-4">
            <x-floating-input id="name" name="name" label="Nombre" type="text" :value="old('name')"
                :error="$errors->first('name')" required autofocus autocomplete="name" />
        </div>

        <!-- Correo electrónico -->
        <div class="mb-4">
            <x-floating-input id="email" name="email" label="Correo electrónico" type="email" :value="old('email')"
                :error="$errors->first('email')" required autocomplete="username" />
        </div>

        <!-- Contraseña -->
        <div class="mb-4">
            <x-floating-input id="password" name="password" label="Contraseña" type="password" :error="$errors->first('password')"
                required autocomplete="new-password" />
        </div>

        <!-- Confirmar Contraseña -->
        <div class="mb-4">
            <x-floating-input id="password_confirmation" name="password_confirmation" label="Confirmar Contraseña"
                type="password" :error="$errors->first('password_confirmation')" required autocomplete="new-password" />
        </div>

        <!-- Botones de acción -->
        <div class="flex items-center justify-end gap-4 mt-8">
            <x-secondary-button onclick="window.location.href='{{ route('login') }}'">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-2" fill="none" viewBox="0 0 24 24"
                    stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                </svg>
                {{ __('Ir al Login') }}
            </x-secondary-button>

            <x-primary-button>
                {{ __('Registrarse') }}
            </x-primary-button>
        </div>
    </form>
</x-guest-layout>