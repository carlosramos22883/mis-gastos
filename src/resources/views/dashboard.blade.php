<x-app-layout>
    <x-slot name="header">
        <h1>
            {{ __('Página de inicio') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900 dark:text-gray-100">
                    {{ __("¡Has iniciado sesión!") }}
                </div>
            </div>
        </div>
    </div>
</x-app-layout>

<script>
    console.log('Todas las sesiones:', @json(session()->all()));
    document.addEventListener('DOMContentLoaded', function() {

        @if (session('verification_error'))
            showAlert(
                'warning',
                'No se puede verificar esta cuenta',
                '{{ session('verification_error') }}',
                { timer: 6000 }
            );
        @endif

        // Intento de resetear contraseña de otra cuenta
        @if (session('password_reset_error'))
            showAlert(
                'warning',
                'Atención',
                '{{ session('password_reset_error') }}',
                 { timer: 6000 }
            );
        @endif

    });
</script>   