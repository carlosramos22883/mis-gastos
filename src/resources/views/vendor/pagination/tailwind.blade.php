@if ($paginator->hasPages())
    <nav role="navigation" aria-label="{{ __('Pagination Navigation') }}" class="flex items-center justify-between">
        <div class="flex justify-between flex-1 sm:hidden">
            @if ($paginator->onFirstPage())
                <x-secondary-button disabled class="opacity-50 cursor-not-allowed">
                    {!! __('pagination.previous') !!}
                </x-secondary-button>
            @else
                <a href="{{ $paginator->previousPageUrl() }}">
                    <x-secondary-button>
                        {!! __('pagination.previous') !!}
                    </x-secondary-button>
                </a>
            @endif

            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" class="ml-3">
                    <x-secondary-button>
                        {!! __('pagination.next') !!}
                    </x-secondary-button>
                </a>
            @else
                <x-secondary-button disabled class="ml-3 opacity-50 cursor-not-allowed">
                    {!! __('pagination.next') !!}
                </x-secondary-button>
            @endif
        </div>

        <div class="hidden sm:flex-1 sm:flex sm:items-center sm:justify-between">
            <div>
                <p class="text-sm text-gray-700 leading-5 dark:text-gray-400">
                    {!! __('Showing') !!}
                    @if ($paginator->firstItem())
                        <span class="font-medium">{{ $paginator->firstItem() }}</span>
                        {!! __('to') !!}
                        <span class="font-medium">{{ $paginator->lastItem() }}</span>
                    @else
                        {{ $paginator->count() }}
                    @endif
                    {!! __('of') !!}
                    <span class="font-medium">{{ $paginator->total() }}</span>
                    {!! __('results') !!}
                </p>
            </div>

            <div>
                <span class="relative z-0 inline-flex shadow-sm rounded-md gap-1">
                    {{-- Previous Page Link --}}
                    @if ($paginator->onFirstPage())
                        <x-secondary-button disabled class="opacity-50 cursor-not-allowed rounded-r-none">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                            </svg>
                        </x-secondary-button>
                    @else
                        <a href="{{ $paginator->previousPageUrl() }}" rel="prev">
                            <x-secondary-button class="rounded-r-none">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                                </svg>
                            </x-secondary-button>
                        </a>
                    @endif

                    {{-- Pagination Elements --}}
                    @foreach ($elements as $element)
                        {{-- "Three Dots" Separator --}}
                        @if (is_string($element))
                            <x-secondary-button disabled>
                                {{ $element }}
                            </x-secondary-button>
                        @endif

                        {{-- Array Of Links --}}
                        @if (is_array($element))
                            @foreach ($element as $page => $url)
                                @if ($page == $paginator->currentPage())
                                    <x-primary-button disabled class="cursor-default">
                                        {{ $page }}
                                    </x-primary-button>
                                @else
                                    <a href="{{ $url }}">
                                        <x-secondary-button>
                                            {{ $page }}
                                        </x-secondary-button>
                                    </a>
                                @endif
                            @endforeach
                        @endif
                    @endforeach

                    {{-- Next Page Link --}}
                    @if ($paginator->hasMorePages())
                        <a href="{{ $paginator->nextPageUrl() }}" rel="next">
                            <x-secondary-button class="rounded-l-none">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                                </svg>
                            </x-secondary-button>
                        </a>
                    @else
                        <x-secondary-button disabled class="rounded-l-none opacity-50 cursor-not-allowed">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                            </svg>
                        </x-secondary-button>
                    @endif
                </span>
            </div>
        </div>
    </nav>
@endif