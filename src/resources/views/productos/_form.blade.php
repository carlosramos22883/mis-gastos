@php($editing = isset($producto))
<div x-data="imageCropper({ previewId: 'producto-foto-preview', inputId: 'producto-foto-upload', cropContainerId: 'producto-crop-container', cropModalName: 'crop-producto-foto', shape: 'square', viewportSize: 220, outputSize: 600 })">
<form x-data="ajaxForm(async function(data) { $dispatch('close-modal', 'producto-modal'); await showAlert('success', '¡Listo!', data.message); window.dispatchEvent(new CustomEvent('refresh-table', { detail: data })); })"
    @submit.prevent="submit" method="POST" action="{{ $editing ? route('productos.update', $producto) : route('productos.store') }}" enctype="multipart/form-data" class="space-y-4" novalidate>
    @csrf @if ($editing) @method('PUT') @endif
    <div class="flex items-center justify-between">
        <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">{{ $editing ? 'Editar producto' : 'Nuevo producto' }}</h2>
        <button type="button" x-on:click="$dispatch('close-modal', 'producto-modal')" class="text-gray-400 hover:text-gray-500" aria-label="Cerrar">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
        </button>
    </div>
    <div class="flex items-center gap-4">
        <div class="relative group shrink-0">
            <img id="producto-foto-preview" alt=""
                src="{{ $editing && $producto->foto ? $producto->urlFoto() : 'data:image/svg+xml;utf8,'.rawurlencode('<svg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 24 24\' fill=\'none\' stroke=\'#9ca3af\' stroke-width=\'1.5\'><rect width=\'24\' height=\'24\' fill=\'#f3f4f6\' stroke=\'none\'/><path d=\'M6 7h12l-1 13H7L6 7zM9 7V5a3 3 0 016 0v2\'/></svg>') }}"
                class="h-24 w-24 rounded-lg object-cover ring-1 ring-black/10">
            <label for="producto-foto-upload" title="Elegir y recortar foto"
                class="absolute -bottom-1 -right-1 cursor-pointer rounded-full bg-primary-600 p-1.5 text-white shadow-md transition-transform hover:bg-primary-700 group-hover:scale-110">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
                <input id="producto-foto-upload" name="foto" type="file" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" class="hidden" x-on:change="handleSelect($event)">
            </label>
        </div>
        <div class="space-y-1 text-sm">
            <p class="text-gray-700 dark:text-gray-300">Toca la cámara para tomarle una foto al producto o elegirla.</p>
            @if ($editing && $producto->foto)
                <x-checkbox name="quitar_foto" peligro>Quitar foto</x-checkbox>
            @endif
            <p class="text-xs text-gray-500 dark:text-gray-400">Así lo reconoces rápido en el estante.</p>
        </div>
    </div>

    <div class="grid gap-4 sm:grid-cols-2">
        <x-floating-input id="nombre" label="Producto (ej. Atún en trocitos)" maxlength="100" :value="$producto->nombre ?? ''" required />
        <x-floating-input id="marca" label="Marca (opcional)" maxlength="80" :value="$producto->marca ?? ''" />
    </div>
    <div class="grid gap-4 sm:grid-cols-3">
        <x-floating-input id="cantidad" type="text" inputmode="decimal" label="Presentación: cantidad" maxlength="12"
            :value="isset($producto) && $producto->cantidad !== null ? rtrim(rtrim((string) $producto->cantidad, '0'), '.') : ''" />
        <x-floating-select id="unidad" label="Unidad" :options="\App\Models\Producto::UNIDADES" :value="$producto->unidad ?? ''" />
        <x-floating-input id="codigo_barras" type="text" inputmode="numeric" label="Código de barras (opcional)" maxlength="20" :value="$producto->codigo_barras ?? ''" />
    </div>
    <div class="grid gap-4 sm:grid-cols-3">
        <x-floating-select id="categoria_despensa_id" label="Categoría" :allowEmpty="false" :options="\App\Models\Catalogos\CategoriaDespensa::opciones($producto->categoria_despensa_id ?? null)"
            :value="$producto->categoria_despensa_id ?? \App\Models\Catalogos\CategoriaDespensa::predeterminado()" required />
        <x-floating-select id="importancia" label="Importancia" :allowEmpty="false" :options="\App\Models\Producto::IMPORTANCIAS" :value="$producto->importancia ?? 'basico'" required />
        <x-floating-select id="duracion" label="Duración" :allowEmpty="false" :options="\App\Models\Producto::DURACIONES" :value="$producto->duracion ?? 'duradero'" required />
    </div>
    <div class="grid gap-4 sm:grid-cols-2">
        <x-floating-input id="en_casa" type="number" min="0" max="999" inputmode="numeric" label="Cuántos hay en casa ahora (opcional)" :value="$producto->en_casa ?? ''" />
        <div x-data="{ perecedero: {{ ($producto->duracion ?? 'duradero') === 'perecedero' ? 'true' : 'false' }} }" x-init="document.getElementById('duracion').addEventListener('change', (e) => perecedero = e.target.value === 'perecedero')">
            <div x-show="perecedero" x-cloak>
                <x-floating-date id="vence_el" label="Vence el (opcional)" :value="isset($producto) ? $producto->vence_el?->toDateString() : ''" :max="now()->addYears(5)" />
            </div>
            <p x-show="!perecedero" class="pt-3 text-xs text-gray-500 dark:text-gray-400">Los productos duraderos no llevan fecha de vencimiento.</p>
        </div>
    </div>
    <div class="grid gap-4 sm:grid-cols-2">
        <x-floating-input id="cantidad_ideal" type="number" min="1" max="999" inputmode="numeric" label="Cuántos tener en casa (opcional)" :value="$producto->cantidad_ideal ?? ''" />
        <x-floating-input id="avisar_cuando" type="number" min="0" max="999" inputmode="numeric" label="Avisar cuando queden (opcional)" :value="$producto->avisar_cuando ?? ''" />
    </div>
    <x-checkbox name="acepta_otra_marca" sinMarcar="0" :checked="$producto->acepta_otra_marca ?? false"
        ayuda="Al comparar precios se te puede sugerir otra marca si está más barata.">Acepto otra marca si es más barata</x-checkbox>
    <x-floating-input id="notas" label="Notas (opcional, ej. el de agua, no el de aceite)" maxlength="1000" :value="$producto->notas ?? ''" />
    @if ($editing)
        <x-checkbox name="activo" sinMarcar="0" :checked="$producto->activo" ayuda="Desmárcalo si ya no lo compras: sale de la despensa sin perder su historial.">Lo sigo comprando</x-checkbox>
    @endif
    <div class="flex justify-end gap-3">
        <x-secondary-button type="button" x-on:click="$dispatch('close-modal', 'producto-modal')">Cancelar</x-secondary-button>
        <x-primary-button type="submit" x-bind:disabled="loading">Guardar</x-primary-button>
    </div>
</form>

{{-- Al nivel de la página (x-teleport): dentro de la ventana del producto quedaba recortado --}}
<template x-teleport="body">
    <x-modal name="crop-producto-foto" :show="false" maxWidth="md">
        <div class="p-5">
            <h3 class="mb-3 text-lg font-semibold text-gray-900 dark:text-gray-100">Recortar foto</h3>
            <p class="mb-3 text-xs text-gray-500 dark:text-gray-400">Arrastra la imagen y usa la barra para acercar o alejar.</p>
            <div id="producto-crop-container" class="mb-2"></div>
            <div class="flex justify-end gap-3">
                <x-secondary-button type="button" x-on:click="cancelCrop()">Cancelar</x-secondary-button>
                <x-primary-button type="button" x-on:click="saveCrop()">Usar este recorte</x-primary-button>
            </div>
        </div>
    </x-modal>
</template>
</div>
