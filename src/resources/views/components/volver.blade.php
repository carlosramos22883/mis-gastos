{{-- "← Volver a …" cuando se llegó desde otra pantalla (?desde=contacto:12). Ver App\Support\Volver. --}}
@php($volver = \App\Support\Volver::destino(request()))
@if ($volver)
    <x-secondary-button type="button" onclick="window.location='{{ $volver['url'] }}'">← {{ $volver['texto'] }}</x-secondary-button>
@endif
