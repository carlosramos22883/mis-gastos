@php
    $simbolo = auth()->user()->monedaPreferida?->simbolo ?? '$';
    $dinero = fn ($v) => $simbolo.' '.number_format((float) $v, 2);
    $nombresMes = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];
    $mesTexto = fn (?string $m) => $m ? $nombresMes[(int) substr($m, 5, 2) - 1].' '.substr($m, 0, 4) : null;
    $deudas = collect($base['deudas']);
    $cuotasEfectivo = (float) $deudas->where('tipo', 'banco')->sum('cuota_efectivo');
    $sobra = $base['ingreso'] - $base['gasto'] - $cuotasEfectivo;
    $grupos = [
        ['Tarjetas', $deudas->where('tipo', 'tarjeta')],
        ['Préstamos del banco', $deudas->where('tipo', 'banco')->where('hipoteca', false)],
        ['Personas', $deudas->where('tipo', 'personal')],
        ['Hipoteca (va aparte)', $deudas->where('tipo', 'banco')->where('hipoteca', true)],
    ];

    // Gráfica: deuda total sin hipoteca, mes a mes, una línea por escenario
    $series = $comparar->map(fn ($e, $i) => ['nombre' => $e['nombre'], 'color' => 'var(--s'.($i + 1).')', 'valores' => array_column($e['resultado']['meses'], 'total')])->values();
    $meses = collect($comparar->max(fn ($e) => count($e['resultado']['meses'])) ? $comparar->sortByDesc(fn ($e) => count($e['resultado']['meses']))->first()['resultado']['meses'] : [])->pluck('mes')->all();
    $n = max(1, count($meses) - 1);
    $maximo = max(1, $series->flatMap(fn ($s) => $s['valores'])->max() ?? 1);
    [$ancho, $alto, $izq, $der, $arr, $aba] = [800, 280, 64, 120, 16, 28];
    $x = fn ($i) => round($izq + ($ancho - $izq - $der) * $i / $n, 1);
    $y = fn ($v) => round($arr + ($alto - $arr - $aba) * (1 - $v / $maximo), 1);
    $paso = 10 ** floor(log10($maximo)) * ($maximo / 10 ** floor(log10($maximo)) > 5 ? 2 : 1);
    $marcasY = range(0, $maximo, $paso);
