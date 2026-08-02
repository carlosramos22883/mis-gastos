<x-app-layout>
    <x-slot name="header">
        <h1>{{ __('Crear Nuevo Usuario') }}</h1>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg p-6">

                <div class="mb-6">
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                        Información del Usuario
                    </h2>
                    <p class="text-sm text-gray-600 dark:text-gray-400">
                        Completa los datos para crear un nuevo usuario en el sistema.
                    </p>
                </div>

                <form method="POST" action="{{ route('admin.usuarios.store') }}" novalidate id="create-user-form">
                    @csrf

                    <div class="space-y-4">
                        <x-floating-input id="name" name="name" label="Nombre completo" type="text"
                            :value="old('name')" :error="$errors->first('name')" required />

                        <x-floating-input id="email" name="email" label="Correo electrónico" type="email"
                            :value="old('email')" :error="$errors->first('email')" required />

                        <x-floating-input id="password" name="password" label="Contraseña" type="password"
                            :error="$errors->first('password')" required />
                        <p class="text-xs text-gray-500 dark:text-gray-400 -mt-2">
                            Mínimo 8 caracteres, con mayúscula, minúscula, número y símbolo.
                        </p>

                        <x-floating-input id="password_confirmation" name="password_confirmation"
                            label="Confirmar contraseña" type="password" required />

                        <!-- Selector de Rol -->
                        <div>
                            <x-floating-select id="role" name="role" label="Rol del usuario" :options="$roles->pluck('name', 'name')->toArray()"
                                :value="old('role')" :error="$errors->first('role')" required :searchable="true"/>
                        </div>
                    </div>

                    <div class="flex justify-end gap-3 mt-8">
                        <x-secondary-button type="button"
                            onclick="window.location.href='{{ route('admin.usuarios.index') }}'">
                            Cancelar
                        </x-secondary-button>
                        <x-primary-button type="submit">
                            Crear Usuario
                        </x-primary-button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
