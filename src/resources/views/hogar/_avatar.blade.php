{{-- Iniciales (o foto) de un miembro del hogar. Uso: @include('hogar._avatar', ['persona' => $user, 'size' => 'sm']) --}}
@php($chico = ($size ?? 'md') === 'sm')
@if ($persona?->avatar)
    <img src="{{ filter_var($persona->avatar, FILTER_VALIDATE_URL) ? $persona->avatar : asset('storage/'.$persona->avatar) }}" alt="{{ $persona->name }}" title="{{ $persona->name }}" class="{{ $chico ? 'h-6 w-6' : 'h-10 w-10' }} rounded-full object-cover">
@else
    <span title="{{ $persona?->name }}" class="{{ $chico ? 'h-6 w-6 text-[10px]' : 'h-10 w-10 text-sm' }} inline-flex shrink-0 items-center justify-center rounded-full bg-primary-100 font-semibold text-primary-700 dark:bg-primary-900/40 dark:text-primary-300">{{ mb_strtoupper(mb_substr($persona?->name ?? '?', 0, 1)) }}</span>
@endif
