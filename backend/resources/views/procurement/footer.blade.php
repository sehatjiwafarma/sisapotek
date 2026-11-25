<div class="proc-table-footer"><span>{{ $rows->firstItem() ?? 0 }}–{{ $rows->lastItem() ?? 0 }} dari {{ $rows->total() }} data</span>{{ $rows->links('partials.pagination') }}</div>
