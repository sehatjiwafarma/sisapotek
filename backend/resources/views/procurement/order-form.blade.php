@extends('layouts.app')
@section('title', $order->exists ? 'Edit draft pembelian' : 'Buat pembelian')
@include('procurement.assets')
@push('master-scripts')<script src="{{ asset('js/procurement.js') }}?v={{ filemtime(public_path('js/procurement.js')) }}" defer></script><script src="{{ asset('js/search-select.js') }}?v={{ filemtime(public_path('js/search-select.js')) }}" defer></script>@endpush
@section('content')
<div class="proc-page"><div class="heading"><div><span class="eyebrow">PEMBELIAN / DRAFT PO</span><h1>{{ $order->exists ? 'Edit draft pembelian' : 'Buat pembelian' }}</h1><p>Simpan draft, periksa rinciannya, lalu konfirmasi pesanan.</p></div><a class="btn" href="{{ route('purchases.index', $currentPharmacy) }}">Kembali</a></div>
@if($suppliers->isEmpty() || $products->isEmpty())<div class="notice warning">Siapkan supplier aktif dan produk aktif terlebih dahulu untuk membuat PO.</div>@endif
<form method="post" action="{{ $order->exists ? route('purchases.update',[$currentPharmacy,$order]) : route('purchases.store',$currentPharmacy) }}" data-order-form>@csrf @if($order->exists) @method('PUT') @endif
<section class="card"><header class="proc-section-header"><div class="help-heading"><h2>Informasi pesanan</h2><x-help-tooltip label="Informasi Informasi pesanan">Nomor PO dibuat otomatis. Harga berikut merupakan estimasi pesanan.</x-help-tooltip></div></header><div class="proc-fields proc-fields-three">
<label>Supplier *<select name="supplier_id" required data-supplier-select data-searchable><option value="">Pilih supplier aktif</option>@foreach($suppliers as $supplier)<option value="{{ $supplier->id }}" data-days="{{ $supplier->payment_days }}" @selected(old('supplier_id',$order->supplier_id) === $supplier->id)>{{ $supplier->name }} · {{ $supplier->code }}</option>@endforeach</select></label>
@include('procurement.field',['label'=>'Tanggal PO','name'=>'ordered_on','type'=>'date','required'=>true,'value'=>$order->ordered_on?->format('Y-m-d')])
@include('procurement.field',['label'=>'Estimasi tiba','name'=>'expected_on','type'=>'date','value'=>$order->expected_on?->format('Y-m-d')])
<label>Pembayaran *<select name="payment_type" data-payment-type><option value="cash" @selected(old('payment_type',$order->payment_type) === 'cash')>Tunai</option><option value="credit" @selected(old('payment_type',$order->payment_type) === 'credit')>Kredit / tempo</option></select></label>
@include('procurement.field',['label'=>'Termin (hari)','name'=>'payment_days','type'=>'number','required'=>true,'value'=>$order->payment_days,'hint'=>'Tunai menggunakan 0 hari; kredit minimal 1 hari.'])
</div></section>
<section class="card proc-order-items"><header class="proc-section-header"><div><h2>Item pesanan <x-help-tooltip label="Informasi Item pesanan">Pilih produk beserta brand. Diskon dan pajak diisi sebagai total rupiah per baris.</x-help-tooltip></h2></div><button type="button" class="btn small" data-add-row>+ Tambah item</button></header>
<div class="table-wrap"><table><thead><tr><th data-no-sort>PRODUK & BRAND</th><th data-no-sort>SATUAN</th><th data-no-sort>QTY</th><th data-no-sort>HARGA (RP)</th><th data-no-sort>PENYESUAIAN</th><th data-no-sort>TOTAL ITEM</th><th>AKSI</th></tr></thead><tbody data-order-rows>
@foreach(old('items', $order->exists ? $order->items->toArray() : [[]]) as $index=>$row)
    @include('procurement.order-row')
@endforeach
</tbody></table></div><div class="proc-order-total"><span>Total estimasi pesanan</span><strong data-order-total>Rp 0</strong></div></section>
<template data-order-template>@include('procurement.order-row',['index'=>'__INDEX__','row'=>[]])</template>
<section class="card"><div class="proc-fields">@include('procurement.field',['label'=>'Catatan pesanan','name'=>'notes','value'=>$order->notes,'type'=>'textarea'])</div></section>
<div class="proc-save-bar"><span>Draft belum menambah stok atau dikirim otomatis ke supplier.</span><div class="actions"><a class="btn" href="{{ route('purchases.index',$currentPharmacy) }}">Batal</a><button class="btn primary" @disabled($suppliers->isEmpty() || $products->isEmpty())>Simpan draft PO</button></div></div></form></div>
@endsection
