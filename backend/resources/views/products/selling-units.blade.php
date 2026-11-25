<div class="product-unit-group product-selling-content">
<h3>Penjualan <x-help-tooltip label="Informasi Penjualan">Isi harga satuan dasar, lalu tambahkan kemasan yang dijual dengan harga khusus.</x-help-tooltip></h3>
<div class="product-base-price">
@include('products.input', ['label'=>'Harga jual satuan dasar (Rp)','name'=>'selling_price','type'=>'number','required'=>true,'min'=>0,'step'=>'0.01','hint'=>'Contoh: harga per tablet. Harga ini hanya diisi sekali.'])
</div>
<div class="product-selling-heading"><div><h4>Harga kemasan tambahan</h4><p>Contoh: 1 strip berisi 10 tablet, harga jual Rp9.000.</p></div><button type="button" class="btn" data-selling-unit-add>Tambah satuan jual</button></div>
<input type="hidden" name="selling_units_present" value="1">
<div data-selling-units>
@foreach(old('selling_units', $product->selling_units ?? []) as $index => $unit)
@include('products.selling-unit-row')
@endforeach
</div>
<template data-selling-unit-template>@include('products.selling-unit-row', ['index'=>'__INDEX__', 'unit'=>[]])</template>
<p class="product-selling-note">Tanpa harga khusus, harga jual kemasan pembelian = harga satuan dasar × isi. Konversi selalu terhadap satuan dasar. Satuan jual yang sudah digunakan tidak dapat dihapus atau diubah konversinya.</p>
</div>
@push('master-scripts')<script src="{{ asset('js/product-selling-units.js') }}?v={{ filemtime(public_path('js/product-selling-units.js')) }}" defer></script>@endpush
