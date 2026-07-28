<x-app-layout>
    <x-slot name="header">
        <h1>{{ __('Editar Rol') }}</h1>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg p-6">
                
                <div class="mb-6">
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                        Editar: {{ $role->name }}
                    </h2>
                    <p class="text-sm text-gray-600 dark:text-gray-400">
                        Modifica el nombre y los permisos asignados a este rol.
                    </p>
                </div>

                <form method="POST" action="{{ route('admin.roles.update', $role) }}" novalidate id="edit-role-form">
                    @csrf
                    @method('PUT')

                    <div class="space-y-4">
                        <x-floating-input 
                            id="name" 
                            name="name" 
                            label="Nombre del rol" 
                            type="text" 
                            :value="old('name', $role->name)" 
                            :error="$errors->first('name')" 
                            required 
                        />

                        <!-- Cuadrícula de Permisos -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">
                                Permisos del rol
                            </label>
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                @foreach($permissions as $module => $modulePermissions)
                                    <div class="border border-gray-300 dark:border-gray-600 rounded-lg p-4">
                                        <h3 class="font-semibold text-gray-900 dark:text-gray-100 mb-3 capitalize">
                                            {{ ucfirst($module) }}
                                        </h3>
                                        <div class="space-y-2">
                                            @foreach($modulePermissions as $permission)
                                                <label class="flex items-center">
                                                    <input 
                                                        type="checkbox" 
                                                        name="permissions[]" 
                                                        value="{{ $permission->name }}"
                                                        {{ in_array($permission->name, old('permissions', $rolePermissions)) ? 'checked' : '' }}
                                                        class="rounded border-gray-300 dark:border-gray-600 text-primary-600 focus:ring-primary-500"
                                                    >
                                                    <span class="ml-2 text-sm text-gray-700 dark:text-gray-300">
                                                        {{ str_replace('.', ' - ', $permission->name) }}
                                                    </span>
                                                </label>
                                            @endforeach
                                        </div>
                                    </div>
                                @endforeach
                            </div>

                            @error('permissions')
                                <p class="mt-2 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>

                    <div class="flex justify-end gap-3 mt-8">
                        <x-secondary-button type="button" onclick="window.location.href='{{ route('admin.roles.index') }}'">
                            Cancelar
                        </x-secondary-button>
                        <x-primary-button type="submit">
                            Guardar Cambios
                        </x-primary-button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('edit-role-form');
            if (!form) return;

            form.addEventListener('submit', function(e) {
                const name = form.querySelector('#name');
                let errors = [];

                if (!name.value.trim()) {
                    errors.push('El nombre del rol es obligatorio.');
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