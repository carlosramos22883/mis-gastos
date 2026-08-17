<section>
    <header class="mb-6 text-center mt-4">
        <h2 class="mb-4">
            {{ __('Información del Perfil') }}
        </h2>

        <p class="mb-4">
            @can('profile.update')
                {{ __('Actualiza la información de perfil y correo electrónico de tu cuenta.') }}
            @elsecan('profile.view')
                {{ __('Información de perfil y correo electrónico de tu cuenta.') }}
            @endcan
        </p>
    </header>

    <form id="send-verification" method="post" action="{{ route('verification.send') }}">
        @csrf
    </form>
    @can('profile.update')
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

            <!-- Moneda Preferida -->
            <div class="mt-4">
                <x-floating-select id="moneda_preferida" name="moneda_preferida" label="Moneda Preferida" :options="[
                    'USD' => 'Dólar Estadounidense (USD)',
                    'EUR' => 'Euro (EUR)',
                    'MXN' => 'Peso Mexicano (MXN)',
                    'GTQ' => 'Quetzal (GTQ)',
                    'HNL' => 'Lempira Hondureña (HNL)',
                    'CRC' => 'Colón Costarricense (CRC)',
                    'NIO' => 'Córdoba Nicaragüense (NIO)',
                    'COP' => 'Peso Colombiano (COP)',
                    'PEN' => 'Sol Peruano (PEN)',
                    'CLP' => 'Peso Chileno (CLP)',
                    'ARS' => 'Peso Argentino (ARS)',
                    'GBP' => 'Libra Esterlina (GBP)',
                    'CAD' => 'Dólar Canadiense (CAD)',
                    'JPY' => 'Yen Japonés (JPY)',
                    'CNY' => 'Yuan Chino (CNY)',
                ]"
                    :value="old('moneda_preferida', $user->moneda_preferida)" required />
            </div>

            <!-- Fecha de Corte -->
            <div class="mt-4">
                <x-floating-input id="fecha_corte_dia" name="fecha_corte_dia" label="Día de Corte Mensual" type="number"
                    min="1" max="31" :value="old('fecha_corte_dia', $user->fecha_corte_dia)" required />
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Día del mes en que se realiza el corte financiero
                    (1-31).</p>
            </div>

            <!-- Zona Horaria -->
            <div class="mt-4">
                <x-floating-select id="zona_horaria" name="zona_horaria" label="Zona Horaria" :options="[
                    'UTC' => 'Coordinated Universal Time (UTC/GMT)',
                    'America/El_Salvador' => 'El Salvador (GMT-6)',
                    'America/Guatemala' => 'Guatemala (GMT-6)',
                    'America/Tegucigalpa' => 'Honduras (GMT-6)',
                    'America/Managua' => 'Nicaragua (GMT-6)',
                    'America/Costa_Rica' => 'Costa Rica (GMT-6)',
                    'America/Mexico_City' => 'Ciudad de México (GMT-6)',
                    'America/Bogota' => 'Bogotá (GMT-5)',
                    'America/Lima' => 'Lima (GMT-5)',
                    'America/Caracas' => 'Caracas (GMT-4)',
                    'America/Santiago' => 'Santiago (GMT-4/-3)',
                    'America/Buenos_Aires' => 'Buenos Aires (GMT-3)',
                    'America/Sao_Paulo' => 'São Paulo (GMT-3)',
                    'America/New_York' => 'Nueva York (GMT-5/-4)',
                    'America/Chicago' => 'Chicago (GMT-6/-5)',
                    'America/Los_Angeles' => 'Los Ángeles (GMT-8/-7)',
                    'Europe/Madrid' => 'Madrid (GMT+1/+2)',
                    'Europe/London' => 'Londres (GMT+0/+1)',
                    'Europe/Paris' => 'París (GMT+1/+2)',
                    'Asia/Tokyo' => 'Tokio (GMT+9)',
                    'Asia/Shanghai' => 'Shanghái (GMT+8)',
                    'Australia/Sydney' => 'Sídney (GMT+10/+11)',
                ]"
                    :value="old('zona_horaria', $user->zona_horaria)" required />
            </div>

            <div class="flex items-center justify-end gap-4 mt-8">
                <x-primary-button class="dark:bg-primary-700 dark:hover:bg-primary-800">
                    {{ __('Actualizar') }}
                </x-primary-button>
            </div>
        </form>
    @elsecan('profile.view')
        <div class="mb-6 text-center mt-4">
            <div>
                <div class="mb-4">
                    <h5>Nombre</h5>
                </div>
                <div class="mb-4">
                    <p>{{ $user->name }}</p>
                </div>
            </div>
            <div class="mb-4">
                <h5>Correo electrónico</h5>
            </div>
            <div class="mb-4">
                <p>{{ $user->email }}</p>
            </div>
            <!-- Moneda Preferida -->
            <div class="mb-4">
                <h5>Moneda Preferida</h5>
            </div>
            <div class="mt-4">
                <p>{{ $user->moneda_preferida }}</p>
            </div>

            <!-- Fecha de Corte -->
            <div class="mb-4">
                <h5>Fecha de Corte</h5>
            </div>
            <div class="mt-4">
                <p>{{ $user->fecha_corte_dia }}</p>
            </div>

            <!-- Zona Horaria -->
            <div class="mb-4">
                <h5>Zona Horaria</h5>
            </div>
            <div class="mt-4">
                <p>{{ $user->zona_horaria }}</p>
            </div>
        </div>
    @endcan
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
            showAlert('success', '¡Enlace enviado!',
                'Se ha enviado un nuevo enlace de verificación a tu correo electrónico.');
        @endif

        //3. Mensaje de contraseña actualizada exitosamente
        @if (session('status') === 'password-updated')
            showAlert('success', '¡Éxito!', 'Contraseña actualizada correctamente.');
        @endif
    });
</script>
