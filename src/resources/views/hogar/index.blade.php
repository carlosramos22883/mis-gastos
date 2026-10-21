<x-app-layout>
    <x-slot name="header"><h1>Hogar</h1></x-slot>
    <div class="max-w-5xl mx-auto space-y-6">
        @foreach ($recibidas as $invitacion)
            <div class="flex flex-wrap items-center justify-between gap-4 rounded-lg border border-primary-200 bg-primary-50 p-4 dark:border-primary-800 dark:bg-primary-900/30">
                <p class="text-sm text-primary-800 dark:text-primary-200">
                    <strong>{{ $invitacion->invitadoPor?->name ?? 'Alguien' }}</strong> te invitó a unirte a <strong>«{{ $invitacion->hogar->nombre }}»</strong>.
                    @if ($hogar->miembros->count() === 1)
                        Lo que tienes registrado se sumará a ese hogar.
                    @else
                        Tus registros se quedarán en tu hogar actual.
                    @endif
                </p>
                <div class="flex gap-2">
                    <x-secondary-button type="button" x-data x-on:click="confirmAction('¿Rechazar invitación?', '', '{{ route('hogar.rechazar', $invitacion) }}')">Rechazar</x-secondary-button>
                    <x-primary-button type="button" x-data x-on:click="confirmAction('¿Unirte al hogar?', {{ \Illuminate\Support\Js::from('Compartirás el efectivo, las cuentas, las tarjetas y los compromisos de «'.$invitacion->hogar->nombre.'».') }}, '{{ route('hogar.aceptar', $invitacion) }}')">Aceptar</x-primary-button>
                </div>
            </div>
        @endforeach

        <div class="rounded-lg bg-white p-6 shadow dark:bg-gray-800">
            @if ($esAdmin)
                @can('hogar.edit')
                    <form x-data="ajaxForm(async function(data) { await showAlert('success', '¡Listo!', data.message); window.location.reload(); })"
                        @submit.prevent="submit" method="POST" action="{{ route('hogar.update') }}" class="flex flex-wrap items-start gap-3" novalidate>
                        @csrf @method('PATCH')
                        <div class="min-w-64 flex-1"><x-floating-input id="nombre" label="Nombre del hogar" maxlength="100" :value="$hogar->nombre" required /></div>
                        <x-primary-button type="submit" x-bind:disabled="loading">Guardar</x-primary-button>
                    </form>
                @else
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ $hogar->nombre }}</h2>
                @endcan
            @else
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ $hogar->nombre }}</h2>
            @endif
            <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">
                Los miembros comparten un solo fondo: efectivo, ciclo (corte el día {{ auth()->user()->fecha_corte_dia === 31 ? 'último' : auth()->user()->fecha_corte_dia }}), categorías, compromisos, cuentas y tarjetas.
                Cada movimiento guarda quién lo hizo, y cada cuenta o tarjeta su titular.
            </p>
        </div>

        <div class="rounded-lg bg-white shadow dark:bg-gray-800">
            <h2 class="border-b border-gray-200 px-6 py-4 font-semibold text-gray-900 dark:border-gray-700 dark:text-gray-100">Miembros</h2>
            <ul class="divide-y divide-gray-200 dark:divide-gray-700">
                @foreach ($hogar->miembros as $miembro)
                    <li class="flex flex-wrap items-center justify-between gap-3 px-6 py-4">
                        <div class="flex items-center gap-3">
                            @include('hogar._avatar', ['persona' => $miembro])
                            <div>
                                <p class="font-medium text-gray-900 dark:text-gray-100">{{ $miembro->name }} @if ($miembro->is(auth()->user()))<span class="text-xs text-gray-500">(tú)</span>@endif</p>
                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ $miembro->email }}</p>
                            </div>
                        </div>
                        <div class="flex items-center gap-3">
                            @if ($hogar->esAdministrador($miembro))
                                <span class="rounded-full bg-primary-100 px-2 py-0.5 text-xs font-medium text-primary-700 dark:bg-primary-900/40 dark:text-primary-300">Administra</span>
                            @endif
                            @if ($miembro->is(auth()->user()))
                                @if ($hogar->miembros->count() > 1)
                                    <x-secondary-button type="button" x-data x-on:click="confirmAction('¿Salir del hogar?', 'Lo registrado se queda en el hogar y tú empezarás con uno propio, vacío.', '{{ route('hogar.salir') }}')">Salir del hogar</x-secondary-button>
                                @endif
                            @elseif ($esAdmin)
                                @can('hogar.edit')
                                    <x-danger-button type="button" x-data x-on:click="confirmAction({{ \Illuminate\Support\Js::from('¿Quitar a '.$miembro->name.'?') }}, 'Lo que registró se queda en el hogar.', '{{ route('hogar.quitar', $miembro) }}', 'DELETE')">Quitar</x-danger-button>
                                @endcan
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        </div>

        @if ($esAdmin)
            @can('hogar.edit')
                <div class="rounded-lg bg-white p-6 shadow dark:bg-gray-800 space-y-4">
                    <h2 class="font-semibold text-gray-900 dark:text-gray-100">Invitar a alguien</h2>
                    <form x-data="ajaxForm(async function(data) { await showAlert('success', '¡Listo!', data.message); window.location.reload(); })"
                        @submit.prevent="submit" method="POST" action="{{ route('hogar.invitar') }}" class="flex flex-wrap items-start gap-3" novalidate>
                        @csrf
                        <div class="min-w-64 flex-1"><x-floating-input id="email" type="email" label="Correo electrónico" maxlength="255" required /></div>
                        <x-primary-button type="submit" x-bind:disabled="loading">Enviar invitación</x-primary-button>
                    </form>
                    @if ($enviadas->isNotEmpty())
                        <ul class="divide-y divide-gray-200 text-sm dark:divide-gray-700">
                            @foreach ($enviadas as $invitacion)
                                <li class="flex items-center justify-between py-2">
                                    <span class="text-gray-700 dark:text-gray-300">{{ $invitacion->email }} <span class="text-xs text-gray-500">· pendiente desde {{ $invitacion->created_at->format('d/m/Y') }}</span></span>
                                    <button type="button" x-data x-on:click="confirmAction('¿Cancelar invitación?', '', '{{ route('hogar.cancelar', $invitacion) }}', 'DELETE')" class="text-sm text-red-600 hover:underline dark:text-red-400">Cancelar</button>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            @endcan
        @endif
    </div>
</x-app-layout>
