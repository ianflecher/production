{{-- The app ships plain CSS, not Tailwind, so the framework's default pager
     would come out unstyled. This one is styled by .app-pager in app.css. --}}
@if ($paginator->hasPages())
    <nav class="app-pager" role="navigation" aria-label="Pagination">

        @if ($paginator->onFirstPage())
            <span class="app-pager-link is-disabled" aria-disabled="true">&larr; Previous</span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" class="app-pager-link" rel="prev">&larr; Previous</a>
        @endif

        <span class="app-pager-status">
            Page {{ number_format($paginator->currentPage()) }}
            of {{ number_format($paginator->lastPage()) }}
        </span>

        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" class="app-pager-link" rel="next">Next &rarr;</a>
        @else
            <span class="app-pager-link is-disabled" aria-disabled="true">Next &rarr;</span>
        @endif

    </nav>
@endif
