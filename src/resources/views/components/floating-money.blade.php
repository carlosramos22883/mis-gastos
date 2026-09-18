@props(['id', 'label', 'value' => '', 'symbol' => '$', 'error' => null])

<div class="relative w-full">
    <div class="flex items-center overflow-hidden rounded-lg border border-gray-300 bg-white dark:border-gray-600 dark:bg-gray-700 focus-within:ring-2 focus-within:ring-primary-500">
        <span class="pl-4 text-sm text-gray-500 dark:text-gray-300">{{ $symbol }}</span>
        <input id="{{ $id }}" name="{{ $id }}" type="text" inputmode="decimal" value="{{ old($id, $value) }}"
            data-money-input placeholder="0.00" class="w-full border-0 bg-transparent px-2 py-2.5 text-xs text-gray-900 focus:ring-0 dark:text-white"
            {{ $attributes }} x-init="formatMoneyInput($el)" onblur="formatMoneyInput(this)">
    </div>
    <label for="{{ $id }}" class="absolute -top-2.5 left-2.5 bg-white px-1 text-xs text-gray-500 dark:bg-gray-800 dark:text-gray-400">{{ $label }}</label>
    @if ($error)
        <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $error }}</p>
    @endif
</div>
