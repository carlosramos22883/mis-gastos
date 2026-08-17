<div x-data="imageCropper({
    previewId: 'marca-logo-preview',
    inputId: 'marca-logo-upload',
    cropContainerId: 'marca-crop-container',
    cropModalName: 'crop-marca-logo',
    shape: 'square',
    outputFormat: 'png',
})">

    <form x-data="ajaxForm(() => {
        showAlert('success', '¡Éxito!', '{{ isset($marcaRed) ? 'Marca actualizada.' : 'Marca creada.' }}');
        $dispatch('close-modal', 'marca-red-modal');
        window.dispatchEvent(new CustomEvent('refresh-table'));
    })"
        action="{{ isset($marcaRed) ? route('admin.catalogos.marcas-red.update', $marcaRed) : route('admin.catalogos.marcas-red.store') }}"
        method="POST" enctype="multipart/form-data" @submit.prevent="submit" class="space-y-4">
        @csrf
        @if (isset($marcaRed))
            @method('PUT')
        @endif

        <div class="flex items-center justify-between mb-6">
            <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                {{ isset($marcaRed) ? 'Editar Marca' : 'Nueva Marca de Red' }}
            </h2>
            <button type="button" x-on:click="$dispatch('close-modal', 'marca-red-modal')"
                class="text-gray-400 hover:text-gray-500">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>

        <!-- Logo Preview - AUMENTADO A 256px -->
        <div class="flex justify-center">
            <div class="relative group">
                <img id="marca-logo-preview"
                    src="{{ isset($marcaRed) && $marcaRed->logo ? asset('storage/' . $marcaRed->logo) : 'https://ui-avatars.com/api/?name=M&background=0a0a5e&color=fff&size=256' }}"
                    alt="Logo Marca"
                    class="w-64 h-64 rounded-lg object-cover border-2 border-gray-200 dark:border-gray-600 shadow">

                <label for="marca-logo-upload"
                    class="absolute bottom-2 right-2 bg-primary-600 hover:bg-primary-700 text-white rounded-full p-2 cursor-pointer shadow-md transition-transform group-hover:scale-110">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24"
                        stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z" />
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M15 13a3 3 0 11-6 0 3 3 0 016 0z" />
                    </svg>
                    <input id="marca-logo-upload" name="logo" type="file" accept=".jpg,.jpeg,.png,.gif,.webp,image/jpeg,image/png,image/webp" class="hidden"
                        x-on:change="handleSelect($event)">
                </label>
            </div>
        </div>
        <p class="text-sm text-center text-gray-500 mt-2">Haz clic para subir/recortar logo</p>

        <x-floating-input id="nombre" name="nombre" label="Nombre" type="text" :value="isset($marcaRed) ? old('nombre', $marcaRed->nombre) : old('nombre')"
            :error="$errors->first('nombre')" required placeholder="Ej: Visa" />

        <!-- Checkbox Activo -->
        <div class="flex items-center gap-2">
            @php
                $isChecked = isset($marcaRed) ? $marcaRed->activo : old('activo', 1);
            @endphp
            <input type="hidden" name="activo" value="0">
            <input type="checkbox" name="activo" id="activo" value="1" {{ $isChecked ? 'checked' : '' }}
                class="h-4 w-4 rounded border-gray-300 dark:border-gray-600 text-primary-600 focus:ring-primary-500">
            <label for="activo" class="text-sm font-medium text-gray-700 dark:text-gray-300 cursor-pointer">
                Activa
            </label>
        </div>

        <div class="mt-6 flex justify-end gap-3">
            <x-secondary-button type="button"
                @click="$dispatch('close-modal', 'marca-red-modal')">Cancelar</x-secondary-button>
            <x-primary-button type="submit" x-bind:disabled="loading" class="relative">
                <span x-show="!loading">{{ isset($marcaRed) ? 'Guardar' : 'Crear' }}</span>
                <span x-show="loading" class="flex items-center gap-2">
                    <svg class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none"
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

    <!-- Modal Croppie -->
    <x-modal name="crop-marca-logo" :show="false">
        <div class="p-6">
            <h3 class="text-lg font-semibold mb-4">Recortar Logo</h3>
            <div id="marca-crop-container" class="mb-4"></div>
            <div class="flex justify-end gap-3">
                <x-secondary-button type="button" x-on:click="cancelCrop()">Cancelar</x-secondary-button>
                <x-primary-button type="button" x-on:click="saveCrop()">Guardar Recorte</x-primary-button>
            </div>
        </div>
    </x-modal>
</div>