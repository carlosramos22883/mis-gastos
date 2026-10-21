@php($editing = isset($fuente))
@php($simbolo = auth()->user()->monedaPreferida?->simbolo ?? '$')
@php($conceptos = $editing ? $fuente->conceptos->map(fn ($c) => ['nombre' => $c->nombre, 'tipo' => $c->tipo, 'monto' => $c->monto !== null ? number_format((float) $c->monto, 2, '.', '') : '', 'porcentaje' => $c->porcentaje !== null ? (float) $c->porcentaje : '', 'tope' => $c->tope !== null ? number_format((float) $c->tope, 2, '.', '') : '', 'prestamo_id' => $c->prestamo_id ?? ''])->values()->all() : [])
<div x-data="{ conceptos: @js($conceptos), agregar(c) { this.conceptos.push(Object.assign({ nombre: '', tipo: 'descuento', monto: '', porcentaje: '', tope: '', prestamo_id: '' }, c)) } }">
<form x-data="ajaxForm(async function(data) { $dispatch('close-modal', 'fuente-modal'); await showAlert('success', '¡Éxito!', data.message); window.dispatchEvent(new CustomEvent('refresh-table', { detail: data })); })"
    @submit.prevent="submit" method="POST" action="{{ $editing ? route('ingresos.update', $fuente) : route('ingresos.store') }}" class="space-y-4" novalidate>
    @csrf @if ($editing) @method('PUT') @endif
    <div class="flex items-center justify-between">
        <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">{{ $editing ? 'Editar fuente de ingreso' : 'Nueva fuente de ingreso' }}</h2>
        <button type="button" x-on:click="$dispatch('close-modal', 'fuente-modal')" class="text-gray-400 hover:text-gray-500">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
        </button>
    </div>
    <div class="grid gap-4 sm:grid-cols-2">
        <x-floating-input id="nombre" label="Nombre (ej. Sueldo Stefany)" maxlength="100" :value="$fuente->nombre ?? ''" required />
        <x-floating-select id="tipo_ingreso_id" label="Tipo" :allowEmpty="false" :options="\App\Models\Catalogos\TipoIngreso::opciones($fuente->tipo_ingreso_id ?? null)" :value="$fuente->tipo_ingreso_id ?? \App\Models\Catalogos\TipoIngreso::predeterminado()" required />
    </div>
    <div class="grid gap-4 sm:grid-cols-2">
        @include('hogar._quien', ['valor' => $fuente->user_id ?? null, 'label' => '¿Quién lo recibe?'])
        <x-floating-input id="pagador" label="Empresa o cliente (opcional)" maxlength="100" :value="$fuente->pagador ?? ''" />
    </div>
    <div class="grid gap-4 sm:grid-cols-4">
        <x-floating-select id="frecuencia" label="Frecuencia" :allowEmpty="false" :options="['mensual' => 'Mensual', 'quincenal' => 'Quincenal']" :value="$fuente->frecuencia ?? 'mensual'" required />
        <x-floating-input id="dia_pago" label="Día de pago" type="number" min="1" max="31" :value="$fuente->dia_pago ?? ''" />
        <x-floating-input id="dia_pago_2" label="2.º día (quincenal)" type="number" min="1" max="31" :value="$fuente->dia_pago_2 ?? ''" />
        <x-floating-money id="monto_bruto" label="Bruto habitual" :symbol="$simbolo" :value="$fuente->monto_bruto ?? ''" />
    </div>
    <div class="grid gap-4 sm:grid-cols-2">
        <x-floating-select id="destino" label="¿A dónde entra el neto?" :allowEmpty="false" :options="$destinos" :value="$fuente->destino ?? 'efectivo'" required />
        <div class="flex items-center gap-6">
            <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                Color <input type="color" name="color" value="{{ $fuente->color ?? '#16A34A' }}" class="h-8 w-12 cursor-pointer rounded border border-gray-300 dark:border-gray-600">
            </label>
            @if ($editing)
                <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                    <input type="hidden" name="activo" value="0">
                    <input type="checkbox" name="activo" value="1" @checked($fuente->activo) class="rounded border-gray-300 text-primary-600 focus:ring-primary-500 dark:border-gray-600"> Activa
                </label>
            @endif
        </div>
    </div>

    <div class="space-y-2 rounded-lg border border-gray-200 p-4 dark:border-gray-700">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <p class="text-sm font-medium text-gray-700 dark:text-gray-300">Descuentos y adiciones de cada pago</p>
            <div class="flex flex-wrap gap-2 text-xs">
                {{-- Atajos del catálogo "Conceptos de planilla" (los mantiene el administrador: ISSS, AFP…) --}}
                @foreach (\App\Models\Catalogos\ConceptoPlanilla::atajos() as $atajo)
                    <button type="button" class="rounded-full bg-gray-100 px-2 py-1 hover:bg-gray-200 dark:bg-gray-700" x-on:click="agregar({{ \Illuminate\Support\Js::from($atajo) }})">+ {{ $atajo['nombre'] }}{{ $atajo['porcentaje'] !== '' ? ' '.$atajo['porcentaje'].' %' : '' }}</button>
                @endforeach
                <button type="button" class="rounded-full bg-gray-100 px-2 py-1 hover:bg-gray-200 dark:bg-gray-700" x-on:click="agregar({})">+ Otro</button>
            </div>
        </div>
        <p class="text-xs text-gray-500 dark:text-gray-400">Monto fijo, o porcentaje del bruto con tope. Si es la cuota de un préstamo, elígelo: cuando el préstamo se paga todo por planilla, su pago se registra solo.</p>
        <template x-for="(c, i) in conceptos" :key="i">
            <div class="grid grid-cols-12 gap-2">
                <input type="text" :name="`conceptos[${i}][nombre]`" x-model="c.nombre" placeholder="Concepto" maxlength="100" class="col-span-12 rounded-md border-gray-300 text-sm sm:col-span-3 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                <select :name="`conceptos[${i}][tipo]`" x-model="c.tipo" class="col-span-4 rounded-md border-gray-300 text-sm sm:col-span-2 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                    <option value="descuento">Descuento</option><option value="adicion">Adición</option>
                </select>
                <input type="text" inputmode="decimal" :name="`conceptos[${i}][monto]`" x-model="c.monto" placeholder="Monto" class="col-span-4 rounded-md border-gray-300 text-right text-sm sm:col-span-2 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                <input type="text" inputmode="decimal" :name="`conceptos[${i}][porcentaje]`" x-model="c.porcentaje" placeholder="%" class="col-span-2 rounded-md border-gray-300 text-right text-sm sm:col-span-1 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                <input type="text" inputmode="decimal" :name="`conceptos[${i}][tope]`" x-model="c.tope" placeholder="Tope" class="col-span-2 rounded-md border-gray-300 text-right text-sm sm:col-span-1 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                <select :name="`conceptos[${i}][prestamo_id]`" x-model="c.prestamo_id" class="col-span-10 rounded-md border-gray-300 text-sm sm:col-span-2 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                    <option value="">Sin préstamo</option>
                    @foreach ($prestamos as $id => $nombre)<option value="{{ $id }}">{{ $nombre }}</option>@endforeach
                </select>
                <button type="button" class="col-span-2 text-red-600 sm:col-span-1" x-on:click="conceptos.splice(i, 1)" title="Quitar">✕</button>
            </div>
        </template>
    </div>
    <div class="flex justify-end gap-3">
        <x-secondary-button type="button" x-on:click="$dispatch('close-modal', 'fuente-modal')">Cancelar</x-secondary-button>
        <x-primary-button type="submit" x-bind:disabled="loading">Guardar</x-primary-button>
    </div>
</form>
</div>
