@php($editing = isset($prestamo))
@php($simbolo = auth()->user()->monedaPreferida?->simbolo ?? '$')
@php($cargos = $editing ? $prestamo->cargos->map(fn ($c) => ['nombre' => $c->nombre, 'monto' => number_format((float) $c->monto, 2, '.', '')])->values()->all() : [])
<div x-data="{ cargos: @js($cargos) }">
<form x-data="ajaxForm(async function(data) { $dispatch('close-modal', 'prestamo-modal'); await showAlert('success', '¡Éxito!', data.message); window.dispatchEvent(new CustomEvent('refresh-table', { detail: data })); })"
    @submit.prevent="submit" method="POST" action="{{ $editing ? route('prestamos.update', $prestamo) : route('prestamos.store') }}" class="space-y-4" novalidate>
    @csrf @if ($editing) @method('PUT') @endif
    <div class="flex items-center justify-between">
        <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">{{ $editing ? 'Editar préstamo' : 'Nuevo préstamo' }}</h2>
        <button type="button" x-on:click="$dispatch('close-modal', 'prestamo-modal')" class="text-gray-400 hover:text-gray-500">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
        </button>
    </div>
    <div class="grid gap-4 sm:grid-cols-2">
        <x-floating-input id="nombre" label="Nombre (ej. Préstamo de la casa)" maxlength="100" :value="$prestamo->nombre ?? ''" required />
        <x-floating-select id="tipo_prestamo_id" label="Tipo" :allowEmpty="false" :options="\App\Models\Catalogos\TipoPrestamo::opciones($prestamo->tipo_prestamo_id ?? null)" :value="$prestamo->tipo_prestamo_id ?? \App\Models\Catalogos\TipoPrestamo::predeterminado()" required />
    </div>
    @include('hogar._quien', ['valor' => $prestamo->user_id ?? null, 'label' => 'Titular'])
    @include('contactos._quien', ['valor' => $prestamo->contacto_id ?? null, 'label' => 'Si es de una persona o tienda: ¿a quién se le debe?'])
    <div class="grid gap-4 sm:grid-cols-2">
        <x-floating-select id="banco_id" label="Banco (opcional)" :options="$bancos" :value="$prestamo->banco_id ?? ''" />
        <x-floating-input id="acreedor" label="O a quién se le debe (ej. Evelyn)" maxlength="100" :value="$prestamo->acreedor ?? ''" />
    </div>
    <div class="grid gap-4 sm:grid-cols-3">
        <x-floating-money id="monto_original" label="Monto original" :symbol="$simbolo" :value="$prestamo->monto_original ?? ''" required />
        <x-floating-input id="tasa_anual" label="Tasa anual %" type="number" step="0.001" min="0" :value="isset($prestamo) ? (float) $prestamo->tasa_anual : '0'" required />
        <x-floating-input id="plazo_meses" label="Plazo (meses, opcional)" type="number" min="1" :value="$prestamo->plazo_meses ?? ''" />
    </div>
    <div class="grid gap-4 sm:grid-cols-3">
        <x-floating-date id="fecha_otorgamiento" label="Otorgado (opcional)" :value="$prestamo->fecha_otorgamiento ?? ''" />
        <x-floating-input id="dia_pago" label="Día de pago" type="number" min="1" max="31" :value="$prestamo->dia_pago ?? ''" />
        <x-floating-money id="cuota" label="Cuota (capital + interés)" :symbol="$simbolo" :value="$prestamo->cuota ?? ''" />
    </div>
    <p class="text-xs text-gray-500 dark:text-gray-400">Si no sabes la cuota, deja el plazo y se calcula. En un préstamo de una persona sin cuota fija, déjala vacía y prueba montos en el simulador.</p>
    <div class="grid gap-4 sm:grid-cols-2">
        <x-floating-money id="saldo_inicial" label="Saldo de capital que debes" :symbol="$simbolo" :value="$prestamo->saldo_inicial ?? ''" required />
        <x-floating-date id="fecha_saldo" label="A la fecha (último pago)" :value="$prestamo->fecha_saldo ?? now(auth()->user()->zona_horaria)" required />
    </div>
    @if ($editing && $prestamo->pagos()->exists())
        <p class="text-xs text-amber-600 dark:text-amber-400">Este préstamo ya tiene pagos: el saldo y su fecha no se modifican.</p>
    @endif

    <div class="space-y-2">
        <div class="flex items-center justify-between">
            <p class="text-sm font-medium text-gray-700 dark:text-gray-300">Cargos de cada cuota (seguros, IVA)</p>
            <button type="button" class="text-sm text-primary-600 hover:underline dark:text-primary-400" x-on:click="cargos.push({ nombre: '', monto: '' })">+ Agregar</button>
        </div>
        <template x-for="(cargo, i) in cargos" :key="i">
            <div class="flex gap-2">
                <input type="text" :name="`cargos[${i}][nombre]`" x-model="cargo.nombre" placeholder="Seguro de vida" maxlength="100" class="flex-1 rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                <input type="text" inputmode="decimal" :name="`cargos[${i}][monto]`" x-model="cargo.monto" placeholder="0.00" class="w-28 rounded-md border-gray-300 text-right text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                <button type="button" class="px-2 text-red-600" x-on:click="cargos.splice(i, 1)" title="Quitar">✕</button>
            </div>
        </template>
    </div>

    <div class="grid gap-4 sm:grid-cols-2">
        <x-floating-select id="medio_pago" label="¿Cómo se paga?" :allowEmpty="false" :options="\App\Models\Prestamo::MEDIOS" :value="$prestamo->medio_pago ?? 'transferencia'" required />
        <x-floating-money id="monto_planilla" label="Parte que se descuenta de planilla (si es mixto)" :symbol="$simbolo" :value="$prestamo->monto_planilla ?? ''" />
    </div>
    <p class="text-xs text-gray-500 dark:text-gray-400">Ej.: si de la cuota de $842.93 te descuentan $526.50 del sueldo y el resto lo pagas en ventanilla, escribe 526.50: el compromiso mensual será solo lo de ventanilla.</p>
    <div class="flex items-center gap-6">
        <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
            Color <input type="color" name="color" value="{{ $prestamo->color ?? '#7C3AED' }}" class="h-8 w-12 cursor-pointer rounded border border-gray-300 dark:border-gray-600">
        </label>
    </div>
    @unless ($editing)
        <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
            <input type="checkbox" name="crear_compromiso" value="1" checked class="rounded border-gray-300 text-primary-600 focus:ring-primary-500 dark:border-gray-600">
            Crear el compromiso mensual de la cuota (no aplica a descuentos de planilla)
        </label>
    @else
        <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
            <input type="hidden" name="activo" value="0">
            <input type="checkbox" name="activo" value="1" @checked($prestamo->activo) class="rounded border-gray-300 text-primary-600 focus:ring-primary-500 dark:border-gray-600"> Activo
        </label>
    @endunless
    <x-floating-input id="notas" label="Notas (opcional)" maxlength="1000" :value="$prestamo->notas ?? ''" />
    <div class="flex justify-end gap-3">
        <x-secondary-button type="button" x-on:click="$dispatch('close-modal', 'prestamo-modal')">Cancelar</x-secondary-button>
        <x-primary-button type="submit" x-bind:disabled="loading">Guardar</x-primary-button>
    </div>
</form>
</div>
