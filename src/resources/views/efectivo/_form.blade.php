<form x-data="ajaxForm(async function(data) { $dispatch('close-modal', 'efectivo-modal'); await showAlert('success', '¡Éxito!', data.message); window.dispatchEvent(new CustomEvent('refresh-table', { detail: data })); })" x-init="const filterCategories = value => { const select = window.tomSelects?.categoria_personal_id; if (!select) return; const selected = select.getValue(); const types = @js($categorias->mapWithKeys(fn ($categoria) => [$categoria->id => $categoria->tipo])); const labels = Object.fromEntries([...document.querySelectorAll('#categoria_personal_id option')].map(option => [option.value, option.textContent.trim()])); select.clear(true); select.clearOptions(); select.addOptions(Object.entries(types).filter(([, type]) => type === value).map(([key]) => ({ value: key, text: labels[key] || '' }))); if (types[selected] === value) select.setValue(selected, true); select.refreshOptions(false); }; window.addEventListener('floating-select-change', event => { if (event.detail.id === 'tipo') filterCategories(event.detail.value); }); setTimeout(() => filterCategories('{{ $movimiento->tipo ?? 'egreso' }}'), 100);" @submit.prevent="submit" method="POST" action="{{ isset($movimiento) ? route('efectivo.update',$movimiento) : route('efectivo.store') }}" class="space-y-4" novalidate>
    @csrf @isset($movimiento) @method('PUT') @endisset
    @isset($compromiso)
        <input type="hidden" name="compromiso_id" value="{{ $compromiso->id }}">
    @endisset
    <div class="flex items-center justify-between mb-6">
        <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">{{ isset($movimiento) ? 'Editar Movimiento de Efectivo' : 'Nuevo Movimiento de Efectivo' }}</h2>
        <button type="button" x-on:click="$dispatch('close-modal', 'efectivo-modal')" class="text-gray-400 hover:text-gray-500">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
        </button>
    </div>
    <x-floating-input id="descripcion" label="Descripción" maxlength="255" :value="$movimiento->descripcion ?? ($compromiso->descripcion ?? '')" :error="$errors->first('descripcion')" required />
    <x-floating-money id="monto" label="Monto" :value="$movimiento->monto ?? ''" :error="$errors->first('monto')" :symbol="auth()->user()->monedaPreferida?->simbolo ?? '$'" required />
    <x-floating-date id="fecha" label="Fecha" :value="isset($movimiento) ? $movimiento->fecha : now()" :min="$cycleStart" :max="$cycleEnd" :error="$errors->first('fecha')" required />
    <x-floating-select id="tipo" label="Tipo" :options="['ingreso'=>'Ingreso','egreso'=>'Egreso']" :value="$movimiento->tipo ?? 'egreso'" :error="$errors->first('tipo')" required />
    <x-floating-select id="categoria_personal_id" label="Categoría" :options="$categorias->pluck('nombre','id')->all()" :value="$movimiento->categoria_personal_id ?? ''" :error="$errors->first('categoria_personal_id')" required />
    <div class="mt-6 flex justify-end gap-3">
        <x-secondary-button type="button" @click="$dispatch('close-modal', 'efectivo-modal')">Cancelar</x-secondary-button>
        <x-primary-button type="submit" x-bind:disabled="loading">Guardar</x-primary-button>
    </div>
</form>
