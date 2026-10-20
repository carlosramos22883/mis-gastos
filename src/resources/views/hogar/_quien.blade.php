{{-- Selector de miembro del hogar (quién hizo el movimiento o titular). Solo aparece si el hogar tiene más de un miembro.
     Uso: @include('hogar._quien', ['valor' => $registro->user_id ?? null, 'label' => 'Titular']) --}}
@php($miembros = auth()->user()->miembrosHogar)
@if ($miembros->count() > 1)
    <x-floating-select id="user_id" :label="$label ?? '¿Quién lo hizo?'" :allowEmpty="false"
        :options="$miembros->pluck('name', 'id')->all()" :value="$valor ?? auth()->id()" required />
@endif
