@if ($paginator->hasPages())
    <nav role="navigation" aria-label="Pagination Navigation" class="flex justify-between gap-3">
        {{-- Previous Page Link --}}
        @if ($paginator->onFirstPage())
            <x-secondary-button disabled class="opacity-50 cursor-not-allowed">
                {!! __('pagination.previous') !!}
            </x-secondary-button>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" rel="prev">
                <x-secondary-button>
                    {!! __('pagination.previous') !!}
                </x-secondary-button>
            </a>
        @endif

        {{-- Next Page Link --}}
        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" rel="next">
                <x-secondary-button>
                    {!! __('pagination.next') !!}
                </x-secondary-button>
            </a>
        @else
            <x-secondary-button disabled class="opacity-50 cursor-not-allowed">
                {!! __('pagination.next') !!}
            </x-secondary-button>
        @endif
    </nav>
@endif