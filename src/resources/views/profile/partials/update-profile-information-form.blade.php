<section>
    <header class="mb-6 text-center mt-4">
        <h2 class="mb-4">
            {{ __('Información del Perfil') }}
        </h2>

        <p class="mb-4">
            {{ __('Actualiza la información de perfil y correo electrónico de tu cuenta.') }}
        </p>
    </header>

    <form id="send-verification" method="post" action="{{ route('verification.send') }}">
        @csrf
    </form>

    <form method="post" action="{{ route('profile.update') }}" class="space-y-2" novalidate id="profile-update-form">
        @csrf
        @method('patch')

        <div>
            <div class="mb-4">
                <x-floating-input id="name" label="Nombre" type="text" :error="$errors->first('name')" :value="$user->name"
                    required autofocus autocomplete="name" />
            </div>
        </div>

        <div>
            <div class="mb-4">
                <x-floating-input id="email" label="Correo electrónico" type="email" :error="$errors->first('email')"
                    :value="$user->email" required autocomplete="username" />
            </div>

            @if ($user instanceof \Illuminate\Contracts\Auth\MustVerifyEmail && !$user->hasVerifiedEmail())
                <div>
                    <p class="text-sm mt-2 text-gray-800 dark:text-gray-200">
                        {{ __('Tu correo electrónico no ha sido verificado.') }}

                        <button form="send-verification"
                            class="underline text-sm text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-200 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                            {{ __('Haz clic aquí para reenviar el correo de verificación.') }}
                        </button>
                    </p>
                </div>
            @endif
        </div>

        <div class="flex items-center justify-end gap-4 mt-8">
            <x-primary-button class="dark:bg-primary-700 dark:hover:bg-primary-800">
                {{ __('Actualizar') }}
            </x-primary-button>
        </div>
    </form>
</section>

<!-- Scripts para manejar las notificaciones con SweetAlert -->
<script>
    document.addEventListener('DOMContentLoaded', function() {
        // 1. Mensaje de perfil actualizado exitosamente
        @if (session('status') === 'profile-updated')
            showAlert('success', '¡Éxito!', 'Información del perfil actualizada correctamente.');
        @endif

        // 2. Mensaje de enlace de verificación enviado
        @if (session('status') === 'verification-link-sent')
            showAlert('success', '¡Enlace enviado!', 'Se ha enviado un nuevo enlace de verificación a tu correo electrónico.');
        @endif
    });
</script>