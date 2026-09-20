@props(['id', 'label', 'value' => '', 'symbol' => '$', 'error' => null])

<div class="relative w-full">
    <div class="flex items-center overflow-hidden rounded-lg border border-gray-300 bg-white dark:border-gray-600 dark:bg-gray-700 focus-within:ring-2 focus-within:ring-primary-500">
        <span class="pl-4 text-sm text-gray-500 dark:text-gray-300">{{ $symbol }}</span>
        <input id="{{ $id }}" @unless($attributes->has('x-bind:name')) name="{{ $id }}" @endunless type="text" inputmode="decimal" value="{{ old($id, $value) }}"
            data-money-input placeholder="0.00" class="w-full border-0 bg-transparent px-2 py-2.5 text-xs text-gray-900 focus:ring-0 dark:text-white"
            {{ $attributes }} x-init="formatMoneyInput($el)" onblur="formatMoneyInput(this)">
    </div>
    <label for="{{ $id }}" class="absolute -top-2.5 left-2.5 bg-white px-1 text-xs text-gray-500 dark:bg-gray-800 dark:text-gray-400">{{ $label }}</label>
    @if ($error)
        <p class="mt-1 text-xs flex items-center gap-1 text-red-600 dark:text-red-400 font-medium">
            <svg class="w-4 h-4 shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>
            {{ $error }}
        </p>
    @endif
</div>
