{{-- Editar la regla de una serie: aplica a los pagos futuros que aún no se tocaron (CompromisoService::actualizarSerie) --}}
@php
    $simbolo = auth()->user()->monedaPreferida?->simbolo ?? '$';
    $diasMes = collect(range(1, 30))->mapWithKeys(fn ($d) => [$d => "Día {$d}"])->put(31, 'Último día del mes')->all();
    $diasSemana = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 0 => 'Domingo'];
    $finalizacion = $serie->fecha_fin ? 'fecha' : ($serie->total_cuotas ? 'cuotas' : 'indefinido');
@endphp
<form x-data="ajaxForm(async function(data) { $dispatch('close-modal', 'compromiso-modal'); await showAlert('success', '¡Listo!', data.message); window.dispatchEvent(new CustomEvent('refresh-table', { detail: data })); })"
    @submit.prevent="submit" method="POST" action="{{ route('compromisos.serie.update', $compromiso) }}" novalidate>
    @csrf @method('PUT')
    <div x-data="{ frecuencia: @js($serie->frecuencia), finalizacion: @js($finalizacion) }"
        x-on:floating-select-change.window="
            if ($event.detail.id === 'frecuencia') frecuencia = $event.detail.value;
            if ($event.detail.id === 'finalizacion') finalizacion = $event.detail.value;"
        class="space-y-4">
        <div class="mb-2 flex items-center justify-between">
            <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">Editar la serie · {{ $serie->descripcion }}</h2>
            <button type="button" x-on:click="$dispatch('close-modal', 'compromiso-modal')" class="text-gray-400 hover:text-gray-500">
                <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
            </button>
        </div>
        <p class="rounded-lg bg-primary-50 px-4 py-3 text-sm text-primary-700 dark:bg-gray-700 dark:text-primary-300">
            Cambia la regla que se repite (empezó el {{ $serie->fecha_inicio->format('d/m/Y') }}). Se aplica a los pagos de hoy en adelante que aún no tienen abonos; los ya pagados, abonados o cancelados no cambian.
        </p>

        <x-floating-input id="descripcion" label="Descripción" maxlength="255" :value="$serie->descripcion" required />
        <x-floating-money id="monto" label="Monto de cada pago" :symbol="$simbolo" :value="$serie->monto" required />
        @include('hogar._quien', ['valor' => $serie->user_id, 'label' => 'Responsable'])
        @include('contactos._quien', ['valor' => $serie->contacto_id, 'label' => '¿A quién se le paga? (opcional)'])

        <x-floating-select id="frecuencia" label="Frecuencia" :allowEmpty="false" :options="['mensual' => 'Mensual', 'quincenal' => 'Quincenal (dos días del mes)', 'semanal' => 'Semanal']" :value="$serie->frecuencia" required />
        <div x-show="frecuencia === 'semanal'" @if ($serie->frecuencia !== 'semanal') style="display: none;" @endif>
            <x-floating-select id="dia_semana" label="Día de la semana" :allowEmpty="false" :options="$diasSemana" :value="$serie->dia_semana ?? 1" />
        </div>
        <div x-show="frecuencia !== 'semanal'" class="grid gap-4" x-bind:class="frecuencia === 'quincenal' ? 'sm:grid-cols-2' : ''" @if ($serie->frecuencia === 'semanal') style="display: none;" @endif>
            <x-floating-select id="dia_pago" label="Día de pago" :allowEmpty="false" :options="$diasMes" :value="$serie->dia_pago ?? 15" />
            <div x-show="frecuencia === 'quincenal'" @if ($serie->frecuencia !== 'quincenal') style="display: none;" @endif>
                <x-floating-select id="dia_pago_2" label="Segundo día de pago" :allowEmpty="false" :options="$diasMes" :value="$serie->dia_pago_2 ?? 31" />
            </div>
        </div>
        <x-floating-select id="finalizacion" label="¿Hasta cuándo?" :allowEmpty="false" :options="['indefinido' => 'Sin fecha de fin', 'fecha' => 'Hasta una fecha', 'cuotas' => 'Un número de cuotas']" :value="$finalizacion" />
        <div x-show="finalizacion === 'fecha'" @if ($finalizacion !== 'fecha') style="display: none;" @endif>
            <x-floating-date id="fecha_fin" label="Hasta" :value="$serie->fecha_fin" :max="now()->addYears(30)" />
        </div>
        <div x-show="finalizacion === 'cuotas'" @if ($finalizacion !== 'cuotas') style="display: none;" @endif>
            <x-floating-input id="total_cuotas" label="Número de cuotas en total" type="number" min="1" max="600" :value="$serie->total_cuotas" />
        </div>

        <x-floating-select id="categoria_personal_id" label="Categoría (opcional)" :options="$categorias" :value="$serie->categoria_personal_id ?? ''" />
        <x-checkbox name="es_presupuesto" sinMarcar="0" :checked="$serie->es_presupuesto" ayuda="No se paga: se descuenta solo con los gastos de su categoría en cada ciclo.">Es un presupuesto</x-checkbox>
        <x-floating-select id="medio_pago" label="Medio de pago" :allowEmpty="false" :options="['por_definir' => 'Por definir', 'efectivo' => 'Efectivo', 'tarjeta' => 'Tarjeta', 'transferencia' => 'Transferencia']" :value="$serie->medio_pago" required />
        <x-floating-input id="comentario" label="Comentario (opcional)" maxlength="1000" :value="$serie->comentario ?? ''" />

        <div class="flex justify-end gap-3">
            <x-secondary-button type="button" x-on:click="$dispatch('close-modal', 'compromiso-modal')">Cancelar</x-secondary-button>
            <x-primary-button type="submit" x-bind:disabled="loading">Guardar la serie</x-primary-button>
        </div>
    </div>
</form>
