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
                        <x-floating-input 
                            id="name" 
                            name="name" 
                            label="Nombre completo" 
                            type="text" 
                            :value="old('name')" 
                            :error="$errors->first('name')" 
                            required 
                        />

                        <x-floating-input 
                            id="email" 
                            name="email" 
                            label="Correo electrónico" 
                            type="email" 
                            :value="old('email')" 
                            :error="$errors->first('email')" 
                            required 
                        />

                        <x-floating-input 
                            id="password" 
                            name="password" 
                            label="Contraseña" 
                            type="password" 
                            :error="$errors->first('password')" 
                            required 
                        />
                        <p class="text-xs text-gray-500 dark:text-gray-400 -mt-2">
                            Mínimo 8 caracteres, con mayúscula, minúscula, número y símbolo.
                        </p>

                        <x-floating-input 
                            id="password_confirmation" 
                            name="password_confirmation" 
                            label="Confirmar contraseña" 
                            type="password" 
                            required 
                        />

                        <!-- Selector de Rol -->
                        <div>
                            <label for="role" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                Rol del usuario
                            </label>
                            <select 
                                id="role" 
                                name="role" 
                                class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-primary-500 focus:border-transparent"
                            >
                                <option value="">Selecciona un rol...</option>
                                @foreach($roles as $role)
                                    <option value="{{ $role->name }}" {{ old('role') == $role->name ? 'selected' : '' }}>
                                        {{ $role->name }}
                                    </option>
                                @endforeach
                            </select>
                            @error('role')
                                <p class="mt-2 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>

                    <div class="flex justify-end gap-3 mt-8">
                        <x-secondary-button type="button" onclick="window.location.href='{{ route('admin.usuarios.index') }}'">
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

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('create-user-form');
            if (!form) return;

            form.addEventListener('submit', function(e) {
                const name = form.querySelector('#name');
                const email = form.querySelector('#email');
                const password = form.querySelector('#password');
                const passwordConfirmation = form.querySelector('#password_confirmation');
                const role = form.querySelector('#role');
                let errors = [];

                if (!name.value.trim()) errors.push('El nombre es obligatorio.');
                if (!email.value.trim()) {
                    errors.push('El correo electrónico es obligatorio.');
                } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.value)) {
                    errors.push('El correo electrónico no es válido.');
                }
                if (!password.value) {
                    errors.push('La contraseña es obligatoria.');
                } else if (password.value.length < 8) {
                    errors.push('La contraseña debe tener al menos 8 caracteres.');
                }
                if (password.value !== passwordConfirmation.value) {
                    errors.push('Las contraseñas no coinciden.');
                }
                if (!role.value) {
                    errors.push('Debes seleccionar un rol para el usuario.');
                }

                if (errors.length > 0) {
                    e.preventDefault();
                    showAlert('warning', 'Revisa los siguientes errores', errors.join('\n'));
                    return false;
                }
            });
        });
    </script>
</x-app-layout>