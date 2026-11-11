@php
    $simbolo = auth()->user()->monedaPreferida?->simbolo ?? '$';
    $editando = $escenario->exists;
    $datos = $escenario->datos ?? [];
    $deudas = collect($base['deudas'])->reject(fn ($d) => ! empty($d['hipoteca']));
    $opcionesDeudas = $deudas->mapWithKeys(fn ($d) => [$d['clave'] => $d['nombre'].' · '.$simbolo.' '.number_format($d['saldo'], 2)])->all();
    $nombres = $deudas->pluck('nombre', 'clave');
    $mesInicio = $base['mes_inicio'];
    $opcionesMeta = ['tarjetas' => 'Tarjetas en $0', 'personas' => 'Deudas con personas pagadas', 'sin_deudas' => 'Sin deudas (menos la hipoteca)']
        + $deudas->mapWithKeys(fn ($d) => [$d['clave'] => 'Pagar '.$d['nombre']])->all();
@endphp
{{-- Escenario: datos generales y la lista de palancas. Las palancas se arman una a una en el panel de abajo (componentes
     fijos) y viajan como JSON en "palancas". --}}
<form x-data="ajaxForm(async function(data) { $dispatch('close-modal', 'escenario-modal'); await showAlert('success', '¡Listo!', data.message); window.location = data.redirect; })"
    @submit.prevent="submit" method="POST" action="{{ $editando ? route('proyecciones.update', $escenario) : route('proyecciones.store') }}" class="space-y-4" novalidate>
    @csrf
    @if ($editando) @method('PUT') @endif
    <input type="hidden" name="ver_actual" value="{{ request('ver_actual') }}">
    <div class="flex items-center justify-between">
        <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">{{ $editando ? 'Editar escenario' : 'Nuevo escenario' }}</h2>
        <button type="button" x-on:click="$dispatch('close-modal', 'escenario-modal')" class="text-gray-400 hover:text-gray-500" aria-label="Cerrar">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
        </button>
    </div>

    <x-floating-input id="nombre" label="Nombre del escenario" maxlength="100" :value="$escenario->nombre ?? ''" placeholder="Ej: Préstamo BAC y vender el Kia" required />
    <div class="grid gap-4 sm:grid-cols-3" x-data="{ rotar: {{ ($datos['modo_tarjetas'] ?? 'intereses') === 'rotar' ? 'true' : 'false' }} }"
        x-on:floating-select-change.window="if ($event.detail.id === 'modo_tarjetas') rotar = $event.detail.value === 'rotar'">
        <x-floating-select id="modo_tarjetas" label="Las tarjetas…" :allowEmpty="false" :value="$datos['modo_tarjetas'] ?? 'intereses'"
            :options="['intereses' => 'Pagan sus intereses', 'rotar' => 'Se rotan con avances (Chivo)']" />
        <div x-show="rotar" x-cloak><x-floating-input id="perdida_rotar" label="% que se pierde al mes" type="number" step="0.1" min="0" max="20" :value="$datos['perdida_rotar'] ?? 1" /></div>
        <x-floating-select id="sobrante" label="Cuando ya no haya tarjetas ni personas" :allowEmpty="false" :value="$datos['sobrante'] ?? 'abonar'"
            :options="['abonar' => 'Abonar a los préstamos', 'ahorrar' => 'Ahorrarlo']" />
    </div>

    {{-- En qué orden paga lo que sobra --}}
    <div class="grid gap-4 sm:grid-cols-3" x-data="{ personalizado: {{ ($datos['orden'] ?? 'mas_cara') === 'personalizado' ? 'true' : 'false' }} }"
        x-on:floating-select-change.window="if ($event.detail.id === 'orden') personalizado = $event.detail.value === 'personalizado'">
        <x-floating-select id="orden" label="Lo que sobra paga primero…" :allowEmpty="false" :value="$datos['orden'] ?? 'mas_cara'"
            :options="['mas_cara' => 'La deuda más cara (menos intereses)', 'mas_pequena' => 'La más pequeña (salen rápido)', 'personalizado' => 'El orden que yo elija']" />
        <div class="sm:col-span-2" x-show="personalizado" x-cloak>
            <x-floating-select id="prioridad" label="Primero estas, en este orden (luego las demás)" multiple :options="$opcionesDeudas" :value="$datos['prioridad'] ?? []" />
        </div>
    </div>

    {{-- Meta opcional: ¿cuánto más necesito al mes para llegar a tiempo? --}}
    <div class="grid gap-4 sm:grid-cols-3">
        <div class="sm:col-span-2"><x-floating-select id="meta_que" label="Meta (opcional): quiero lograr…" :options="$opcionesMeta" :value="$datos['meta']['que'] ?? ''" /></div>
        <x-floating-input id="meta_mes" type="month" label="Para (mes)" :value="$datos['meta']['mes'] ?? ''" />
    </div>

    <div x-data="{
            palancas: {{ \Illuminate\Support\Js::from($datos['palancas'] ?? []) }},
            nombres: {{ \Illuminate\Support\Js::from($nombres) }},
            tipo: 'prestamo',
            error: '',
            valor(id) { const el = document.getElementById(id); return el ? el.value.replace(/,/g, '').trim() : ''; },
            select(id) { return window.tomSelects?.[id]?.getValue() ?? ''; },
            dinero(v) { return '{{ $simbolo }} ' + Number(v || 0).toLocaleString('es-SV', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
            mes(m) { if (!m) return ''; const [a, b] = m.split('-'); return b + '/' + a; },
            destinos(p) { return (p.destino || []).map(c => this.nombres[c] || c).join(', '); },
            resumen(p) {
                switch (p.tipo) {
                    case 'prestamo': return 'Préstamo de ' + this.dinero(p.monto) + ' en ' + this.mes(p.mes) + ' · ' + (p.tasa || 0) + ' % · ' + p.plazo + ' meses' + (p.cuota ? ' · cuota ' + this.dinero(p.cuota) : '') + (p.comision ? ' · comisión ' + this.dinero(p.comision) : '') + (p.destino?.length ? ' → paga: ' + this.destinos(p) : '');
                    case 'venta': return 'Vender ' + (p.descripcion || 'algo') + ' por ' + this.dinero(p.monto) + ' en ' + this.mes(p.mes) + (p.destino?.length ? ' → paga: ' + this.destinos(p) : '');
                    case 'gasto': return 'Gasto de vida de ' + this.dinero(p.monto) + ' al mes desde ' + this.mes(p.desde) + (p.hasta ? ' hasta ' + this.mes(p.hasta) : '');
                    case 'ingreso': return (Number(p.monto) < 0 ? 'Entra menos: ' : 'Ingreso extra de ') + this.dinero(Math.abs(p.monto)) + ' al mes desde ' + this.mes(p.desde) + (p.hasta ? ' hasta ' + this.mes(p.hasta) : '') + (p.descripcion ? ' (' + p.descripcion + ')' : '');
                    case 'unico': return (Number(p.monto) < 0 ? 'Sale ' : 'Entra ') + this.dinero(Math.abs(p.monto)) + ' en ' + this.mes(p.mes) + (p.descripcion ? ' · ' + p.descripcion : '');
                    case 'pagar': return 'Pagar ' + (p.monto ? this.dinero(p.monto) + ' de ' : 'todo ') + (this.nombres[p.deuda] || p.deuda) + ' en ' + this.mes(p.mes);
                    case 'cuotas': return 'Pasar ' + (this.nombres[p.deuda] || p.deuda) + ' a ' + p.plazo + ' cuotas en ' + this.mes(p.mes) + ' · ' + (p.tasa || 0) + ' %' + (p.comision ? ' · comisión ' + this.dinero(p.comision) : '');
                }
            },
            agregar() {
                const p = { tipo: this.tipo };
                const campos = { prestamo: ['mes', 'monto', 'tasa', 'plazo', 'comision', 'cuota', 'destino'], venta: ['descripcion', 'mes', 'monto', 'destino'],
                    gasto: ['desde', 'hasta', 'monto'], ingreso: ['descripcion', 'desde', 'hasta', 'monto'], unico: ['descripcion', 'mes', 'monto'], pagar: ['mes', 'deuda', 'monto'],
                    cuotas: ['mes', 'deuda', 'tasa', 'plazo', 'comision'] }[this.tipo];
                const ids = { mes: 'p_mes', desde: 'p_mes', hasta: 'p_hasta', monto: 'p_monto', tasa: 'p_tasa', plazo: 'p_plazo', comision: 'p_comision', cuota: 'p_cuota', descripcion: 'p_descripcion' };
                for (const c of campos) {
                    let v = c === 'destino' ? this.select('p_destino') : c === 'deuda' ? this.select('p_deuda') : this.valor(ids[c]);
                    if (Array.isArray(v) ? v.length : v !== '') p[c] = ['monto', 'tasa', 'comision', 'cuota'].includes(c) ? Number(v) : c === 'plazo' ? parseInt(v) : v;
                }
                if (this.tipo === 'unico' && p.monto && document.getElementById('p_sale')?.checked) p.monto = -Math.abs(p.monto);
                const faltan = { prestamo: ['mes', 'monto', 'plazo'], venta: ['mes', 'monto'], gasto: ['desde', 'monto'], ingreso: ['desde', 'monto'], unico: ['mes', 'monto'], pagar: ['mes', 'deuda'], cuotas: ['mes', 'deuda', 'plazo'] }[this.tipo].filter(c => p[c] === undefined);
                if (faltan.length) { this.error = 'Falta: ' + faltan.join(', ') + '.'; return; }
                this.error = '';
                this.palancas.push(p);
                ['p_monto', 'p_comision', 'p_cuota', 'p_descripcion'].forEach(id => { const el = document.getElementById(id); if (el) { el.value = ''; el.dispatchEvent(new Event('input')); } });
                window.tomSelects?.p_destino?.clear();
            },
        }" x-on:floating-select-change.window="if ($event.detail.id === 'p_tipo') tipo = $event.detail.value">
        <input type="hidden" name="palancas" x-bind:value="JSON.stringify(palancas)">
        <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Palancas</h3>
        <p class="text-xs text-gray-500 dark:text-gray-400">Lo que cambia frente a «Si sigo igual». Sin palancas, el escenario solo cambia cómo se manejan las tarjetas.</p>
        <ul class="mt-2 divide-y divide-gray-100 rounded-lg border border-gray-200 dark:divide-gray-700 dark:border-gray-700" x-show="palancas.length">
            <template x-for="(p, i) in palancas" :key="i">
                <li class="flex items-start justify-between gap-3 px-3 py-2 text-sm text-gray-700 dark:text-gray-300">
                    <span x-text="resumen(p)"></span>
                    <button type="button" class="shrink-0 text-xs text-red-600 hover:underline dark:text-red-400" x-on:click="palancas.splice(i, 1)">Quitar</button>
                </li>
            </template>
        </ul>

        {{-- Armar una palanca --}}
        <div class="mt-3 space-y-3 rounded-lg bg-gray-50 p-4 dark:bg-gray-900/40">
            <x-floating-select id="p_tipo" label="Agregar una palanca" :allowEmpty="false" value="prestamo" :options="[
                'prestamo' => 'Tomar un préstamo', 'venta' => 'Vender algo', 'gasto' => 'Cambiar el gasto de vida',
                'ingreso' => 'Ingreso extra (o menos ingreso) al mes', 'unico' => 'Algo de una vez (aguinaldo, renta…)', 'pagar' => 'Pagar una deuda en un mes',
                'cuotas' => 'Pasar una deuda a cuotas (ExtraYA, Minicuotas…)',
            ]" />
            <div class="grid gap-3 sm:grid-cols-2">
                <div x-show="['venta', 'ingreso', 'unico'].includes(tipo)"><x-floating-input id="p_descripcion" label="Descripción (opcional)" maxlength="100" /></div>
                <x-floating-input id="p_mes" type="month" x-bind:aria-label="['gasto', 'ingreso'].includes(tipo) ? 'Desde' : 'Mes'" label="Mes (o desde)" :value="$mesInicio" />
                <div x-show="['gasto', 'ingreso'].includes(tipo)"><x-floating-input id="p_hasta" type="month" label="Hasta (opcional)" /></div>
                <div x-show="! ['pagar', 'cuotas'].includes(tipo)"><x-floating-money id="p_monto" label="Monto" :symbol="$simbolo" /></div>
                <div x-show="tipo === 'pagar'" x-cloak><x-floating-money id="p_monto_pagar" label="Monto (vacío = toda la deuda)" :symbol="$simbolo" /></div>
                <div x-show="['prestamo', 'cuotas'].includes(tipo)" class="contents">
                    <x-floating-input id="p_tasa" label="Tasa anual %" type="number" step="0.01" min="0" />
                    <x-floating-input id="p_plazo" label="Plazo (meses)" type="number" min="1" max="600" value="60" />
                    <x-floating-money id="p_comision" label="Comisión (opcional)" :symbol="$simbolo" />
                </div>
                <div x-show="tipo === 'prestamo'"><x-floating-money id="p_cuota" label="Cuota (opcional, si te la dieron)" :symbol="$simbolo" /></div>
                <div x-show="tipo === 'unico'" class="sm:col-span-2"><x-checkbox id="p_sale" ayuda="Márcalo si es dinero que sale (la renta de abril); sin marcar, entra (aguinaldo).">Es un gasto</x-checkbox></div>
            </div>
            <div x-show="['prestamo', 'venta'].includes(tipo)"><x-floating-select id="p_destino" label="¿Qué deudas paga primero? (en ese orden)" multiple :options="$opcionesDeudas" /></div>
            <div x-show="['pagar', 'cuotas'].includes(tipo)" x-cloak><x-floating-select id="p_deuda" label="Deuda" :options="$opcionesDeudas" /></div>
            <p x-show="error" x-text="error" class="text-xs text-red-600 dark:text-red-400"></p>
            <div class="flex justify-end">
                <x-secondary-button type="button" x-on:click="if (tipo === 'pagar') { document.getElementById('p_monto').value = document.getElementById('p_monto_pagar').value } agregar()">Agregar palanca</x-secondary-button>
            </div>
        </div>
    </div>

    <div class="flex justify-end gap-3">
        <x-secondary-button type="button" x-on:click="$dispatch('close-modal', 'escenario-modal')">Cancelar</x-secondary-button>
        <x-primary-button type="submit" x-bind:disabled="loading">{{ $editando ? 'Guardar cambios' : 'Guardar escenario' }}</x-primary-button>
    </div>
</form>
