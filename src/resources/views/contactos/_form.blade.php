@php($editing = isset($contacto))
<div x-data="imageCropper({ previewId: 'contacto-foto-preview', inputId: 'contacto-foto-upload', cropContainerId: 'contacto-crop-container', cropModalName: 'crop-contacto-foto', shape: 'circle', viewportSize: 200, outputSize: 512 })">
<form x-data="ajaxForm(async function(data) { $dispatch('close-modal', 'contacto-modal'); await showAlert('success', '¡Éxito!', data.message); window.dispatchEvent(new CustomEvent('refresh-table', { detail: data })); })"
    @submit.prevent="submit" method="POST" action="{{ $editing ? route('contactos.update', $contacto) : route('contactos.store') }}" enctype="multipart/form-data" class="space-y-4" novalidate>
    @csrf @if ($editing) @method('PUT') @endif
    <div class="flex items-center justify-between">
        <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">{{ $editing ? 'Editar contacto' : 'Nuevo contacto' }}</h2>
        <button type="button" x-on:click="$dispatch('close-modal', 'contacto-modal')" class="text-gray-400 hover:text-gray-500">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
        </button>
    </div>
    <div class="flex items-center gap-4">
        <div class="relative group shrink-0">
            <img id="contacto-foto-preview" alt=""
                src="{{ $editing && $contacto->foto ? $contacto->urlFoto() : 'data:image/svg+xml;utf8,'.rawurlencode('<svg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 24 24\' fill=\'none\' stroke=\'#9ca3af\' stroke-width=\'1.5\'><rect width=\'24\' height=\'24\' fill=\'#f3f4f6\' stroke=\'none\'/><path d=\'M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z\'/></svg>') }}"
                class="h-24 w-24 rounded-full object-cover ring-1 ring-black/10">
            <label for="contacto-foto-upload" title="Elegir y recortar foto"
                class="absolute bottom-0 right-0 cursor-pointer rounded-full bg-primary-600 p-1.5 text-white shadow-md transition-transform hover:bg-primary-700 group-hover:scale-110">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
                <input id="contacto-foto-upload" name="foto" type="file" accept=".jpg,.jpeg,.png,.gif,.webp,image/jpeg,image/png,image/webp" class="hidden" x-on:change="handleSelect($event)">
            </label>
        </div>
        <div class="space-y-1 text-sm">
            <p class="text-gray-700 dark:text-gray-300">Toca la cámara para elegir una foto o logo y recortarla.</p>
            @if ($editing && $contacto->foto)
                <label class="flex items-center gap-2 text-gray-600 dark:text-gray-300"><input type="checkbox" name="quitar_foto" value="1" class="rounded border-gray-300 dark:border-gray-600"> Quitar foto</label>
            @endif
            <p class="text-xs text-gray-500 dark:text-gray-400">Solo la ven los miembros de tu hogar.</p>
        </div>
    </div>
    <div class="grid gap-4 sm:grid-cols-2">
        <x-floating-input id="nombre" label="Nombre (o razón social)" maxlength="100" :value="$contacto->nombre ?? ''" required />
        <x-floating-input id="apellido" label="Apellido (opcional)" maxlength="100" :value="$contacto->apellido ?? ''" />
    </div>
    <div class="grid gap-4 sm:grid-cols-3">
        <x-floating-select id="tipo_contacto_id" label="Tipo" :allowEmpty="false" :options="\App\Models\Catalogos\TipoContacto::opciones($contacto->tipo_contacto_id ?? null)" :value="$contacto->tipo_contacto_id ?? \App\Models\Catalogos\TipoContacto::predeterminado()" required />
        <x-floating-input id="telefono" label="Teléfono (opcional)" maxlength="30" :value="$contacto->telefono ?? ''" />
        <x-floating-input id="email" type="email" label="Correo (opcional)" maxlength="150" :value="$contacto->email ?? ''" />
    </div>
    <x-floating-input id="notas" label="Notas (opcional)" maxlength="1000" :value="$contacto->notas ?? ''" />
    @if ($editing)
        <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
            <input type="hidden" name="activo" value="0">
            <input type="checkbox" name="activo" value="1" @checked($contacto->activo) class="rounded border-gray-300 text-primary-600 focus:ring-primary-500 dark:border-gray-600"> Activo
        </label>
    @endif
    <div class="flex justify-end gap-3">
        <x-secondary-button type="button" x-on:click="$dispatch('close-modal', 'contacto-modal')">Cancelar</x-secondary-button>
        <x-primary-button type="submit" x-bind:disabled="loading">Guardar</x-primary-button>
    </div>
</form>

{{-- Al nivel de la página (x-teleport): dentro de la ventana del contacto quedaba recortado --}}
<template x-teleport="body">
    <x-modal name="crop-contacto-foto" :show="false" maxWidth="md">
        <div class="p-5">
            <h3 class="mb-3 text-lg font-semibold text-gray-900 dark:text-gray-100">Recortar foto</h3>
            <p class="mb-3 text-xs text-gray-500 dark:text-gray-400">Arrastra la imagen y usa la barra para acercar o alejar.</p>
            <div id="contacto-crop-container" class="mb-2"></div>
            <div class="flex justify-end gap-3">
                <x-secondary-button type="button" x-on:click="cancelCrop()">Cancelar</x-secondary-button>
                <x-primary-button type="button" x-on:click="saveCrop()">Usar este recorte</x-primary-button>
            </div>
        </div>
    </x-modal>
</template>
</div>
