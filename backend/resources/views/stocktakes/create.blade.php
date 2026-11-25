@extends('layouts.app')
@section('title','Mulai stok opname')
@include('sales.assets')
@include('procurement.assets')
@push('master-scripts')<script src="{{ asset('js/search-select.js') }}?v={{ filemtime(public_path('js/search-select.js')) }}" defer></script>@endpush
@section('content')
<div class="products-page"><div class="heading"><div><span class="eyebrow">PERSEDIAAN / SESI BARU</span><h1>Mulai stok opname <x-help-tooltip label="Informasi Mulai stok opname">Pilih produk yang akan dihitung. Snapshot dibuat saat sesi dimulai.</x-help-tooltip></h1></div><a class="btn" href="{{ route('stocktakes.index',$currentPharmacy) }}">Kembali</a></div>
<div class="pos-instructions"><strong>Siapkan penghitungan sebelum memulai.</strong> Pembayaran POS, posting penerimaan, dan perubahan satuan untuk produk sesi akan dihentikan sementara. Produk lain tetap dapat ditransaksikan. Pilih satu produk untuk opname bertahap, atau kategori untuk cakupan lebih luas. Produk nonaktif dan barang kedaluwarsa tetap termasuk penghitungan.</div>
<form class="card" style="max-width:780px" method="post" action="{{ route('stocktakes.store',$currentPharmacy) }}" data-confirm="Mulai opname dan hentikan sementara perubahan stok untuk produk yang dipilih? Hitung fisik sampai selesai, lalu posting koreksi atau batalkan sesi." data-confirm-title="Mulai sesi penghitungan" data-confirm-label="Ambil snapshot & mulai">@csrf<input type="hidden" name="request_key" value="{{ old('request_key',$requestKey) }}"><div class="pos-details">
<label>Kategori<select name="category"><option value="">Semua kategori</option>@foreach(\App\Models\Product::CATEGORIES as $category)<option @selected(old('category') === $category)>{{ $category }}</option>@endforeach</select></label>
<label>Produk tertentu (opsional)<select name="product_id" data-searchable><option value="">Semua produk pada kategori terpilih</option>@foreach($products as $product)<option value="{{ $product->id }}" @selected(old('product_id') === $product->id)>{{ $product->name }} · {{ \App\Support\ProductComposition::label($currentPharmacy, $product->id) }} · {{ $product->brand }} · {{ $product->sku }}</option>@endforeach</select><small>Jika memilih kategori dan produk, produk harus termasuk kategori tersebut.</small></label>
<label>Catatan penghitungan (opsional)<textarea name="notes" maxlength="2000" rows="3">{{ old('notes') }}</textarea></label><button class="btn primary" @disabled($products->isEmpty())>Ambil snapshot & mulai</button></div></form></div>
@endsection
