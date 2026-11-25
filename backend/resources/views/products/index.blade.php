@extends('layouts.app')
@section('title', 'Obat & produk')
@include('products.assets')
@section('content')
<div class="products-page">
    <div class="heading"><div><span class="eyebrow">KATALOG APOTEK</span><h1>Obat & produk</h1><p>Kelola identitas, brand, satuan, dan harga produk di {{ $currentPharmacy->name }}.</p></div>@if($access->allows('drugs.create'))<div class="actions"><a class="btn" href="{{ route('products.import', $currentPharmacy) }}">Impor Excel</a><a class="btn primary" href="{{ route('products.create', $currentPharmacy) }}">+ Tambah produk</a></div>@endif</div>
    <div class="product-summary" aria-label="Ringkasan katalog">
        <div><span>Total produk</span><strong>{{ number_format($counts['all'],0,',','.') }}</strong></div><div><span>Aktif</span><strong>{{ number_format($counts['active'],0,',','.') }}</strong></div><div><span>Nonaktif</span><strong>{{ number_format($counts['inactive'],0,',','.') }}</strong></div>
    </div>
    <section class="card">
        <form class="toolbar product-filters" method="get" data-live-filter>
            @foreach(['sort','direction'] as $sortParam) @if(is_string(request($sortParam)))<input type="hidden" name="{{ $sortParam }}" value="{{ request($sortParam) }}">@endif @endforeach
            <label class="search-label">Cari produk<input type="search" name="q" value="{{ request('q') }}" placeholder="Nama, brand, kandungan, SKU, atau barcode" maxlength="150"></label>
            <label>Kategori<select name="category"><option value="">Semua kategori</option>@foreach($categories as $category)<option @selected(request('category') === $category)>{{ $category }}</option>@endforeach</select></label>
            <label>Status<select name="status"><option value="">Semua status</option><option value="active" @selected(request('status') === 'active')>Aktif</option><option value="inactive" @selected(request('status') === 'inactive')>Nonaktif</option></select></label>
            <button class="btn">Tampilkan</button><a class="btn" href="{{ route('products.index', $currentPharmacy) }}">Reset</a>
        </form>
        @if($products->isNotEmpty())
        <div class="table-wrap"><table data-table-sort="server" data-sort-columns="name,brand,category,price,status," data-sort-default="name" class="product-table"><thead><tr><th>PRODUK / SKU</th><th>BRAND</th><th>KATEGORI</th><th>HARGA JUAL / SATUAN</th><th>STATUS</th><th>AKSI</th></tr></thead><tbody>
        @foreach($products as $product)
            <tr><td><strong>{{ $product->name }}</strong><small>{{ $product->sku }}@if($product->ingredients->isEmpty() && $product->strength) · {{ $product->strength }}@endif</small>@if($product->ingredients->isNotEmpty())@foreach($product->ingredients as $ingredient)<span class="product-generic">{{ $ingredient->name }}@if($ingredient->amount !== null) {{ (float)$ingredient->amount }} {{ $ingredient->unit }} / {{ (float)$ingredient->basis_quantity }} {{ $ingredient->basis_unit }}@endif</span>@endforeach @elseif($product->generic_name)<span class="product-generic">{{ $product->generic_name }}</span>@endif</td><td><span class="product-brand">{{ $product->brand }}</span></td><td>{{ $product->category }}@if($product->requires_prescription)<small class="product-prescription">Memerlukan resep</small>@endif</td><td><strong>Rp {{ number_format($product->selling_price, fmod((float) $product->selling_price, 1.0) == 0 ? 0 : 2, ',', '.') }}</strong><small>per {{ mb_strtolower($product->base_unit) }}</small></td><td><span class="badge {{ $product->active ? '' : 'neutral' }}">{{ $product->active ? 'Aktif' : 'Nonaktif' }}</span></td><td><div class="actions">
                @if($access->allows('drugs.update'))<a class="btn small" href="{{ route('products.edit', [$currentPharmacy, $product]) }}">Edit</a>@endif
                @if($access->allows('drugs.deactivate'))<form method="post" action="{{ route('products.status', [$currentPharmacy, $product]) }}" data-confirm="{{ $product->active ? 'Nonaktifkan' : 'Aktifkan' }} produk {{ $product->name }} ({{ $product->brand }})? Produk nonaktif tidak tersedia untuk transaksi baru." data-confirm-title="Ubah status produk" data-confirm-label="{{ $product->active ? 'Nonaktifkan' : 'Aktifkan' }}" data-confirm-variant="{{ $product->active ? 'danger' : 'primary' }}">@csrf @method('PATCH')<input type="hidden" name="active" value="{{ $product->active ? 0 : 1 }}"><button class="btn small {{ $product->active ? 'danger' : '' }}">{{ $product->active ? 'Nonaktifkan' : 'Aktifkan' }}</button></form>@endif
            </div></td></tr>
        @endforeach
        </tbody></table></div>
        <div class="product-list-footer"><span>{{ $products->firstItem() }}–{{ $products->lastItem() }} dari {{ $products->total() }} produk</span>{{ $products->links('partials.pagination') }}</div>
        @else
        <div class="product-empty"><span class="product-empty-symbol" aria-hidden="true">+</span><h2>{{ $counts['all'] ? 'Produk tidak ditemukan' : 'Mulai susun katalog apotek' }}</h2><p>{{ $counts['all'] ? 'Coba nama, brand, atau filter lain untuk menemukan produk.' : 'Catat setiap brand dan varian sebagai produk tersendiri dengan SKU yang unik.' }}</p>@if(!$counts['all'] && $access->allows('drugs.create'))<a class="btn primary" href="{{ route('products.create', $currentPharmacy) }}">Tambah produk pertama</a>@endif</div>
        @endif
    </section>
</div>
@endsection
