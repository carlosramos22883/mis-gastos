@php($editing = isset($contacto))
<form x-data="ajaxForm(async function(data) { $dispatch('close-modal', 'contacto-modal'); await showAlert('success', '¡Éxito!', data.message); window.dispatchEvent(new CustomEvent('refresh-table', { detail: data })); })"
    @submit.prevent="submit" method="POST" action="{{ $editing ? route('contactos.update', $contacto) : route('contactos.store') }}" enctype="multipart/form-data" class="space-y-4" novalidate>
    @csrf @if ($editing) @method('PUT') @endif
    <div class="flex items-center justify-between">
        <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">{{ $editing ? 'Editar contacto' : 'Nuevo contacto' }}</h2>
        <button type="button" x-on:click="$dispatch('close-modal', 'contacto-modal')" class="text-gray-400 hover:text-gray-500">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
        </button>
    </div>
    <div class="flex items-center gap-4" x-data="{ vista: @js($editing ? $contacto->urlFoto() : null), quitar: false }">
        <template x-if="vista && !quitar"><img :src="vista" alt="" class="h-20 w-20 rounded-full object-cover ring-1 ring-black/10"></template>
        <template x-if="!vista || quitar"><span class="inline-flex h-20 w-20 items-center justify-center rounded-full bg-gray-100 text-gray-400 dark:bg-gray-700">
            <svg class="h-8 w-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" /></svg></span></template>
        <div class="space-y-1 text-sm">
            <label class="cursor-pointer font-medium text-primary-600 hover:underline dark:text-primary-400">
                Elegir foto o logo
                <input type="file" name="foto" accept="image/*" class="hidden" x-on:change="const f = $event.target.files[0]; if (f) { vista = URL.createObjectURL(f); quitar = false }">
            </label>
            @if ($editing && $contacto->foto)
                <label class="flex items-center gap-2 text-gray-600 dark:text-gray-300"><input type="checkbox" name="quitar_foto" value="1" x-model="quitar" class="rounded border-gray-300 dark:border-gray-600"> Quitar foto</label>
            @endif
            <p class="text-xs text-gray-500 dark:text-gray-400">Solo la ven los miembros de tu hogar. Máximo 2 MB.</p>
        </div>
    </div>
    <div class="grid gap-4 sm:grid-cols-2">
        <x-floating-input id="nombre" label="Nombre (o razón social)" maxlength="100" :value="$contacto->nombre ?? ''" required />
        <x-floating-input id="apellido" label="Apellido (opcional)" maxlength="100" :value="$contacto->apellido ?? ''" />
    </div>
    <div class="grid gap-4 sm:grid-cols-3">
        <x-floating-select id="tipo" label="Tipo" :allowEmpty="false" :options="\App\Models\Contacto::TIPOS" :value="$contacto->tipo ?? 'persona'" required />
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
