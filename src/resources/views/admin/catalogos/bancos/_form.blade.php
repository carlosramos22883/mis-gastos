<div x-data="imageCropper({
        previewId: 'logo-preview',
        inputId: 'logo-upload',
        cropContainerId: 'banco-crop-container',
        cropModalName: 'crop-banco-logo',
        shape: 'square',
    })">

    <form x-data="ajaxForm(function() {
        $dispatch('close-modal', 'banco-modal');
        showAlert('success', '¡Éxito!', 'Banco guardado correctamente.');
        window.dispatchEvent(new CustomEvent('refresh-table'));
    })" @submit.prevent="submit"
        action="{{ isset($banco) ? route('admin.catalogos.bancos.update', $banco) : route('admin.catalogos.bancos.store') }}"
        method="POST" enctype="multipart/form-data" novalidate class="space-y-4">
        @csrf
        @if (isset($banco)) @method('PUT') @endif

        <div class="flex items-center justify-between mb-6">
            <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                {{ isset($banco) ? 'Editar Banco' : 'Nuevo Banco' }}
            </h2>
            <button type="button" x-on:click="$dispatch('close-modal', 'banco-modal')"
                class="text-gray-400 hover:text-gray-500">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>

        <!-- Logo Preview - AUMENTADO A 256px -->
        <div class="flex justify-center">
            <div class="relative group">
                <img id="logo-preview"
                    src="{{ isset($banco) && $banco->logo ? asset('storage/' . $banco->logo) : 'https://ui-avatars.com/api/?name=B&background=0a0a5e&color=fff&size=256' }}"
                    alt="Logo Banco"
                    class="w-64 h-64 rounded-lg object-cover border-2 border-gray-200 dark:border-gray-600 shadow">

                <label for="logo-upload"
                    class="absolute bottom-0 right-0 bg-primary-600 hover:bg-primary-700 text-white rounded-full p-1.5 cursor-pointer shadow-md transition-transform group-hover:scale-110">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z" />
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z" />
                    </svg>
                    <input id="logo-upload" name="logo" type="file" accept=".jpg,.jpeg,.png,.gif,.webp,image/jpeg,image/png,image/webp" class="hidden"
                        x-on:change="handleSelect($event)">
                </label>
            </div>
        </div>
        <p class="text-xs text-center text-gray-500">Haz clic en el ícono para subir/recortar logo</p>

        <x-floating-input id="nombre" name="nombre" label="Nombre del Banco" type="text"
            :value="isset($banco) ? old('nombre', $banco->nombre) : old('nombre')"
            :error="$errors->first('nombre')" required placeholder="Ej: Banco Industrial" />

        <!-- Checkbox Activo -->
        <div class="flex items-center gap-2">
            @php
                $isChecked = isset($banco) ? $banco->activo : old('activo', 1);
            @endphp
            <input type="hidden" name="activo" value="0">
            <input type="checkbox" name="activo" id="activo" value="1"
                {{ $isChecked ? 'checked' : '' }}
                class="h-4 w-4 rounded border-gray-300 dark:border-gray-600 text-primary-600 focus:ring-primary-500">
            <label for="activo" class="text-sm font-medium text-gray-700 dark:text-gray-300 cursor-pointer">
                Activo
            </label>
        </div>

        <div class="mt-6 flex justify-end gap-3">
            <x-secondary-button type="button" @click="$dispatch('close-modal', 'banco-modal')">Cancelar</x-secondary-button>
            <x-primary-button type="submit" x-bind:disabled="loading" class="relative">
                <span x-show="!loading">{{ isset($banco) ? 'Guardar Cambios' : 'Crear Banco' }}</span>
                <span x-show="loading" class="flex items-center gap-2">
                    <svg class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    Guardando...
                </span>
            </x-primary-button>
        </div>
    </form>

    <!-- Modal de Croppie -->
    <x-modal name="crop-banco-logo" :show="false">
        <div class="p-6">
            <h3 class="text-lg font-semibold mb-4">Recortar Logo del Banco</h3>
            <div id="banco-crop-container" class="mb-4"></div>
            <div class="flex justify-end gap-3">
                <x-secondary-button type="button" x-on:click="cancelCrop()">Cancelar</x-secondary-button>
                <x-primary-button type="button" x-on:click="saveCrop()">Guardar Recorte</x-primary-button>
            </div>
        </div>
    </x-modal>
</div>