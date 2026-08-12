<form x-data="ajaxForm(function() {
    // Esta función se ejecuta cuando el AJAX es exitoso
    $dispatch('close-modal', 'role-modal'); // Cierra el modal
    showAlert('success', '¡Éxito!', 'Rol guardado correctamente.'); // Muestra alerta
    setTimeout(() => window.location.reload(), 1500); // Recarga la página suavemente para ver los cambios
})" @submit.prevent="submit" method="POST"
    action="{{ isset($role) ? route('admin.roles.update', $role) : route('admin.roles.store') }}" novalidate
    class="space-y-4">
    @csrf
    @if (isset($role))
        @method('PUT')
    @endif

    <!-- Cabecera inyectada con la vista -->
    <div class="flex items-center justify-between mb-6">
        <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
            {{ isset($role) ? __('Editar Rol') : __('Nuevo Rol') }}
        </h2>
        <button type="button" x-on:click="$dispatch('close-modal', 'role-modal')"
            class="text-gray-400 hover:text-gray-500 dark:hover:text-gray-300 transition-colors">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
        </button>
    </div>

    <div class="mb-6">
        <p class="text-sm text-gray-600 dark:text-gray-400">
            Define un rol y asigna los permisos correspondientes.
        </p>
    </div>

    <x-floating-input id="name" name="name" label="Nombre del rol" type="text" :value="isset($role) ? old('name', $role->name) : old('name')"
        :error="$errors->first('name')" required placeholder="Ej: Editor, Contador, Supervisor" />

    <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">Permisos del rol</label>
        <div
            class="grid grid-cols-1 md:grid-cols-2 gap-4 max-h-96 overflow-y-auto p-2 border border-gray-200 dark:border-gray-700 rounded-lg">
            @foreach ($permissions as $module => $modulePermissions)
                <div class="border border-gray-300 dark:border-gray-600 rounded-lg p-4">
                    <h3 class="font-semibold text-gray-900 dark:text-gray-100 mb-3 capitalize">{{ ucfirst($module) }}
                    </h3>
                    <div class="space-y-2">
                        @foreach ($modulePermissions as $permission)
                            <label class="flex items-center cursor-pointer">
                                <input type="checkbox" name="permissions[]" value="{{ $permission->name }}"
                                    {{ in_array($permission->name, old('permissions', isset($role) ? $role->permissions->pluck('name')->toArray() : [])) ? 'checked' : '' }}
                                    class="rounded border-gray-300 dark:border-gray-600 text-primary-600 focus:ring-primary-500">
                                <span
                                    class="ml-2 text-sm text-gray-700 dark:text-gray-300">{{ str_replace('.', ' - ', $permission->name) }}</span>
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

    <div class="mt-6 flex justify-end gap-3">
        <x-secondary-button type="button"
            @click="$dispatch('close-modal', { name: 'role-modal' })">Cancelar</x-secondary-button>
        <x-primary-button type="submit" x-bind:disabled="loading" class="relative">
            <span x-show="!loading">{{ isset($role) ? 'Guardar Cambios' : 'Crear Rol' }}</span>
            <span x-show="loading" class="flex items-center gap-2">
                <svg class="animate-spin h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none"
                    viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor"
                        stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor"
                        d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                    </path>
                </svg>
                Guardando...
            </span>
        </x-primary-button>
    </div>
</form>
