@php($simbolo = auth()->user()->monedaPreferida?->simbolo ?? '$')
@forelse($compromisos as $compromiso)
@php($estado = $compromiso->estadoVisual($hoy, $inicio))
<tr data-record-id="{{ $compromiso->id }}" class="border-t border-gray-200 dark:border-gray-700">
    <td class="px-6 py-4">
        <div class="font-medium text-gray-900 dark:text-gray-100">{{ $compromiso->descripcion }}</div>
        <div class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
            {{ collect([
                $compromiso->categoria?->nombre,
                $compromiso->numero_cuota ? 'Cuota '.$compromiso->numero_cuota.($compromiso->total_cuotas ? ' de '.$compromiso->total_cuotas : '') : null,
                $compromiso->recurrente?->descripcionFrecuencia(),
            ])->filter()->implode(' · ') }}
        </div>
    </td>
    <td class="px-6 py-4 whitespace-nowrap">{{ $compromiso->fecha_esperada?->format('d/m/Y') ?? 'Sin fecha' }}</td>
    <td class="px-6 py-4 whitespace-nowrap">
        {{ $simbolo }} {{ number_format((float) $compromiso->monto, 2) }}
        @if ($compromiso->estado === \App\Models\Compromiso::PENDIENTE && $compromiso->montoPagado() > 0)
            <div class="text-xs text-amber-700 dark:text-amber-300">Pendiente: {{ $simbolo }} {{ number_format((float) $compromiso->saldo_pendiente, 2) }}</div>
        @endif
    </td>
    <td class="px-6 py-4">@include('compromisos._estado', ['estado' => $estado])</td>
    <td class="px-6 py-4 sticky-col-right"><div class="flex justify-end gap-2">
        @if ($compromiso->estado === \App\Models\Compromiso::PENDIENTE)
            @canany(['efectivo.create', 'cuentas.create'])
                <x-primary-button type="button" class="py-1.5 px-2" title="Pagar" x-on:click.prevent="$dispatch('load-pago-modal-form', '{{ route('compromisos.pagar', $compromiso) }}'); $dispatch('open-modal', 'pago-modal')"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z" /></svg></x-primary-button>
            @endcanany
            @can('compromisos.edit')
                <x-secondary-button type="button" class="py-1.5 px-2" title="Editar" x-on:click.prevent="$dispatch('load-compromiso-modal-form', '{{ route('compromisos.edit', $compromiso) }}?modal=1'); $dispatch('open-modal', 'compromiso-modal')"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg></x-secondary-button>
            @endcan
        @endif
        <x-secondary-button type="button" class="py-1.5 px-2" title="Ver detalle" x-on:click.prevent="window.location='{{ route('compromisos.show', $compromiso) }}'"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" /></svg></x-secondary-button>
        @if ($compromiso->estado === \App\Models\Compromiso::PENDIENTE)
            @can('compromisos.delete')
                <x-danger-button type="button" class="py-1.5 px-2" title="Cancelar" x-on:click.prevent="cancelCommitment(@js($compromiso->descripcion), '{{ route('compromisos.destroy', $compromiso) }}', {{ $compromiso->recurrente?->activo ? 'true' : 'false' }})"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg></x-danger-button>
            @endcan
        @endif
    </div></td>
</tr>
@empty
<tr><td colspan="5" class="px-6 py-10 text-center text-gray-500">No hay compromisos en este período.</td></tr>
@endforelse
