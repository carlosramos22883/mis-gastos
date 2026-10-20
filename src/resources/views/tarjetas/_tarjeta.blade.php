{{-- Tarjeta visual: color de la tarjeta, disponible y uso del límite --}}
@php
    $simbolo = auth()->user()->monedaPreferida?->simbolo ?? '$';
    $texto = $tarjeta->textColor();
    $tag = ($enlace ?? false) ? 'a' : 'div';
    $miembros = auth()->user()->miembrosHogar;
    $titular = $miembros->count() > 1 ? $miembros->firstWhere('id', $tarjeta->user_id)?->name : null;
    $uso = (float) $tarjeta->limite_credito > 0 ? min(100, max(0, $tarjeta->deuda / (float) $tarjeta->limite_credito * 100)) : 0;
@endphp
<div class="{{ $tarjeta->activo ? '' : 'opacity-60' }}" data-record-id="{{ $tarjeta->id }}">
    <{{ $tag }} @if ($tag === 'a') href="{{ route('tarjetas.show', $tarjeta) }}" @endif class="block rounded-2xl p-5 shadow-lg transition hover:shadow-xl"
        style="background: linear-gradient(135deg, {{ $tarjeta->color }} 0%, {{ $tarjeta->color }}cc 100%); color: {{ $texto }}">
        <div class="flex items-start justify-between gap-2">
            <div class="flex min-w-0 items-center gap-3">
                <x-logo-banco :banco="$tarjeta->banco" />
                <div class="min-w-0">
                    <p class="truncate text-lg font-semibold leading-tight">{{ $tarjeta->nombre }}</p>
                    <p class="truncate text-xs opacity-80">{{ $tarjeta->banco?->nombre }}</p>
                </div>
            </div>
            @unless ($tarjeta->activo)<span class="shrink-0 text-xs font-semibold opacity-90">Inactiva</span>@endunless
        </div>
        <p class="mt-4 font-mono text-sm tracking-widest opacity-80">•••• •••• •••• {{ $tarjeta->ultimos_digitos ?? '••••' }}</p>
        @if ($titular)<p class="mt-1 truncate text-xs font-medium uppercase tracking-wide opacity-80">{{ $titular }}</p>@endif
        <div class="mt-4 flex items-end justify-between">
            <div>
                <p class="text-xs opacity-80">Disponible</p>
                <p class="text-2xl font-bold">{{ $simbolo }} {{ number_format($tarjeta->disponible, 2) }}</p>
                <p class="text-xs opacity-80">de {{ $simbolo }} {{ number_format((float) $tarjeta->limite_credito, 2) }}</p>
            </div>
            <x-logo-red :marca="$tarjeta->marcaRed" />
        </div>
        <div class="mt-2 h-1.5 overflow-hidden rounded-full" style="background-color: {{ $texto }}33">
            <div class="h-full rounded-full" style="width: {{ $uso }}%; background-color: {{ $texto }}"></div>
        </div>
    </{{ $tag }}>
    <dl class="mt-3 grid grid-cols-3 gap-2 px-1 text-xs text-gray-600 dark:text-gray-300">
        <div><dt class="text-gray-400 dark:text-gray-500">Deuda</dt><dd class="font-semibold">{{ $simbolo }} {{ number_format($tarjeta->deuda, 2) }}</dd></div>
        <div><dt class="text-gray-400 dark:text-gray-500">Próximo corte</dt><dd class="font-semibold">{{ $tarjeta->proximo_corte->format('d/m/Y') }}</dd></div>
        <div>
            <dt class="text-gray-400 dark:text-gray-500">Por pagar</dt>
            <dd class="font-semibold {{ $tarjeta->por_pagar?->fecha_esperada?->isPast() ? 'text-red-600 dark:text-red-400' : '' }}">
                {{ $tarjeta->por_pagar ? $simbolo.' '.number_format((float) $tarjeta->por_pagar->saldo_pendiente, 2).($tarjeta->por_pagar->fecha_esperada ? ' · '.$tarjeta->por_pagar->fecha_esperada->format('d/m') : '') : '—' }}
            </dd>
        </div>
    </dl>
</div>
