<x-app-layout>
    <x-slot name="header">
        <h1>
            {{ __('Perfil de ') . Auth::user()->name }}
        </h1>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8 space-y-8">
            <div class="flex justify-center">
                <!-- 1. SECCIÓN DE FOTO DE PERFIL -->
                <div class="w-full max-w-md bg-white dark:bg-gray-800 shadow sm:rounded-lg p-6">
                    <header class="mb-6 text-center">
                        <h2>                        
                            {{ __('Foto de Perfil') }}
                        </h2>
                        <p>
                            {{ __('Haz clic en el icono de la cámara para cambiar tu foto.') }}
                        </p>
                    </header>

                    <form id="avatar-form" method="POST" action="{{ route('profile.avatar.update') }}" enctype="multipart/form-data" class="space-y-6">
                        @csrf
                        @method('patch')

                        <div class="flex justify-center">
                            <div class="relative group">
                                <img id="avatar-preview"
                                    src="{{ Auth::user()->avatar ? asset('storage/' . Auth::user()->avatar) : 'https://ui-avatars.com/api/?name=' . urlencode(Auth::user()->name) . '&background=0a0a5e&color=fff&size=256' }}"
                                    alt="Avatar"
                                    class="w-32 h-32 rounded-full object-cover border-4 border-gray-200 dark:border-gray-600 shadow-lg">

                                <!-- Icono de cámara -->
                                <label for="avatar-upload"
                                    title="Haga clic para cambiar foto de perfil"
                                    class="absolute bottom-0 right-0 bg-primary-600 dark:bg-primary-500 hover:bg-primary-700 dark:hover:bg-primary-400 text-white rounded-full p-2 cursor-pointer shadow-md transition-all transform group-hover:scale-110 border-2 border-white dark:border-gray-800">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z" />
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z" />
                                    </svg>
                                    <input id="avatar-upload" name="avatar" type="file" accept=".jpg,.jpeg,.png,.gif,.webp,image/jpeg,image/png,image/webp" class="hidden">
                                </label>
                            </div>
                        </div>

                        <x-input-error :messages="$errors->get('avatar')" class="mt-2 text-center" />
                        
                    </form>
                

            <!-- 2. INFORMACIÓN DEL PERFIL -->
            
                
                    @include('profile.partials.update-profile-information-form')
                </div>
            </div>

            <!-- 3. BOTONES DE ACCIÓN -->
            <div class="flex justify-center gap-4">
                <x-secondary-button x-data="" x-on:click.prevent="$dispatch('open-modal', 'confirm-password-update')">
                    {{ __('Actualizar Contraseña') }}
                </x-secondary-button>

                <x-danger-button x-data="" x-on:click.prevent="$dispatch('open-modal', 'confirm-user-deletion')">
                    {{ __('Eliminar Cuenta') }}
                </x-danger-button>
            </div>

        </div>
    </div>

    <!-- MODAL: Actualizar Contraseña -->
    <x-modal name="confirm-password-update" :show="$errors->updatePassword->isNotEmpty()" focusable>
        <form method="post" action="{{ route('password.update') }}" class="p-6">
            @csrf
            @method('put')

            <div class="flex items-center justify-between mb-6">
                <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                    {{ __('Actualizar Contraseña') }}
                </h2>
                <button type="button" x-on:click="$dispatch('close')" class="text-gray-400 hover:text-gray-500 dark:hover:text-gray-300">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            <div class="space-y-4">
                <x-floating-input id="update_password_current_password" name="current_password" label="Contraseña Actual" type="password" :error="$errors->updatePassword->first('current_password')" required />
                <x-floating-input id="update_password_password" name="password" label="Nueva Contraseña" type="password" :error="$errors->updatePassword->first('password')" required />
                <x-floating-input id="update_password_password_confirmation" name="password_confirmation" label="Confirmar Contraseña" type="password" :error="$errors->updatePassword->first('password_confirmation')" required />
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <x-secondary-button type="button" x-on:click="$dispatch('close')">
                    {{ __('Cancelar') }}
                </x-secondary-button>
                <x-primary-button>
                    {{ __('Guardar') }}
                </x-primary-button>
            </div>
        </form>
    </x-modal>

    <!-- MODAL: Eliminar Cuenta -->
    <x-modal name="confirm-user-deletion" :show="$errors->userDeletion->isNotEmpty()" focusable>
        <form method="post" action="{{ route('profile.destroy') }}" class="p-6">
            @csrf
            @method('delete')

            <div class="flex items-center justify-between mb-6">
                <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                    {{ __('¿Estás seguro de eliminar tu cuenta?') }}
                </h2>
                <button type="button" x-on:click="$dispatch('close')" class="text-gray-400 hover:text-gray-500 dark:hover:text-gray-300">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            <div class="mb-4">
                <x-floating-input id="password" name="password" label="Contraseña" type="password" :error="$errors->userDeletion->first('password')" required />
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <x-secondary-button type="button" x-on:click="$dispatch('close')">
                    {{ __('Cancelar') }}
                </x-secondary-button>
                <x-danger-button type="submit">
                    {{ __('Eliminar Cuenta') }}
                </x-danger-button>
            </div>
        </form>
    </x-modal>

    <!-- MODAL: Croppie (Recortar imagen) - USANDO EL MISMO COMPONENTE GENÉRICO -->
    <x-modal name="crop-image-modal" :show="false">
        <div class="p-6">
            <div class="flex items-center justify-between mb-6">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                    Recortar Imagen de Perfil
                </h3>
                <button type="button" id="close-modal" class="text-gray-400 hover:text-gray-500 dark:hover:text-gray-300">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            <div id="crop-image-container" class="mb-6"></div>

            <div class="flex justify-end gap-3">
                <x-secondary-button id="cancel-crop" type="button">
                    Cancelar
                </x-secondary-button>
                <x-primary-button id="save-crop" type="button">
                    Guardar y Recortar
                </x-primary-button>
            </div>
        </div>
    </x-modal>

</x-app-layout>