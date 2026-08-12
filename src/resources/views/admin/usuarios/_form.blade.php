<form x-data="ajaxForm(function() {
    $dispatch('close-modal', 'user-modal');
    showAlert('success', '¡Éxito!', 'Usuario guardado correctamente.');
    setTimeout(() => window.location.reload(), 1000);
})" @submit.prevent="submit"
    action="{{ isset($usuario) ? route('admin.usuarios.update', $usuario) : route('admin.usuarios.store') }}"
    method="POST" novalidate class="space-y-4">
    @csrf
    @if (isset($usuario))
        @method('PUT')
    @endif

    <!-- Cabecera inyectada con la vista -->
    <div class="flex items-center justify-between mb-6">
        <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
            {{ isset($usuario) ? __('Editar Usuario') : __('Nuevo Usuario') }}
        </h2>
        <button type="button" x-on:click="$dispatch('close-modal', 'user-modal')"
            class="text-gray-400 hover:text-gray-500 dark:hover:text-gray-300 transition-colors">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
        </button>
    </div>

    <div class="mb-6">
        <p class="text-sm text-gray-600 dark:text-gray-400">
            Completa los datos para crear un nuevo usuario en el sistema.
        </p>
    </div>

    <x-floating-input id="name" name="name" label="Nombre completo" type="text" :value="isset($usuario) ? old('name', $usuario->name) : old('name')"
        :error="$errors->first('name')" required />

    <x-floating-input id="email" name="email" label="Correo electrónico" type="email" :value="isset($usuario) ? old('email', $usuario->email) : old('email')"
        :error="$errors->first('email')" required />

    <x-floating-input id="password" name="password"
        label="{{ isset($usuario) ? 'Nueva contraseña (opcional)' : 'Contraseña' }}" type="password" :error="$errors->first('password')"
        :required="!isset($usuario)" />

    @if (!isset($usuario))
        <p class="text-xs text-gray-500 dark:text-gray-400 -mt-2">Mínimo 8 caracteres, con mayúscula, minúscula, número
            y símbolo.</p>
        <x-floating-input id="password_confirmation" name="password_confirmation" label="Confirmar contraseña"
            type="password" required />
    @else
        <p class="text-xs text-gray-500 dark:text-gray-400 -mt-2">Déjalo vacío para mantener la contraseña actual.</p>
        <x-floating-input id="password_confirmation" name="password_confirmation" label="Confirmar nueva contraseña"
            type="password" />
    @endif

    <x-floating-select id="role" name="role" label="Rol del usuario" :options="$roles->pluck('name', 'name')->toArray()" :value="isset($usuario) ? old('role', $usuario->roles->first()?->name) : old('role')"
        :error="$errors->first('role')" required :searchable="true" />

    <div class="mt-6 flex justify-end gap-3">
        <x-secondary-button type="button" @click="$dispatch('close-modal', 'user-modal')">
            Cancelar
        </x-secondary-button>
        <x-primary-button type="submit" x-bind:disabled="loading" class="relative">
            <span x-show="!loading">{{ isset($usuario) ? 'Guardar Cambios' : 'Crear Usuario' }}</span>
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
