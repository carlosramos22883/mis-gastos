@php($simbolo = auth()->user()->monedaPreferida?->simbolo ?? '$')
@if ($cuentas->isEmpty())
    <div class="rounded-lg bg-white p-10 text-center shadow dark:bg-gray-800">
        <p class="text-gray-600 dark:text-gray-300">Aún no tienes cuentas registradas.</p>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Agrega tu cuenta del banco o tu billetera digital (Chivo, Niu…) con el saldo que tiene hoy.</p>
    </div>
@else
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        @foreach ($cuentas as $cuenta)
            <div class="relative overflow-hidden rounded-lg bg-white shadow dark:bg-gray-800 {{ $cuenta->activo ? '' : 'opacity-60' }}" data-record-id="{{ $cuenta->id }}">
                <div class="absolute inset-y-0 left-0 w-1.5" style="background-color: {{ $cuenta->color }}"></div>
                <a href="{{ route('cuentas.show', $cuenta) }}" class="block p-5 pl-6 hover:bg-gray-50 dark:hover:bg-gray-700/40">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <p class="truncate font-semibold text-gray-900 dark:text-gray-100">{{ $cuenta->nombre }}</p>
                            <p class="truncate text-xs text-gray-500 dark:text-gray-400">
                                {{ collect([$cuenta->tipoCuenta?->nombre, $cuenta->banco?->nombre, $cuenta->numeroEnmascarado()])->filter()->implode(' · ') }}
                            </p>
                        </div>
                        @unless ($cuenta->activo)
                            <span class="shrink-0 rounded-md bg-gray-100 px-2 py-0.5 text-xs text-gray-600 dark:bg-gray-700 dark:text-gray-300">Inactiva</span>
                        @endunless
                    </div>
                    <p class="mt-4 text-2xl font-bold {{ $cuenta->saldo_actual > 0 ? 'text-gray-900 dark:text-gray-100' : 'text-gray-400' }}">{{ $simbolo }} {{ number_format($cuenta->saldo_actual, 2) }}</p>
                </a>
                <div class="flex justify-end gap-2 border-t border-gray-100 px-4 py-2 dark:border-gray-700">
                    @can('cuentas.edit')
                        <button type="button" class="text-xs font-medium text-primary-600 hover:underline dark:text-primary-400" x-on:click="$dispatch('load-cuenta-modal-form', '{{ route('cuentas.edit', $cuenta) }}'); $dispatch('open-modal', 'cuenta-modal')">Editar</button>
                    @endcan
                    @can('cuentas.delete')
                        <button type="button" class="text-xs font-medium text-red-600 hover:underline dark:text-red-400" x-on:click="deleteItem({{ $cuenta->id }}, @js($cuenta->nombre), '{{ route('cuentas.destroy', $cuenta) }}')">Eliminar</button>
                    @endcan
                </div>
            </div>
        @endforeach
    </div>
@endif
