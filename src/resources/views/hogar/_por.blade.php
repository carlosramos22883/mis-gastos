{{-- Iniciales de quien hizo el registro (o del titular), solo en hogares de más de un miembro. Uso: @include('hogar._por', ['id' => $registro->user_id]) --}}
@php($miembros = auth()->user()->miembrosHogar)
@if ($miembros->count() > 1 && ($persona = $miembros->firstWhere('id', $id)))
    <span class="mr-2 inline-block align-middle">@include('hogar._avatar', ['persona' => $persona, 'size' => 'sm'])</span>
@endif
