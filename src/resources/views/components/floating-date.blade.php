@props(['id', 'label', 'value' => '', 'error' => null, 'min' => null, 'max' => null, 'required' => false])

@php
    $dateValue = old($id, $value);
    $date = null;
    if ($dateValue) {
        try {
            $date = preg_match('/^\d{2}\/\d{2}\/\d{4}$/', (string) $dateValue)
                ? \Carbon\Carbon::createFromFormat('d/m/Y', $dateValue)
                : \Carbon\Carbon::parse($dateValue);
        } catch (\Throwable) {
            $date = null;
        }
    }
    $displayValue = $date?->format('d/m/Y') ?? '';
    $isoValue = $date?->format('Y-m-d') ?? '';
@endphp

<div class="relative w-full">
    @php($dynamicName = $attributes->get('x-bind:name'))
    <input id="{{ $id }}_display" name="{{ $id }}_display" type="text" inputmode="numeric"
        maxlength="10" @required($required) value="{{ $displayValue }}" placeholder="dd/mm/aaaa" data-date-input
        class="block w-full px-4 py-2.5 pr-11 text-xs text-gray-900 dark:text-white bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
        {{ $attributes->except(['x-bind:name', 'name']) }}
        oninput="this.value = this.value.replace(/\D/g, '').slice(0, 8).replace(/^(\d{2})(\d)/, '$1/$2').replace(/^(\d{2}\/\d{2})(\d)/, '$1/$2')"
        onblur="validateDateInput(this)">

    <input id="{{ $id }}" name="{{ $id }}"
        @if ($dynamicName) x-bind:name="{{ $dynamicName }}" @endif type="date"
        value="{{ $isoValue }}" min="{{ $min?->format('Y-m-d') }}" max="{{ ($max ?? now())->format('Y-m-d') }}"
        class="absolute right-3 top-2.5 h-6 w-6 cursor-pointer opacity-0" data-date-picker
        onchange="syncDateInput(this)" tabindex="-1">

    <svg class="pointer-events-none absolute right-3 top-2.5 h-6 w-6 text-gray-400 dark:text-gray-500" fill="none"
        stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
            d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
    </svg>

    <label for="{{ $id }}_display"
        class="absolute -top-2.5 left-2.5 bg-white dark:bg-gray-800 px-1 text-xs text-gray-500 dark:text-gray-400">
        {{ $label }}
    </label>

    @if ($error)
        <p class="mt-1 text-xs flex items-center gap-1 text-red-600 dark:text-red-400 font-medium">
            <svg class="w-4 h-4 shrink-0" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd"
                    d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z"
                    clip-rule="evenodd" />
            </svg>
            {{ $error }}
        </p>
    @endif
    <p data-date-error class="mt-1 hidden text-xs flex items-center gap-1 text-red-600 dark:text-red-400 font-medium">
    </p>
</div>
