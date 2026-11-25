<section class="card combined-browser" data-combined-browser>
    <header class="combined-browser-header"><div><h2>Pilih resep</h2><p>Cari dan tambahkan resep pasien.</p></div><span class="combined-count">{{ $results->total() }} hasil</span></header>
    <div class="combined-browser-body">
        <form method="get" class="combined-search" data-combined-search>
            @foreach($selected as $id)<input type="hidden" name="selected[]" value="{{ $id }}">@endforeach
            <input type="hidden" name="sort" value="{{ request('sort','date') }}"><input type="hidden" name="direction" value="{{ request('direction','desc') }}">
            <label for="combined-search-input">Cari resep atau pasien</label><div class="combined-search-controls"><input id="combined-search-input" type="search" name="q" value="{{ $term }}" maxlength="150" autocomplete="off" placeholder="Nama, nomor resep, atau rekam medis"><button class="btn">Cari</button></div>
        </form>
        <div class="combined-browser-meta"><span>RESEP BELUM DITEBUS</span><a href="{{ request()->fullUrlWithQuery(['sort'=>'date', 'direction'=>request('sort','date') === 'date' && request('direction','desc') === 'desc' ? 'asc' : 'desc', 'page'=>null]) }}">Tanggal resep {{ request('sort','date') === 'date' && request('direction','desc') === 'asc' ? '↑' : '↓' }}</a></div>
        <div class="combined-result-list">
        @forelse($results as $result)@php($expired=$result->valid_until && $result->valid_until < now($currentPharmacy->timezone ?: 'Asia/Jakarta')->toDateString())
            <article class="combined-result"><div class="combined-result-info"><strong>{{ $result->patient_name }}</strong><span>{{ $result->number }}</span><small>{{ $result->valid_until ? 'Berlaku sampai '.\Illuminate\Support\Carbon::parse($result->valid_until)->format('d/m/Y') : 'Masa berlaku belum ditetapkan' }}</small></div>
                <div class="combined-result-action">@if($expired)<span class="badge warning">Kedaluwarsa</span><small>Tidak dapat ditambahkan</small>@elseif(in_array($result->id,$selected))<span class="badge">Dipilih</span>@elseif($result->prescription_count > 1 && !$access->allows('dispensing.multiple_prescriptions'))<span class="muted">Memerlukan izin beberapa resep</span>@elseif(count($selected)>=30)<span class="muted">Batas 30 resep</span>@else<a class="btn small" data-combined-add href="{{ route('combined-dispensing.index', [$currentPharmacy, 'q'=>$term, 'sort'=>request('sort','date'), 'direction'=>request('direction','desc'), 'page'=>$results->currentPage(), 'selected'=>array_merge($selected,[$result->id])]) }}">Tambah</a>@endif</div>
            </article>
        @empty<div class="combined-result-empty">Tidak ada resep yang sesuai.</div>@endforelse
        </div>
        {{ $results->links('partials.pagination') }}
        <p data-combined-browser-error class="notice error" role="alert" hidden></p>
    </div>
</section>
