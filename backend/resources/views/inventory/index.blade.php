@extends('layouts.app')
@section('title', 'Persediaan')
@include('inventory.assets')
@section('content')
@php($qty = fn($value) => rtrim(rtrim(number_format((float)$value, 4, ',', '.'), '0'), ','))
<div class="products-page">
<div class="heading"><div><span class="eyebrow">PERSEDIAAN APOTEK</span><h1>Stok & kartu stok <x-help-tooltip label="Informasi Stok & kartu stok">Pantau ketersediaan produk, periksa batch, dan telusuri setiap mutasi.</x-help-tooltip></h1></div></div>
<div class="inventory-summary" aria-label="Ringkasan seluruh persediaan">
@foreach(['all'=>'Total produk','empty'=>'Stok layak jual kosong','low'=>'Stok menipis','near'=>'Hampir kedaluwarsa','expired'=>'Ada stok kedaluwarsa'] as $key=>$label)
<a href="{{ route('inventory.index', [$currentPharmacy, 'status'=>$key === 'all' ? null : $key]) }}" @if(request('status','all') === $key) aria-current="page" @endif><span>{{ $label }}</span><strong>{{ number_format($counts[$key],0,',','.') }}</strong></a>
@endforeach
</div>
<p class="inventory-note">Ringkasan menghitung jumlah produk di seluruh katalog. Stok layak jual mengecualikan batch dengan tanggal kedaluwarsa hari ini atau sebelumnya. Peringatan kedaluwarsa sampai {{ \Illuminate\Support\Carbon::parse($until)->format('d/m/Y') }} mengikuti pengaturan apotek.</p>
<section class="card">
<form class="toolbar product-filters" method="get" data-live-filter>
@include('inventory.sort-fields')
<label class="search-label">Cari produk<input type="search" name="q" value="{{ request('q') }}" placeholder="Nama, brand, kandungan, SKU, atau barcode" maxlength="150"></label>
<label>Kategori<select name="category"><option value="">Semua kategori</option>@foreach(\App\Models\Product::CATEGORIES as $category)<option @selected(request('category') === $category)>{{ $category }}</option>@endforeach</select></label>
<label>Kondisi stok<select name="status"><option value="">Semua kondisi</option>@foreach(['empty'=>'Stok layak jual kosong','low'=>'Stok menipis','near'=>'Hampir kedaluwarsa','expired'=>'Ada stok kedaluwarsa'] as $key=>$label)<option value="{{ $key }}" @selected(request('status') === $key)>{{ $label }}</option>@endforeach</select></label>
<button class="btn">Tampilkan</button><a class="btn" href="{{ route('inventory.index',$currentPharmacy) }}">Reset</a>
</form>
@if($products->isNotEmpty())
<div class="table-wrap"><table class="product-table" data-table-sort="server" data-sort-columns="name,physical,available,minimum,," data-sort-default="name"><thead><tr><th>PRODUK / SKU</th><th>STOK FISIK</th><th>LAYAK JUAL</th><th>MINIMUM</th><th>KONDISI</th><th>AKSI</th></tr></thead><tbody>
@foreach($products as $product)
<tr><td><strong>{{ $product->name }}</strong><small>{{ \App\Support\ProductComposition::label($currentPharmacy, $product->id) }}</small><small>{{ $product->brand }} Â· {{ $product->sku }}</small>@unless($product->active)<span class="badge neutral">Produk nonaktif</span>@endunless</td><td class="inventory-number">{{ $qty($product->physical) }}<small>{{ $product->base_unit }}</small></td><td class="inventory-number"><strong>{{ $qty($product->available) }}</strong><small>{{ $product->base_unit }}</small></td><td class="inventory-number">{{ $qty($product->minimum_stock) }}<small>{{ $product->base_unit }}</small></td><td><div class="inventory-status">
@if($product->available <= 0)<span class="badge neutral">Kosong</span>@elseif($product->available <= $product->minimum_stock)<span class="badge warning">Menipis</span>@else<span class="badge">Tersedia</span>@endif
@if($product->near_expiry > 0)<span class="badge warning">Hampir kedaluwarsa</span>@endif
@if($product->expired > 0)<span class="badge expired">Kedaluwarsa</span>@endif
</div></td><td><div class="actions"><a class="btn small" href="{{ route('inventory.show',[$currentPharmacy,$product->id]) }}">Detail batch</a><a class="btn small" href="{{ route('inventory.ledger',[$currentPharmacy,$product->id]) }}">Kartu stok</a></div></td></tr>
@endforeach
</tbody></table></div><div class="product-list-footer"><span>{{ $products->firstItem() }}â€“{{ $products->lastItem() }} dari {{ $products->total() }} produk</span>{{ $products->links('partials.pagination') }}</div>
@else<div class="product-empty"><h2>{{ $counts['all'] ? 'Produk tidak ditemukan' : 'Belum ada produk' }}</h2><p>{{ $counts['all'] ? 'Ubah kata pencarian atau reset filter untuk melihat persediaan.' : 'Tambahkan produk di katalog, lalu posting penerimaan untuk mencatat stok masuk.' }}</p></div>@endif
</section></div>
@endsection
