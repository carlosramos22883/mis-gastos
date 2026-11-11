{{-- Botón "Me lo debe" de una fila de movimiento: liga ese gasto a una deuda de un contacto (la página necesita <x-ajax-modal name="deuda" />).
     Uso: @include('contactos._me_lo_debe', ['clase' => 'tarjeta', 'movimiento' => $m]) --}}
@can('deudores.create')
    @if (app(\App\Services\DeudorService::class)->vinculable($movimiento))
        <x-secondary-button type="button" class="py-1.5 px-2" title="Me lo debe (alguien te debe este gasto)"
            x-on:click.prevent="$dispatch('load-deuda-modal-form', '{{ route('movimientos.deuda.create', [$clase, $movimiento->id]) }}'); $dispatch('open-modal', 'deuda-modal')">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 15v-1a4 4 0 00-4-4H8m0 0l3 3m-3-3l3-3m9 14V5a2 2 0 00-2-2H6a2 2 0 00-2 2v16l4-2 4 2 4-2 4 2z" /></svg>
        </x-secondary-button>
    @endif
@endcan
