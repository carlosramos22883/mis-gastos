<form x-data="ajaxForm(function(data) { $dispatch('close-modal', 'efectivo-modal'); showAlert('success', '¡Éxito!', data.message); window.dispatchEvent(new CustomEvent('refresh-table', { detail: data })); })" @submit.prevent="submit" method="POST" action="{{ isset($movimiento) ? route('efectivo.update',$movimiento) : route('efectivo.store') }}" class="space-y-4" novalidate>
    @csrf @isset($movimiento) @method('PUT') @endisset
    <div class="flex items-center justify-between mb-6">
        <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">{{ isset($movimiento) ? 'Editar Movimiento de Efectivo' : 'Nuevo Movimiento de Efectivo' }}</h2>
        <button type="button" x-on:click="$dispatch('close-modal', 'efectivo-modal')" class="text-gray-400 hover:text-gray-500">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
        </button>
    </div>
    <x-floating-input id="descripcion" label="Descripción" :value="$movimiento->descripcion ?? ''" :error="$errors->first('descripcion')" required />
    <x-floating-money id="monto" label="Monto" :value="$movimiento->monto ?? ''" :error="$errors->first('monto')" :symbol="auth()->user()->monedaPreferida?->simbolo ?? '$'" required />
    <x-floating-date id="fecha" label="Fecha" :value="isset($movimiento) ? $movimiento->fecha : now()" :min="$cycleStart" :max="$cycleEnd" :error="$errors->first('fecha')" required />
    <x-floating-select id="tipo" label="Tipo" :options="['ingreso'=>'Ingreso','egreso'=>'Egreso']" :value="$movimiento->tipo ?? 'egreso'" :error="$errors->first('tipo')" required />
    <x-floating-select id="categoria_personal_id" label="Categoría" :options="$categorias->pluck('nombre','id')->all()" :value="$movimiento->categoria_personal_id ?? ''" :error="$errors->first('categoria_personal_id')" required />
    <div class="mt-6 flex justify-end gap-3">
        <x-secondary-button type="button" @click="$dispatch('close-modal', 'efectivo-modal')">Cancelar</x-secondary-button>
        <x-primary-button type="submit" x-bind:disabled="loading">Guardar</x-primary-button>
    </div>
</form>
