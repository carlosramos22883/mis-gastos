@props(['align' => 'right', 'width' => '48', 'contentClasses' => 'py-1 bg-white dark:bg-gray-800'])

@php
    switch ($align) {
        case 'top':
            $alignmentClasses = 'ltr:origin-top-left rtl:origin-top-right start-0 top-full';
            break;
        case 'top-end':
            $alignmentClasses = 'ltr:origin-top-right rtl:origin-top-left end-0 top-full';
            break;
        case 'bottom':
            $alignmentClasses = 'ltr:origin-bottom-left rtl:origin-bottom-right start-0 bottom-full';
            break;
        case 'left':
            $alignmentClasses = 'ltr:origin-top-right rtl:origin-top-left end-0 top-0';
            break;
        case 'right':
        default:
            $alignmentClasses = 'ltr:origin-top-left rtl:origin-top-right start-0 top-0';
            break;
    }

    switch ($width) {
        case '48':
            $width = 'w-48';
            break;
    }
@endphp

<div class="relative" x-data="{ open: false }" @click.outside="open = false" @close.stop="open = false">
    <div @click="open = ! open">
        {{ $trigger }}
    </div>

    <div x-show="open" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 scale-95"
        x-transition:enter-end="opacity-100 scale-100" x-transition:leave="transition ease-in duration-75"
        x-transition:leave-start="opacity-100 scale-100" x-transition:leave-end="opacity-0 scale-95"
        class="absolute z-50 mt-2 {{ $width }} rounded-lg shadow-xl border border-gray-200 dark:border-gray-600 {{ $alignmentClasses }}"
        style="display: none;" @click="open = false">
        <div class="rounded-lg ring-1 ring-black ring-opacity-5 dark:ring-opacity-20 {{ $contentClasses }}">
            {{ $content }}
        </div>
    </div>
</div>
