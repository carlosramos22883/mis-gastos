@props(['id', 'label', 'value' => '', 'error' => null, 'min' => null, 'max' => null])

@php
    $dateValue = old($id, $value);
    $date = $dateValue ? (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', (string) $dateValue)
        ? \Carbon\Carbon::createFromFormat('d/m/Y', $dateValue)
        : \Carbon\Carbon::parse($dateValue)) : null;
    $displayValue = $date?->format('d/m/Y') ?? '';
    $isoValue = $date?->format('Y-m-d') ?? '';
@endphp

<div class="relative w-full">
    <input id="{{ $id }}_display" name="{{ $id }}_display" type="text" inputmode="numeric" maxlength="10"
        value="{{ $displayValue }}" placeholder="dd/mm/aaaa" data-date-input
        class="block w-full px-4 py-2.5 text-xs text-gray-900 dark:text-white bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
        {{ $attributes }} onblur="normalizeDateInput(this)">
    <input id="{{ $id }}" name="{{ $id }}" type="date" value="{{ $isoValue }}" min="{{ $min?->format('Y-m-d') }}" max="{{ ($max ?? now())->format('Y-m-d') }}"
        class="absolute right-3 top-2.5 h-6 w-6 cursor-pointer opacity-70" data-date-picker
        onchange="syncDateInput(this)">
    <label for="{{ $id }}_display"
        class="absolute -top-2.5 left-2.5 bg-white dark:bg-gray-800 px-1 text-xs text-gray-500 dark:text-gray-400">
        {{ $label }}
    </label>
    @if ($error)
        <p class="mt-1 text-xs flex items-center gap-1 text-red-600 dark:text-red-400 font-medium">
            {{ $error }}
        </p>
    @endif
</div>
