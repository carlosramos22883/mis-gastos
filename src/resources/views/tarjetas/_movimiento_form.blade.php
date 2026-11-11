@php
    $editing = isset($movimiento);
    $simbolo = auth()->user()->monedaPreferida?->simbolo ?? '$';
    $tipos = $tipo === 'compra' ? ['compra' => 'Compra'] : ['cargo' => 'Cargo (intereses, seguro, comisión)', 'credito' => 'Crédito (devolución o promoción)'];
@endphp
<form x-data="ajaxForm(async function(data) { $dispatch('close-modal', 'movimiento-modal'); await showAlert('success', '¡Éxito!', data.message); window.dispatchEvent(new CustomEvent('refresh-table', { detail: data })); })"
    @submit.prevent="submit" method="POST" action="{{ $editing ? route('tarjetas.movimientos.update', [$tarjeta, $movimiento]) : route('tarjetas.movimientos.store', [$tarjeta, 'estado' => ($facturado ?? null)?->id]) }}" class="space-y-4" novalidate>
    @csrf @if ($editing) @method('PUT') @endif
    <div class="flex items-center justify-between">
        <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">{{ $editing ? 'Editar movimiento' : ($tipo === 'compra' ? 'Registrar compra' : 'Cargo o crédito') }}{{ ! $editing && ($facturado ?? null) ? ' (corte pasado)' : '' }} · {{ $tarjeta->nombre }}</h2>
        <button type="button" x-on:click="$dispatch('close-modal', 'movimiento-modal')" class="text-gray-400 hover:text-gray-500">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
        </button>
    </div>
    @if ($facturado ?? null)
        <p class="rounded-md bg-amber-50 p-3 text-sm text-amber-800 dark:bg-amber-900/30 dark:text-amber-200">
            @if ($editing)
                Ya se facturó en el corte del {{ $facturado->fecha_corte->format('d/m/Y') }}. Si cambias el monto, el total de ese estado de cuenta y lo que falta pagar se ajustan solos.
            @else
                Se agrega al corte del {{ $facturado->fecha_corte->format('d/m/Y') }} (período del {{ $facturado->fecha_inicio->format('d/m/Y') }} al {{ $facturado->fecha_corte->format('d/m/Y') }}): el total de ese estado de cuenta y lo que falta pagar suben.
            @endif
        </p>
    @else
        <p class="text-sm text-gray-600 dark:text-gray-300">Disponible: <strong>{{ $simbolo }} {{ number_format($disponible, 2) }}</strong> · Período sin facturar desde el {{ $desde->format('d/m/Y') }}</p>
    @endif
    @if (count($tipos) > 1)
        <x-floating-select id="tipo" label="Tipo" :allowEmpty="false" :options="$tipos" :value="$movimiento->tipo ?? 'cargo'" required />
    @else
        <input type="hidden" name="tipo" value="compra">
    @endif
    <x-floating-input id="descripcion" label="Descripción" maxlength="255" :value="$movimiento->descripcion ?? ''" required />
    @include('hogar._quien', ['valor' => $movimiento->user_id ?? null])
    @include('contactos._quien', ['valor' => $movimiento->contacto_id ?? null, 'label' => '¿Dónde o a quién? (opcional)'])
    @if (! $editing && $tipo === 'compra')
        @can('deudores.create')
            {{-- La compra la hizo otra persona con la tarjeta: queda como deuda suya por el monto completo --}}
            <div x-data="{ conContacto: false }" x-on:floating-select-change.window="if ($event.detail.id === 'contacto_id') conContacto = !! $event.detail.value" x-show="conContacto" x-cloak>
                <x-checkbox name="me_lo_debe">Me lo debe (la compra fue suya: queda como deuda en el contacto)</x-checkbox>
            </div>
        @endcan
    @endif
    <div class="grid gap-4 sm:grid-cols-2">
        <x-floating-money id="monto" label="Monto total" :symbol="$simbolo" :value="$movimiento->monto ?? ''" required />
        <x-floating-date id="fecha" label="Fecha" :value="$movimiento->fecha ?? ($facturado ?? null)?->fecha_corte ?? now(auth()->user()->zona_horaria)" :min="($facturado ?? null)?->fecha_inicio ?? $desde" :max="($facturado ?? null)?->fecha_corte" required />
    </div>
    @if ($tipo === 'compra' && ! ($facturado ?? null))
        {{-- En cuotas: el día del mes en que se cobra cada una (la primera, ese día en o después de la compra) --}}
        <div class="space-y-1" x-data="{ enCuotas: {{ ($movimiento->cuotas ?? 1) > 1 ? 'true' : 'false' }} }"
            x-on:floating-select-change.window="if ($event.detail.id === 'cuotas') enCuotas = parseInt($event.detail.value) > 1">
            <div class="grid gap-4" x-bind:class="enCuotas && 'sm:grid-cols-2'">
                <x-floating-select id="cuotas" label="Forma de pago" :allowEmpty="false"
                    :options="collect([1 => 'De contado (un solo corte)'])->union(collect([2, 3, 4, 6, 9, 10, 12, 15, 18, 24, 36, 48])->mapWithKeys(fn ($n) => [$n => $n.' cuotas a tasa cero']))->all()"
                    :value="$movimiento->cuotas ?? 1" />
                <div x-show="enCuotas" x-cloak>
                    <x-floating-select id="dia_cuota" label="Día de cobro de cada cuota" :allowEmpty="false"
                        :options="collect(range(1, 30))->mapWithKeys(fn ($d) => [$d => 'Día '.$d])->put(31, 'Último día del mes')->all()"
                        :value="$movimiento->dia_cuota ?? (int) now(auth()->user()->zona_horaria)->format('j')" />
                </div>
            </div>
            <p class="text-xs text-gray-500 dark:text-gray-400">En cuotas se descuenta el total del disponible y cada cuota entra en el corte en que cae su día de cobro.</p>
        </div>
    @endif
    <x-floating-select id="categoria_personal_id" label="Categoría (opcional)" :options="$categorias" :value="$movimiento->categoria_personal_id ?? ''" />
    <x-floating-input id="comentario" label="Comentario (opcional)" maxlength="1000" :value="$movimiento->comentario ?? ''" />
    <div class="flex justify-end gap-3">
        <x-secondary-button type="button" x-on:click="$dispatch('close-modal', 'movimiento-modal')">Cancelar</x-secondary-button>
        <x-primary-button type="submit" x-bind:disabled="loading">Guardar</x-primary-button>
    </div>
</form>
