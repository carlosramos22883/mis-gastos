@php($simbolo = auth()->user()->monedaPreferida?->simbolo ?? '$')
@php($activos = $prestamos->where('activo', true))
<x-app-layout>
    <x-slot name="header"><h1>Préstamos</h1></x-slot>
    <div class="max-w-7xl mx-auto space-y-6" x-data x-on:refresh-table.window="$event.detail?.redirect ? window.location = $event.detail.redirect : window.location.reload()">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div class="flex flex-wrap gap-8">
                <div>
                    <p class="text-sm text-gray-500 dark:text-gray-400">Total que deben</p>
                    <p class="text-3xl font-bold text-primary-600 dark:text-primary-400">{{ $simbolo }} {{ number_format($activos->sum(fn ($p) => $p->resumen['saldo']), 2) }}</p>
                </div>
                <div>
                    <p class="text-sm text-gray-500 dark:text-gray-400">Cuotas al mes</p>
                    <p class="text-3xl font-bold text-gray-900 dark:text-gray-100">{{ $simbolo }} {{ number_format($activos->sum(fn ($p) => $p->resumen['saldo'] > 0 ? ($p->resumen['cuota_total'] ?? 0) : 0), 2) }}</p>
                </div>
            </div>
            @can('prestamos.create')
                <x-primary-button type="button" x-on:click="$dispatch('load-prestamo-modal-form', '{{ route('prestamos.create') }}'); $dispatch('open-modal', 'prestamo-modal')">Nuevo préstamo</x-primary-button>
            @endcan
        </div>
        @if ($prestamos->isEmpty())
            <div class="rounded-lg bg-white p-10 text-center shadow dark:bg-gray-800">
                <p class="text-gray-600 dark:text-gray-300">No tienes préstamos registrados.</p>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Registra tus préstamos (banco o personas) con el saldo de hoy y verás cuándo terminas, cuánto pagas de intereses y cuánto te ahorras si abonas.</p>
            </div>
        @else
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($prestamos as $prestamo)
                    @php($r = $prestamo->resumen)
                    <a href="{{ route('prestamos.show', $prestamo) }}" class="relative block overflow-hidden rounded-lg bg-white p-5 pl-6 shadow hover:bg-gray-50 dark:bg-gray-800 dark:hover:bg-gray-700/40 {{ $prestamo->activo ? '' : 'opacity-60' }}" data-record-id="{{ $prestamo->id }}">
                        <span class="absolute inset-y-0 left-0 w-1.5" style="background-color: {{ $prestamo->color }}"></span>
                        <div class="flex items-center gap-3">
                            {{-- Con un banco: su logo; con una persona (contacto): su foto --}}
                            @if (! $prestamo->banco && $prestamo->contacto)<x-avatar-contacto :contacto="$prestamo->contacto" />@else<x-logo-banco :banco="$prestamo->banco" />@endif
                            <div class="min-w-0">
                                <p class="truncate font-semibold text-gray-900 dark:text-gray-100">@include('hogar._por', ['id' => $prestamo->user_id]){{ $prestamo->nombre }}</p>
                                <p class="truncate text-xs text-gray-500 dark:text-gray-400">{{ $prestamo->nombreAcreedor() }} · {{ (float) $prestamo->tasa_anual > 0 ? rtrim(rtrim(number_format((float) $prestamo->tasa_anual, 3), '0'), '.').' %' : 'sin interés' }}</p>
                            </div>
                        </div>
                        <p class="mt-4 text-2xl font-bold {{ $r['saldo'] > 0 ? 'text-gray-900 dark:text-gray-100' : 'text-green-600' }}">{{ $r['saldo'] > 0 ? $simbolo.' '.number_format($r['saldo'], 2) : 'Pagado' }}</p>
                        <div class="mt-2 h-2 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700"><div class="h-full rounded-full" style="width: {{ $r['avance'] }}%; background-color: {{ $prestamo->color }}"></div></div>
                        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                            {{ $r['avance'] }} % pagado
                            @if ($r['cuota_total']) · cuota {{ $simbolo }} {{ number_format($r['cuota_total'], 2) }} @endif
                            @if ($r['fecha_fin']) · termina {{ \Carbon\Carbon::parse($r['fecha_fin'])->translatedFormat('M Y') }} @endif
                        </p>
                    </a>
                @endforeach
            </div>
        @endif
    </div>
    <x-ajax-modal name="prestamo" />
</x-app-layout>
