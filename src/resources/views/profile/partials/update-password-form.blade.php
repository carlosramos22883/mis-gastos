<section>
    <header>
        <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
            {{ __('Actualizar Contraseña') }}
        </h2>

        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
            {{ __('Asegúrate de que tu cuenta esté usando una contraseña larga y aleatoria para mantenerla segura.') }}
        </p>
    </header>

    <form method="post" action="{{ route('password.update') }}" class="mt-6 space-y-6">
        @csrf
        @method('put')

        <div class="mb-4">
            <x-floating-input 
                id="update_password_current_password" 
                name="current_password"
                label="Contraseña Actual" 
                type="password"
                :error="$errors->updatePassword->first('current_password')"
                required 
                autocomplete="current-password" 
            />
        </div>
        
        <div class="mb-4">
            <x-floating-input 
                id="update_password_password" 
                name="password"
                label="Nueva Contraseña" 
                type="password" 
                :error="$errors->updatePassword->first('password')"
                required 
                autocomplete="new-password" 
            />
        </div>
        
        <div class="mb-4">
            <x-floating-input 
                id="update_password_password_confirmation" 
                name="password_confirmation"
                label="Confirmar Contraseña" 
                type="password"
                :error="$errors->updatePassword->first('password_confirmation')"
                required 
                autocomplete="new-password" 
            />
        </div>

        <div class="flex items-center gap-4">
            <x-primary-button class="dark:bg-primary-700 dark:hover:bg-primary-800">
                {{ __('Guardar') }}
            </x-primary-button>

            @if (session('status') === 'password-updated')
                <p x-data="{ show: true }" x-show="show" x-transition x-init="setTimeout(() => show = false, 2000)"
                    class="text-sm text-gray-600 dark:text-gray-400">
                    {{ __('Guardado.') }}
                </p>
            @endif
        </div>
    </form>
</section>