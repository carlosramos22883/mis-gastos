@php($simbolo = auth()->user()->monedaPreferida?->simbolo ?? '$')
<div x-data="{
        bruto: @js(number_format($propuesta['bruto'], 2, '.', '')),
        lineas: @js($propuesta['lineas']),
        num(v) { return parseFloat(String(v ?? '').replace(/,/g, '')) || 0 },
        recalcular() { const b = this.num(this.bruto); this.lineas.forEach(l => { if (l.porcentaje !== null && l.porcentaje !== '') { let m = b * this.num(l.porcentaje) / 100; if (l.tope !== null && l.tope !== '') m = Math.min(m, this.num(l.tope)); l.monto = m.toFixed(2) } }) },
        get neto() { return (this.num(this.bruto) + this.lineas.reduce((s, l) => s + (l.tipo === 'adicion' ? 1 : -1) * this.num(l.monto), 0)).toFixed(2) },
        agregar(tipo) { this.lineas.push({ nombre: '', tipo, monto: '', porcentaje: null, tope: null, prestamo_id: null }) }
    }">
<form x-data="ajaxForm(async function(data) { $dispatch('close-modal', 'recibo-modal'); await showAlert('success', '¡Éxito!', data.message); window.dispatchEvent(new CustomEvent('refresh-table', { detail: data })); })"
    @submit.prevent="submit" method="POST" action="{{ route('ingresos.recibos.store', $fuente) }}" class="space-y-4" novalidate>
    @csrf
    <div class="flex items-center justify-between">
        <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">Pago recibido · {{ $fuente->nombre }}</h2>
        <button type="button" x-on:click="$dispatch('close-modal', 'recibo-modal')" class="text-gray-400 hover:text-gray-500">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
        </button>
    </div>
    <div class="grid gap-4 sm:grid-cols-3">
        <x-floating-date id="fecha" label="Fecha" :value="now(auth()->user()->zona_horaria)" :max="now(auth()->user()->zona_horaria)" required />
        <label class="text-xs text-gray-600 dark:text-gray-400">Bruto ({{ $simbolo }})
            <input type="text" inputmode="decimal" name="bruto" x-model="bruto" x-on:input="recalcular()" class="mt-1 w-full rounded-md border-gray-300 text-right text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
        </label>
        <x-floating-select id="destino" label="¿A dónde entró el neto?" :allowEmpty="false" :options="$destinos" :value="$fuente->destino" required />
    </div>

    <div class="space-y-2">
        <template x-for="(l, i) in lineas" :key="i">
            <div class="grid grid-cols-12 items-center gap-2">
                <input type="text" :name="`lineas[${i}][nombre]`" x-model="l.nombre" placeholder="Concepto" maxlength="100" class="col-span-6 rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                <input type="hidden" :name="`lineas[${i}][tipo]`" :value="l.tipo">
                <input type="hidden" :name="`lineas[${i}][prestamo_id]`" :value="l.prestamo_id ?? ''">
                <span class="col-span-1 text-center text-sm font-semibold" :class="l.tipo === 'adicion' ? 'text-green-600' : 'text-red-600'" x-text="l.tipo === 'adicion' ? '+' : '−'"></span>
                <input type="text" inputmode="decimal" :name="`lineas[${i}][monto]`" x-model="l.monto" class="col-span-4 rounded-md border-gray-300 text-right text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                <button type="button" class="col-span-1 text-red-600" x-on:click="lineas.splice(i, 1)" title="Quitar">✕</button>
            </div>
        </template>
        <div class="flex gap-3 text-sm">
            <button type="button" class="text-primary-600 hover:underline dark:text-primary-400" x-on:click="agregar('descuento')">+ Descuento</button>
            <button type="button" class="text-primary-600 hover:underline dark:text-primary-400" x-on:click="agregar('adicion')">+ Adición (bono, horas extra…)</button>
        </div>
    </div>
    <p class="text-right text-lg text-gray-900 dark:text-gray-100">Neto: <strong>{{ $simbolo }} <span x-text="neto"></span></strong></p>
    <p class="text-xs text-gray-500 dark:text-gray-400">El neto entra como ingreso. Los descuentos de préstamos que se pagan todo por planilla se registran solos en Préstamos.</p>
    @include('hogar._quien', ['valor' => $fuente->user_id, 'label' => '¿Quién lo recibió?'])
    <x-floating-input id="comentario" label="Comentario (opcional)" maxlength="1000" />
    <div class="flex justify-end gap-3">
        <x-secondary-button type="button" x-on:click="$dispatch('close-modal', 'recibo-modal')">Cancelar</x-secondary-button>
        <x-primary-button type="submit" x-bind:disabled="loading">Registrar</x-primary-button>
    </div>
</form>
</div>
