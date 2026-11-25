@if($paginator->hasPages())
<nav class="app-pagination" aria-label="Navigasi halaman">
    <span class="page-position">Halaman <strong>{{ $paginator->currentPage() }}</strong> dari {{ $paginator->lastPage() }}</span>
    <div class="page-controls">
        @if($paginator->onFirstPage())<span class="page-control" aria-disabled="true">← Sebelumnya</span>@else<a class="page-control" href="{{ $paginator->previousPageUrl() }}" rel="prev">← Sebelumnya</a>@endif
        @if($paginator->hasMorePages())<a class="page-control" href="{{ $paginator->nextPageUrl() }}" rel="next">Berikutnya →</a>@else<span class="page-control" aria-disabled="true">Berikutnya →</span>@endif
    </div>
</nav>
@endif
