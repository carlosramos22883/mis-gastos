<x-guest-layout>
    <div class="mb-4">
        <h2>{{ __('¡Hola!') }}</h2>
    </div>
    <div class="mb-4">
        <p>{{ __('Estás aquí por una de estas razones:') }}</p>
    </div>
    <div class="mb-4">
        <ul class="list-disc list-inside space-y-2 mb-4">
            <li>{{ __('Te registraste en el sistema, pero aún no has verificado tu correo electrónico.') }}</li>
            <li>
                {{ __('Cambiaste tu dirección de correo y debes verificarla de nuevo para poder ingresar.') }}
            </li>
        </ul>
    </div>

    <div class="mb-8">
        <p><b>¿Que debes hacer?</b>
            {{ __('Revisa tu bandeja de entrada. Si no lo encuentras, avísanos y con gusto te enviaremos otro.') }}</p>
    </div>

    <div class="flex flex-row items-center justify-end gap-4">
        <form id="logout-form" method="POST" action="{{ route('logout') }}" class="w-full sm:w-auto">
            @csrf
            <x-secondary-button type="submit" class="w-full justify-center">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-2" fill="none" viewBox="0 0 24 24"
                    stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                </svg>
                {{ __('Ir al Login') }}
            </x-secondary-button>
        </form>

        <form method="POST" action="{{ route('verification.send') }}" class="w-full sm:w-auto">
            @csrf
            <x-primary-button type="submit" class="w-full justify-center">
                {{ __('Reenviar') }}
            </x-primary-button>
        </form>
    </div>
</x-guest-layout>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        @if (session('status') == 'verification-link-sent')
            showAlert('success', 'Mensaje Reenviado',
                'Se ha enviado un nuevo enlace de verificación a tu correo electrónico.'
            )
            .then(() => {
                document.getElementById('logout-form').submit();
            });
        @endif
    });
</script>