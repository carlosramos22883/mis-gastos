{{-- Texto de una deuda o un pago que lleva al movimiento que generó (efectivo, cuenta o tarjeta); si solo se anotó, texto plano. --}}
@php
    $clase = match (true) {
        (bool) $registro->movimiento_efectivo_id => 'efectivo',
        (bool) $registro->movimiento_cuenta_id => 'cuenta',
        (bool) ($registro->movimiento_tarjeta_id ?? null) => 'tarjeta',
        default => null,
    };
@endphp
@if ($clase)
    <a href="{{ route('movimientos.ver', [$clase, $registro->{'movimiento_'.$clase.'_id'}, 'desde' => $desde]) }}" class="text-primary-600 hover:underline dark:text-primary-400" title="Ver el movimiento">{{ $texto }}</a>
@else
    {{ $texto }}
@endif
