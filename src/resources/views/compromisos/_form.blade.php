@php
    $editing = isset($compromiso);
    $simbolo = auth()->user()->monedaPreferida?->simbolo ?? '$';
    $diasMes = collect(range(1, 30))->mapWithKeys(fn ($d) => [$d => "Día {$d}"])->put(31, 'Último día del mes')->all();
    $diasSemana = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 0 => 'Domingo'];
@endphp
<form x-data="ajaxForm(async function(data) { $dispatch('close-modal', 'compromiso-modal'); await showAlert('success', '¡Éxito!', data.message); window.dispatchEvent(new CustomEvent('refresh-table', { detail: data })); })"
    @submit.prevent="submit" method="POST"
    action="{{ $editing ? route('compromisos.update', $compromiso) : route('compromisos.store') }}" novalidate>
    @csrf @if($editing) @method('PUT') @endif
    <div x-data="{ tipo: 'unica', frecuencia: 'mensual', finalizacion: 'indefinido' }"
        x-on:floating-select-change.window="
            if ($event.detail.id === 'recurrencia_tipo') tipo = $event.detail.value;
            if ($event.detail.id === 'frecuencia') frecuencia = $event.detail.value;
            if ($event.detail.id === 'finalizacion') finalizacion = $event.detail.value;"
        class="space-y-4">

    <div class="flex items-center justify-between mb-2">
        <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">{{ $editing ? 'Editar compromiso' : 'Nuevo compromiso' }}</h2>
        <button type="button" x-on:click="$dispatch('close-modal', 'compromiso-modal')" class="text-gray-400 hover:text-gray-500">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
        </button>
    </div>

    @if ($editing && $compromiso->recurrente)
        <p class="rounded-lg bg-primary-50 px-4 py-3 text-sm text-primary-700 dark:bg-gray-700 dark:text-primary-300">
            Solo se modifica este pago{{ $compromiso->numero_cuota ? ' (cuota '.$compromiso->numero_cuota.($compromiso->total_cuotas ? ' de '.$compromiso->total_cuotas : '').')' : '' }}. Los siguientes de la serie no cambian.
            <button type="button" class="ml-1 font-semibold underline hover:no-underline" x-on:click="window.dispatchEvent(new CustomEvent('load-compromiso-modal-form', { detail: '{{ route('compromisos.serie.edit', $compromiso) }}' }))">Editar toda la serie</button>
        </p>
    @endif

    <x-floating-input id="descripcion" label="Descripción" maxlength="255" :value="old('descripcion', $compromiso->descripcion ?? '')" :error="$errors->first('descripcion')" required />
    @include('hogar._quien', ['valor' => $compromiso->user_id ?? null, 'label' => 'Responsable'])
    @include('contactos._quien', ['valor' => $compromiso->contacto_id ?? null, 'label' => '¿A quién se le paga? (opcional)'])

    @unless ($editing)
        <x-floating-select id="recurrencia_tipo" label="Tipo de compromiso" :allowEmpty="false" :options="['unica' => 'Una sola vez', 'recurrente' => 'Se repite']" value="unica" required />
    @endunless

    <div x-show="tipo === 'unica'" class="space-y-4">
        <x-floating-date id="fecha_esperada" label="Fecha de pago (opcional)" :value="$editing ? $compromiso->fecha_esperada : ''" :max="$hoy->copy()->addYears(10)" :error="$errors->first('fecha_esperada')" />
    </div>

    @unless ($editing)
        <div x-show="tipo === 'recurrente'" class="space-y-4" style="display: none;">
            <x-floating-select id="frecuencia" label="Frecuencia" :allowEmpty="false" :options="['mensual' => 'Mensual', 'quincenal' => 'Quincenal (dos días del mes)', 'semanal' => 'Semanal']" value="mensual" required />
            <div x-show="frecuencia === 'semanal'" style="display: none;">
                <x-floating-select id="dia_semana" label="Día de la semana" :allowEmpty="false" :options="$diasSemana" value="1" />
            </div>
            <div x-show="frecuencia !== 'semanal'" class="grid gap-4" x-bind:class="frecuencia === 'quincenal' ? 'sm:grid-cols-2' : ''">
                <x-floating-select id="dia_pago" label="Día de pago" :allowEmpty="false" :options="$diasMes" value="15" :error="$errors->first('dia_pago')" />
                <div x-show="frecuencia === 'quincenal'" style="display: none;">
                    <x-floating-select id="dia_pago_2" label="Segundo día de pago" :allowEmpty="false" :options="$diasMes" value="31" :error="$errors->first('dia_pago_2')" />
                </div>
            </div>
            <x-floating-date id="fecha_inicio" label="Desde" :value="$hoy" :max="$hoy->copy()->addYears(10)" :error="$errors->first('fecha_inicio')" />
            <x-floating-select id="finalizacion" label="¿Hasta cuándo?" :allowEmpty="false" :options="['indefinido' => 'Sin fecha de fin', 'fecha' => 'Hasta una fecha', 'cuotas' => 'Un número de cuotas']" value="indefinido" />
            <div x-show="finalizacion === 'fecha'" style="display: none;">
                <x-floating-date id="fecha_fin" label="Hasta" :max="$hoy->copy()->addYears(30)" :error="$errors->first('fecha_fin')" />
            </div>
            <div x-show="finalizacion === 'cuotas'" style="display: none;">
                <x-floating-input id="total_cuotas" label="Número de cuotas" type="number" min="1" max="600" :error="$errors->first('total_cuotas')" />
            </div>
        </div>
    @endunless

    <x-floating-money id="monto" label="Monto" :symbol="$simbolo" :value="old('monto', $compromiso->monto ?? '')" :error="$errors->first('monto')" required />
    @if ($editing && $compromiso->montoPagado() > 0)
        <p class="-mt-2 text-xs text-gray-500 dark:text-gray-400">Ya se abonaron {{ $simbolo }} {{ number_format($compromiso->montoPagado(), 2) }}.</p>
    @endif
    <x-floating-select id="categoria_personal_id" label="Categoría (opcional)" :options="$categorias" :value="old('categoria_personal_id', $compromiso->categoria_personal_id ?? '')" :error="$errors->first('categoria_personal_id')" />
    <x-floating-select id="medio_pago" label="Medio de pago" :allowEmpty="false" :options="['por_definir' => 'Por definir', 'efectivo' => 'Efectivo', 'tarjeta' => 'Tarjeta', 'transferencia' => 'Transferencia']" :value="old('medio_pago', $compromiso->medio_pago ?? 'por_definir')" required />
    <x-floating-input id="comentario" label="Comentario (opcional)" maxlength="1000" :value="old('comentario', $compromiso->comentario ?? '')" :error="$errors->first('comentario')" />

    <div class="flex justify-end gap-3">
        <x-secondary-button type="button" x-on:click="$dispatch('close-modal', 'compromiso-modal')">Cancelar</x-secondary-button>
        <x-primary-button type="submit" x-bind:disabled="loading">Guardar</x-primary-button>
    </div>
    </div>
</form>
