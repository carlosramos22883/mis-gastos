<form x-data="ajaxForm(function() {
    $dispatch('close-modal', 'tipo-cuenta-modal');
    showAlert('success', '¡Éxito!', 'Tipo de cuenta guardado correctamente.');
    window.dispatchEvent(new CustomEvent('refresh-table'));
})" @submit.prevent="submit"
    action="{{ isset($tipoCuenta) ? route('admin.catalogos.tipos-cuenta.update', $tipoCuenta) : route('admin.catalogos.tipos-cuenta.store') }}"
    method="POST" novalidate class="space-y-4">
    @csrf
    @if (isset($tipoCuenta)) @method('PUT') @endif

    <div class="flex items-center justify-between mb-6">
        <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">{{ isset($tipoCuenta) ? 'Editar Tipo de Cuenta' : 'Nuevo Tipo de Cuenta' }}</h2>
        <button type="button" x-on:click="$dispatch('close-modal', 'tipo-cuenta-modal')" class="text-gray-400 hover:text-gray-500">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
        </button>
    </div>

    <x-floating-input id="nombre" name="nombre" label="Nombre" type="text" :value="isset($tipoCuenta) ? old('nombre', $tipoCuenta->nombre) : old('nombre')" :error="$errors->first('nombre')" required placeholder="Ej: Cuenta de Ahorro" />
    <x-floating-input id="descripcion" name="descripcion" label="Descripción (Opcional)" type="text" :value="isset($tipoCuenta) ? old('descripcion', $tipoCuenta->descripcion) : old('descripcion')" :error="$errors->first('descripcion')" placeholder="Ej: Cuenta que genera intereses" />

    <!-- Checkbox Activo (Marcado por defecto al crear) -->
    <div class="flex items-center gap-2 mt-6">
        @php
            // Si estamos editando, usa el valor de la BD. Si estamos creando, usa '1' (true) por defecto, a menos que old() diga lo contrario.
            $isChecked = isset($tipoCuenta) ? $tipoCuenta->activo : old('activo', 1);
        @endphp
        <input type="checkbox" name="activo" id="activo" value="1" 
            {{ $isChecked ? 'checked' : '' }}
            class="h-4 w-4 rounded border-gray-300 dark:border-gray-600 text-primary-600 focus:ring-primary-500 dark:bg-gray-700">
        <label for="activo" class="text-sm font-medium text-gray-700 dark:text-gray-300 cursor-pointer">
            Marca Activa
        </label>
    </div>

    <div class="mt-6 flex justify-end gap-3">
        <x-secondary-button type="button" @click="$dispatch('close-modal', 'tipo-cuenta-modal')">Cancelar</x-secondary-button>
        <x-primary-button type="submit" x-bind:disabled="loading" class="relative">
            <span x-show="!loading">{{ isset($tipoCuenta) ? 'Guardar Cambios' : 'Crear Tipo de Cuenta' }}</span>
            <span x-show="loading" class="flex items-center gap-2">
                <svg class="animate-spin h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                Guardando...
            </span>
        </x-primary-button>
    </div>
</form>