@endphp
<x-app-layout>
    <div class="max-w-7xl mx-auto space-y-6" x-data x-on:refresh-table.window="window.location.reload()">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">Proyecciones</h1>
                <p class="text-sm text-gray-600 dark:text-gray-300">¿Cuándo salgo de mis deudas? Simulado con tus datos reales desde {{ $mesTexto($base['mes_inicio']) }}.</p>
            </div>
            @can('proyecciones.create')
                <x-primary-button type="button" x-data x-on:click="$dispatch('load-escenario-modal-form', '{{ route('proyecciones.create') }}?ver_actual={{ implode(',', $ver) }}'); $dispatch('open-modal', 'escenario-modal')">Nuevo escenario</x-primary-button>
            @endcan
        </div>

        {{-- La foto de hoy --}}
        <section class="rounded-lg bg-white p-6 shadow dark:bg-gray-800" x-data="{ detalle: false }">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100">Tu situación hoy</h2>
                <button type="button" class="text-xs text-primary-600 hover:underline dark:text-primary-400" x-on:click="detalle = !detalle" x-text="detalle ? 'Ocultar las deudas' : 'Ver cada deuda'"></button>
            </div>
            <dl class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ([
                    ['Entra al mes', $dinero($base['ingreso']), 'neto de todos los sueldos'],
                    ['Gasto de vida', $dinero($base['gasto']), $base['gasto_origen'] === 'tope' ? 'el tope del ciclo' : 'promedio de los últimos 3 ciclos'],
                    ['Cuotas en efectivo', $dinero($cuotasEfectivo), 'préstamos del banco (sin planilla)'],
                    ['Sobra para deudas', $dinero($sobra), 'cada mes, si todo sale como está'],
                ] as [$etiqueta, $valor, $nota])
                    <div>
                        <dt class="text-xs text-gray-500 dark:text-gray-400">{{ $etiqueta }}</dt>
                        <dd class="text-2xl font-bold {{ $etiqueta === 'Sobra para deudas' && $sobra < 0 ? 'text-red-600 dark:text-red-400' : 'text-gray-900 dark:text-gray-100' }}">{{ $valor }}</dd>
                        <dd class="text-xs text-gray-500 dark:text-gray-400">{{ $nota }}</dd>
                    </div>
                @endforeach
            </dl>
            <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($grupos as [$titulo, $lista])
                    <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ $titulo }} · {{ $lista->count() }}</p>
                        <p class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ $dinero($lista->sum('saldo')) }}</p>
                        <ul x-show="detalle" x-collapse class="mt-2 space-y-1 text-xs text-gray-600 dark:text-gray-300">
                            @foreach ($lista as $d)
                                <li class="flex justify-between gap-2">
                                    <span class="truncate">{{ $d['nombre'] }}@if ($d['tasa'] > 0)<span class="text-gray-400"> · {{ rtrim(rtrim(number_format($d['tasa'], 2), '0'), '.') }} %{{ ! empty($d['tasa_supuesta']) ? ' (supuesta)' : '' }}</span>@endif</span>
                                    <span class="whitespace-nowrap">{{ $dinero($d['saldo']) }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </div>
        </section>

        {{-- Qué escenarios comparar --}}
        <section class="rounded-lg bg-white p-6 shadow dark:bg-gray-800">
            <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100">Escenarios</h2>
            @if ($escenarios->isEmpty())
                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Aún no tienes escenarios. Crea uno: «tomo el préstamo y vendo el Kia», «gasto $1,600 en vez de $1,800»… Siempre se compara con «Si sigo igual».</p>
            @else
                <form method="GET" action="{{ route('proyecciones.index') }}" class="mt-3 space-y-2" x-data>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Elige hasta {{ \App\Http\Controllers\ProyeccionController::MAX_COMPARAR }} para comparar con «Si sigo igual».</p>
                    <ul class="divide-y divide-gray-100 dark:divide-gray-700">
                        @foreach ($escenarios as $e)
                            <li class="flex flex-wrap items-center justify-between gap-2 py-2">
                                <x-checkbox name="ver[]" :value="$e->id" :checked="in_array($e->id, $ver, true)" x-on:change="$el.form.requestSubmit()"
                                    :ayuda="count($e->datos['palancas'] ?? []).' palanca(s) · tarjetas: '.(($e->datos['modo_tarjetas'] ?? '') === 'rotar' ? 'rotar con avances ('.($e->datos['perdida_rotar'] ?? 1).' %)' : 'pagan intereses')">{{ $e->nombre }}</x-checkbox>
                                <div class="flex gap-2">
                                    @can('proyecciones.edit')
                                        <x-secondary-button type="button" class="py-1.5 px-2" title="Editar" x-on:click="$dispatch('load-escenario-modal-form', '{{ route('proyecciones.edit', $e) }}?ver_actual={{ implode(',', $ver) }}'); $dispatch('open-modal', 'escenario-modal')">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg>
                                        </x-secondary-button>
                                    @endcan
                                    @can('proyecciones.delete')
                                        <x-danger-button type="button" class="py-1.5 px-2" title="Eliminar" x-on:click="deleteItem({{ $e->id }}, {{ \Illuminate\Support\Js::from($e->nombre) }}, '{{ route('proyecciones.destroy', $e) }}')">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                                        </x-danger-button>
                                    @endcan
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </form>
            @endif
        </section>

        {{-- Comparación --}}
        <section class="viz-proy overflow-x-auto rounded-lg bg-white shadow dark:bg-gray-800">
            <h2 class="px-6 pt-5 text-base font-semibold text-gray-900 dark:text-gray-100">Comparación</h2>
            <table class="mt-3 min-w-full text-sm text-gray-700 dark:text-gray-300">
                <thead class="text-xs uppercase bg-cabecera text-gray-600 dark:bg-gray-700 dark:text-gray-400">
                    <tr>
                        <th class="px-6 py-3 text-left">&nbsp;</th>
                        @foreach ($comparar as $i => $e)
                            <th class="px-4 py-3 text-right normal-case"><span class="inline-flex items-center gap-2"><span class="inline-block h-0.5 w-4" style="background: var(--s{{ $i + 1 }})" aria-hidden="true"></span>{{ $e['nombre'] }}</span></th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @php($fila = fn (string $etiqueta, callable $valor, bool $fecha = false) => [$etiqueta, $valor, $fecha])
                    @foreach ([
                        $fila('Tarjetas en $0', fn ($r) => $r['hitos']['tarjetas'], true),
                        $fila('Personas pagadas', fn ($r) => $r['hitos']['personas'], true),
                        $fila('Sin deudas (menos la hipoteca)', fn ($r) => $r['hitos']['sin_deudas'], true),
                        $fila('Intereses de tarjetas', fn ($r) => $r['totales']['intereses_tarjetas']),
                        $fila('Perdido al rotar con avances', fn ($r) => $r['totales']['perdida_rotar']),
                        $fila('Préstamos nuevos: intereses y comisiones', fn ($r) => $r['totales']['intereses_prestamos_nuevos'] + $r['totales']['comisiones']),
                        $fila('Lo que no alcanzó (va a tarjetas)', fn ($r) => $r['totales']['faltante']),
                    ] as [$etiqueta, $valor, $esFecha])
                        <tr class="border-t border-gray-200 dark:border-gray-700">
                            <th scope="row" class="px-6 py-3 text-left font-medium">{{ $etiqueta }}</th>
                            @foreach ($comparar as $e)
                                @php($v = $valor($e['resultado']))
                                <td class="px-4 py-3 text-right whitespace-nowrap {{ $esFecha ? 'font-semibold' : '' }} {{ (! $esFecha && $etiqueta === 'Lo que no alcanzó (va a tarjetas)' && $v > 0) || ($esFecha && ! $v) ? 'text-red-600 dark:text-red-400' : '' }}">
                                    @if ($esFecha)
                                        {{ $v ? $mesTexto($v) : 'En 15 años no' }}
                                    @else
                                        {{ $dinero($v) }}@if ($etiqueta === 'Lo que no alcanzó (va a tarjetas)' && $e['resultado']['totales']['meses_con_faltante'])<span class="block text-xs font-normal">en {{ $e['resultado']['totales']['meses_con_faltante'] }} mes(es)</span>@endif
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>

        {{-- Gráfica: deuda total sin hipoteca --}}
        <section class="viz-proy rounded-lg bg-white p-6 shadow dark:bg-gray-800"
            x-data="{ tabla: false, i: null, meses: {{ \Illuminate\Support\Js::from(array_map($mesTexto, $meses)) }}, series: {{ \Illuminate\Support\Js::from($series) }},
                mover(e) { const r = e.currentTarget.getBoundingClientRect(); const px = (e.clientX - r.left) / r.width * {{ $ancho }}; const k = Math.round((px - {{ $izq }}) / ({{ $ancho - $izq - $der }}) * {{ $n }}); this.i = Math.max(0, Math.min({{ $n }}, k)); },
                dinero(v) { return v === undefined ? '—' : '{{ $simbolo }} ' + Number(v).toLocaleString('es-SV', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); } }">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100">Cuánto debes, mes a mes <span class="text-sm font-normal text-gray-500 dark:text-gray-400">(sin la hipoteca)</span></h2>
                <button type="button" class="text-xs text-primary-600 hover:underline dark:text-primary-400" x-on:click="tabla = !tabla" x-text="tabla ? 'Ver gráfica' : 'Ver como tabla'"></button>
            </div>
            {{-- Leyenda --}}
            <ul class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-600 dark:text-gray-300">
                @foreach ($series as $i => $s)
                    <li class="inline-flex items-center gap-2"><span class="inline-block h-0.5 w-5" style="background: {{ $s['color'] }}" aria-hidden="true"></span>{{ $s['nombre'] }}</li>
                @endforeach
            </ul>
            <div x-show="!tabla" class="relative mt-3">
                <svg viewBox="0 0 {{ $ancho }} {{ $alto }}" class="h-auto w-full" role="img" aria-label="Deuda total sin hipoteca por mes, por escenario">
                    @foreach ($marcasY as $m)
                        <line x1="{{ $izq }}" x2="{{ $ancho - $der }}" y1="{{ $y($m) }}" y2="{{ $y($m) }}" stroke="var(--grid)" stroke-width="1" />
                        <text x="{{ $izq - 8 }}" y="{{ $y($m) + 4 }}" text-anchor="end" class="fill-gray-500 text-[11px] dark:fill-gray-400">{{ $m >= 1000 ? number_format($m / 1000).'k' : number_format($m) }}</text>
                    @endforeach
                    @foreach ($meses as $k => $mes)
                        @if (str_ends_with($mes, '-01'))
                            <text x="{{ $x($k) }}" y="{{ $alto - 8 }}" text-anchor="middle" class="fill-gray-500 text-[11px] dark:fill-gray-400">{{ substr($mes, 0, 4) }}</text>
                        @endif
                    @endforeach
                    @foreach ($series as $s)
                        @php($puntos = collect($s['valores'])->map(fn ($v, $k) => $x($k).','.$y($v))->implode(' '))
                        <polyline points="{{ $puntos }}" fill="none" stroke="{{ $s['color'] }}" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" class="linea-dibuja" pathLength="1" />
                        {{-- Etiqueta directa al final de cada línea --}}
                        @php($ultimo = array_key_last($s['valores']))
                        <circle cx="{{ $x($ultimo) }}" cy="{{ $y(end($s['valores'])) }}" r="4" fill="{{ $s['color'] }}" stroke="var(--surface)" stroke-width="2" />
                    @endforeach
                    @foreach ($series->sortBy(fn ($s) => end($s['valores']))->values() as $j => $s)
                        @php($ultimo = array_key_last($s['valores']))
                        <text x="{{ min($ancho - $der + 8, $x($ultimo) + 8) }}" y="{{ min($alto - $aba - 4, $y(end($s['valores'])) - 6 - $j * 13) }}" class="fill-gray-700 text-[11px] dark:fill-gray-200">{{ \Illuminate\Support\Str::limit($s['nombre'], 18) }}</text>
                    @endforeach
                    {{-- Cruz que sigue al puntero --}}
                    <line x-show="i !== null" x-bind:x1="{{ $izq }} + ({{ $ancho - $izq - $der }}) * i / {{ $n }}" x-bind:x2="{{ $izq }} + ({{ $ancho - $izq - $der }}) * i / {{ $n }}" y1="{{ $arr }}" y2="{{ $alto - $aba }}" stroke="currentColor" class="text-gray-400" stroke-width="1" />
                    <rect x="{{ $izq }}" y="{{ $arr }}" width="{{ $ancho - $izq - $der }}" height="{{ $alto - $arr - $aba }}" fill="transparent" x-on:pointermove="mover($event)" x-on:pointerleave="i = null" />
                </svg>
                <div x-show="i !== null" x-cloak class="pointer-events-none absolute right-2 top-2 rounded-lg border border-gray-200 bg-white/95 px-3 py-2 text-xs shadow dark:border-gray-600 dark:bg-gray-800/95">
                    <p class="font-semibold text-gray-900 dark:text-gray-100" x-text="meses[i]"></p>
                    <template x-for="s in series" :key="s.nombre">
                        <p class="flex items-center gap-2"><span class="inline-block h-0.5 w-3" :style="'background:' + s.color"></span><strong class="text-gray-900 dark:text-gray-100" x-text="dinero(s.valores[i])"></strong><span class="text-gray-500 dark:text-gray-400" x-text="s.nombre"></span></p>
                    </template>
                </div>
            </div>
            <div x-show="tabla" x-cloak class="mt-3 max-h-96 overflow-auto">
                <table class="min-w-full text-xs text-gray-700 dark:text-gray-300">
                    <thead class="sticky top-0 bg-cabecera uppercase text-gray-600 dark:bg-gray-700 dark:text-gray-400">
                        <tr><th class="px-3 py-2 text-left">Mes</th>@foreach ($series as $s)<th class="px-3 py-2 text-right normal-case">{{ $s['nombre'] }}</th>@endforeach</tr>
                    </thead>
                    <tbody>
                        @foreach ($meses as $k => $mes)
                            <tr class="border-t border-gray-100 dark:border-gray-700"><td class="px-3 py-1.5">{{ $mesTexto($mes) }}</td>@foreach ($series as $s)<td class="px-3 py-1.5 text-right">{{ isset($s['valores'][$k]) ? $dinero($s['valores'][$k]) : '—' }}</td>@endforeach</tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        {{-- Cuándo queda en cero cada deuda --}}
        <section class="rounded-lg bg-white p-6 shadow dark:bg-gray-800">
            <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100">Cuándo queda en cero cada deuda</h2>
            <div class="mt-3 overflow-x-auto">
                <table class="min-w-full text-sm text-gray-700 dark:text-gray-300">
                    <thead class="text-xs uppercase bg-cabecera text-gray-600 dark:bg-gray-700 dark:text-gray-400">
                        <tr><th class="px-4 py-2 text-left">Deuda</th>@foreach ($comparar as $e)<th class="px-4 py-2 text-right normal-case">{{ $e['nombre'] }}</th>@endforeach</tr>
                    </thead>
                    <tbody>
                        @foreach ($comparar->first()['resultado']['deudas'] as $k => $d)
                            @continue($d['hipoteca'])
                            <tr class="border-t border-gray-100 dark:border-gray-700">
                                <td class="px-4 py-2">{{ $d['nombre'] }}</td>
                                @foreach ($comparar as $e)
                                    @php($cero = collect($e['resultado']['deudas'])->firstWhere('clave', $d['clave'])['cero'] ?? null)
                                    <td class="px-4 py-2 text-right whitespace-nowrap {{ $cero ? '' : 'text-red-600 dark:text-red-400' }}">{{ $cero ? $mesTexto($cero) : 'Más de 15 años' }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">Es una proyección con supuestos: el ingreso y el gasto se mantienen, lo que sobra paga primero la deuda más cara y lo que falta se va a las tarjetas. Las tarjetas sin tasa registrada usan 40 %.</p>
        </section>
    </div>

    <x-ajax-modal name="escenario" maxWidth="3xl" />
</x-app-layout>
