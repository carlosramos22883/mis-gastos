@php($editing = isset($compromiso))
<form x-data="{ recurrente: @js(old('recurrencia_tipo', $compromiso->recurrencia_tipo ?? 'unica') === 'recurrente'), ajax: ajaxForm(async function(data) { $dispatch('close-modal', 'compromiso-modal'); await showAlert('success', '¡Éxito!', data.message); window.dispatchEvent(new CustomEvent('refresh-table', { detail: data })); }) }" @submit.prevent="ajax.submit" method="post" action="{{ $editing ? route('compromisos.update', $compromiso) : route('compromisos.store') }}" class="space-y-5" novalidate>
    @csrf @if($editing) @method('PUT') @endif
    <h2 class="text-lg font-semibold">{{ $editing ? 'Editar compromiso' : 'Nuevo compromiso' }}</h2>
    <x-floating-input id="descripcion" name="descripcion" label="Descripción" maxlength="255" :value="old('descripcion', $compromiso->descripcion ?? '')" :error="$errors->first('descripcion')" required />
    <x-floating-select id="recurrencia_tipo" name="recurrencia_tipo" label="Tipo de compromiso" :options="['unica'=>'Una sola vez','recurrente'=>'Recurrente']" :value="old('recurrencia_tipo', $compromiso->recurrencia_tipo ?? 'unica')" x-on:change="recurrente = $event.target.value === 'recurrente'" required />
    <div x-show="!recurrente" class="space-y-4">
        <x-floating-date id="fecha_esperada" label="Fecha esperada" :value="old('fecha_esperada', $editing && $compromiso->fecha_esperada ? $compromiso->fecha_esperada->format('d/m/Y') : '')" :min="$dateMin" :max="$dateMax" :error="$errors->first('fecha_esperada')" :required="true" />
    </div>
    <div x-show="recurrente" class="space-y-4">
        <x-floating-select id="recurrencia_unidad" name="recurrencia_unidad" label="Frecuencia de pago" :options="['semanal'=>'Semanal','quincenal'=>'Quincenal','mensual'=>'Mensual']" :value="old('recurrencia_unidad', $compromiso->recurrencia_unidad ?? 'mensual')" required />
        <x-floating-input id="recurrencia_intervalo" name="recurrencia_intervalo" label="Día de pago (1-31)" type="number" min="1" max="31" :value="old('recurrencia_intervalo', $compromiso->recurrencia_intervalo ?? 1)" required />
    </div>
    <x-floating-money id="monto" label="Monto" symbol="{{ auth()->user()->monedaPreferida?->simbolo ?? '$' }}" :value="old('monto', $compromiso->monto ?? '0.00')" :error="$errors->first('monto')" required />
    <x-floating-select id="medio_pago" name="medio_pago" label="Medio de pago" :options="['por_definir'=>'Por definir','efectivo'=>'Efectivo','tarjeta'=>'Tarjeta','transferencia'=>'Transferencia']" :value="old('medio_pago', $compromiso->medio_pago ?? 'por_definir')" required />
    <div class="flex justify-end gap-3"><x-secondary-button type="button" x-on:click="$dispatch('close-modal', 'compromiso-modal')">Cancelar</x-secondary-button><x-primary-button type="submit">Guardar</x-primary-button></div>
</form>
