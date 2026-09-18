<form x-data="ajaxForm(function(data) { $dispatch('close-modal', 'categoria-modal'); showAlert('success', '¡Éxito!', data.message); window.dispatchEvent(new CustomEvent('refresh-table', { detail: data })); })" @submit.prevent="submit" method="POST" action="{{ isset($categoria) ? route('categorias.update',$categoria) : route('categorias.store') }}" class="space-y-4" novalidate>
    @csrf @isset($categoria) @method('PUT') @endisset
    <div class="flex items-center justify-between mb-6">
        <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">{{ isset($categoria) ? 'Editar Categoría' : 'Nueva Categoría' }}</h2>
        <button type="button" x-on:click="$dispatch('close-modal', 'categoria-modal')" class="text-gray-400 hover:text-gray-500">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
        </button>
    </div>
    <x-floating-input id="nombre" label="Nombre" :value="$categoria->nombre ?? ''" :error="$errors->first('nombre')" required />
    <x-floating-select id="tipo" label="Tipo" :options="['ingreso'=>'Ingreso','egreso'=>'Egreso']" :value="$categoria->tipo ?? 'egreso'" :error="$errors->first('tipo')" required />
    <div class="mt-4">
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Color de la categoría</label>
        <div class="flex items-center gap-3">
            <input type="color" id="color_picker" value="{{ old('color', $categoria->color ?? '#64748B') }}"
                class="h-10 w-14 rounded-lg cursor-pointer border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 p-1"
                x-on:input="document.getElementById('color').value = $el.value">
            <div class="flex-1">
                <x-floating-input id="color" name="color" label="Código Hexadecimal" type="text"
                    :value="old('color', $categoria->color ?? '#64748B')" :error="$errors->first('color')" maxlength="7"
                    x-on:input="document.getElementById('color_picker').value = $el.value" required />
            </div>
        </div>
        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Selecciona un color o escribe su código hexadecimal.</p>
    </div>
    <label class="flex gap-2 items-center"><input type="checkbox" name="activo" value="1" @checked($categoria->activo ?? true)> Activa</label>
    @error('activo')<p class="text-red-600 text-sm">{{ $message }}</p>@enderror
    <div class="mt-6 flex justify-end gap-3">
        <x-secondary-button type="button" @click="$dispatch('close-modal', 'categoria-modal')">Cancelar</x-secondary-button>
        <x-primary-button type="submit" x-bind:disabled="loading">Guardar</x-primary-button>
    </div>
</form>
