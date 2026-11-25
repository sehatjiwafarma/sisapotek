@extends('layouts.app')
@section('title', $sale ? 'Edit draft penjualan' : 'Penjualan umum')
@include('sales.assets')
@push('master-scripts')<script src="{{ asset('js/sales.js') }}?v={{ filemtime(public_path('js/sales.js')) }}" defer></script>@endpush
@section('content')
<div class="products-page">
    <div class="heading"><div><span class="eyebrow">KASIR / PENJUALAN UMUM</span><h1>{{ $sale ? 'Edit '.$sale->number : 'Penjualan umum' }}</h1><p>Pilih produk, periksa keranjang, lalu simpan draft untuk pembayaran.</p></div><a class="btn" href="{{ route('sales.index',$currentPharmacy) }}">Riwayat transaksi</a></div>
    <div class="pos-steps" aria-label="Tahapan penjualan"><strong>1. Susun keranjang</strong><span>2. Periksa & bayar</span><span>3. Cetak struk</span></div>
    <div class="pos-instructions">Hanya produk aktif tanpa kewajiban resep. Harga mengikuti master produk; batch dipilih otomatis dengan FEFO saat pembayaran. Stok pada katalog bersifat informasi dan diperiksa ulang saat finalisasi.</div>
    <noscript><div class="notice warning">Aktifkan JavaScript untuk mencari produk dan menyusun keranjang POS.</div></noscript>
    <nav class="pos-mobile-nav" aria-label="Area penjualan"><a class="btn" href="#pos-catalog-panel" data-pos-view="catalog">Pilih produk</a><a class="btn" href="#pos-cart-panel" data-pos-view="cart">Keranjang (<span data-cart-count>0</span>) · <span data-pos-mobile-total>Rp 0</span></a></nav><div class="pos-layout">
        <section class="card pos-panel-active" id="pos-catalog-panel" data-pos-panel="catalog" aria-labelledby="catalog-title">
            <header class="pos-section"><div class="help-heading"><h2 id="catalog-title">Pilih produk</h2><x-help-tooltip label="Informasi Pilih produk">Cari nama, brand, kandungan, SKU, atau barcode. Alt+K untuk fokus pencarian.</x-help-tooltip></div><label class="pos-search">Pencarian produk<input type="search" data-pos-search maxlength="150" placeholder="Ketik atau pindai barcode…" autocomplete="off"></label></header>
            <div class="pos-catalog" data-pos-catalog aria-live="polite"><p class="pos-help">Memuat katalog…</p></div>
            <div class="pos-pager"><button type="button" class="btn small" data-catalog-prev disabled>Sebelumnya</button><span data-catalog-page></span><button type="button" class="btn small" data-catalog-next disabled>Berikutnya</button></div>
        </section>
        <form class="card pos-cart-card" id="pos-cart-panel" data-pos-panel="cart" method="post" action="{{ $sale ? route('sales.update',[$currentPharmacy,$sale->id]) : route('sales.store',$currentPharmacy) }}" data-pos-form data-catalog-url="{{ route('sales.catalog',$currentPharmacy) }}">@csrf
            @if($sale) @method('PUT')<input type="hidden" name="revision" value="{{ $sale->revision }}">@else<input type="hidden" name="request_key" value="{{ $requestKey }}">@endif
            <header class="pos-section"><div class="help-heading"><h2>Keranjang penjualan</h2><x-help-tooltip label="Informasi Keranjang penjualan">Jumlah dapat berupa pecahan sampai 3 desimal. Harga kemasan = harga satuan dasar × isi kemasan.</x-help-tooltip></div></header>
            <div class="notice error" data-pos-error role="alert" hidden></div>
            <div class="pos-cart" data-pos-rows></div><div class="pos-empty" data-cart-empty>Keranjang masih kosong.<br>Pilih produk dari katalog untuk memulai.</div>
            <details class="pos-options"><summary>Detail pelanggan, diskon, PPN & catatan</summary><div class="pos-details">
                <label>Pelanggan <span class="muted">(opsional)</span><input name="customer_name" maxlength="150" value="{{ old('customer_name',$sale?->customer_name) }}" placeholder="Umum / tanpa identitas"></label>
                @php($selectedDiscount = old('discount_rule_id', $sale ? $sale->discount_rule_id : $discountRules->firstWhere('is_default',true)?->id))
                @php($selectedTax = old('tax_rule_id', $sale ? $sale->tax_rule_id : $taxRules->firstWhere('is_default',true)?->id))
                @if($access->allows('sales.discount'))
                <label>Diskon transaksi<select name="discount_rule_id" data-pos-discount-rule><option value="">Tanpa master / nominal manual</option>@foreach($discountRules as $rule)<option value="{{ $rule->id }}" data-type="{{ $rule->type }}" data-value="{{ $rule->value }}" @selected($selectedDiscount === $rule->id)>{{ $rule->name }} — {{ $rule->type === 'nominal' ? 'Rp ' : '' }}{{ number_format($rule->value,2,',','.') }}{{ $rule->type === 'percentage' ? '%' : '' }}</option>@endforeach @if($selectedDiscount && !$discountRules->contains('id',$selectedDiscount))<option value="{{ $selectedDiscount }}" selected data-type="{{ $sale?->discount_type }}" data-value="{{ $sale?->discount_value ?? 0 }}">Master sebelumnya tidak tersedia — pilih ulang</option>@endif</select></label>
                <label>Diskon nominal manual (Rp)<input type="number" name="discount" min="0" max="10000000000000" step="0.01" value="{{ old('discount',$sale?->discount ?? 0) }}" data-pos-discount><small>Digunakan hanya saat tidak memilih master diskon.</small></label>
                @else<input type="hidden" name="discount" value="{{ $sale?->discount ?? 0 }}" data-pos-discount><input type="hidden" name="discount_rule_id" value="{{ $sale?->discount_rule_id }}" data-pos-discount-rule data-type="{{ $sale?->discount_type }}" data-value="{{ $sale?->discount_value ?? 0 }}">@endif
                <label>PPN<select name="tax_rule_id" data-pos-tax-rule><option value="">Tanpa PPN</option>@foreach($taxRules as $rule)<option value="{{ $rule->id }}" data-rate="{{ $rule->value }}" data-mode="{{ $rule->tax_mode }}" @selected($selectedTax === $rule->id)>{{ $rule->name }} — {{ number_format($rule->value,2,',','.') }}% ({{ $rule->tax_mode === 'inclusive' ? 'termasuk harga' : 'ditambahkan' }})</option>@endforeach @if($selectedTax && !$taxRules->contains('id',$selectedTax))<option value="{{ $selectedTax }}" selected data-rate="{{ $sale?->tax_rate ?? 0 }}" data-mode="{{ $sale?->tax_mode }}">Master sebelumnya tidak tersedia — pilih ulang</option>@endif</select><small>PPN dihitung setelah diskon. Mode termasuk harga menguraikan PPN tanpa menambah total.</small></label>
                <label>Catatan <span class="muted">(opsional)</span><textarea name="notes" maxlength="2000" rows="2">{{ old('notes',$sale?->notes) }}</textarea></label>
            </div></details><div class="pos-checkout"><div class="pos-totals" aria-live="polite"><div><span>Subtotal</span><strong data-pos-subtotal>Rp 0</strong></div><div><span>Diskon</span><strong data-pos-discount-total>Rp 0</strong></div><div><span>Nilai sebelum PPN</span><strong data-pos-tax-base>Rp 0</strong></div><div><span data-pos-tax-label>PPN</span><strong data-pos-tax-total>Rp 0</strong></div><div class="pos-grand-total"><span>Total estimasi</span><strong data-pos-total>Rp 0</strong></div></div>
                <button class="btn primary pos-save" data-pos-save>Lanjut periksa & bayar</button><p class="pos-help">Draft belum mengurangi stok atau mencatat pembayaran. Alt+S untuk menyimpan.</p>
            </div>
        </form>
    </div>
</div>
<script type="application/json" id="pos-cart-data">@json($cart)</script>
<template id="pos-row-template"><div class="pos-row"><div><strong class="pos-row-title" data-row-name></strong><small data-row-sku></small><small data-row-composition></small><small data-row-stock></small><input type="hidden" data-field="product_id"><div class="pos-row-fields"><label>Jumlah<input type="number" data-field="quantity" min="0.001" max="10000" step="0.001" required></label><label>Satuan<select data-field="unit_type"></select></label></div><small data-row-price></small></div><div class="pos-row-end"><button type="button" class="btn small danger" data-remove-row>Hapus</button><strong data-row-total></strong></div></div></template>
@endsection
