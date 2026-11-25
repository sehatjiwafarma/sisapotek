@extends('layouts.app')
@section('title', $product->exists ? 'Edit produk' : 'Tambah produk')
@include('products.assets')
@section('content')
<div class="products-page">
    <div class="heading"><div><span class="eyebrow">KATALOG / OBAT & PRODUK</span><h1>{{ $product->exists ? 'Edit produk' : 'Tambah produk' }}</h1><p>Satu produk untuk setiap brand, kekuatan, dan varian kemasan.</p></div><a class="btn" href="{{ route('products.index', $currentPharmacy) }}">Kembali ke daftar</a></div>
    <form class="product-editor" method="post" action="{{ $product->exists ? route('products.update', [$currentPharmacy, $product]) : route('products.store', $currentPharmacy) }}">
        @csrf @if($product->exists) @method('PUT') @endif
        <div class="product-form-main">
            <section class="card product-form-section"><header><span class="product-section-number">01</span><div><h2>Identitas produk <x-help-tooltip label="Informasi Identitas produk">Identifikasi produk menggunakan brand / merek dan SKU.</x-help-tooltip></h2></div></header><div class="product-form-fields">
                @include('products.input', ['label'=>'Kode produk / SKU','name'=>'sku','required'=>true,'max'=>50,'hint'=>'Unik di apotek ini. Contoh: PCM-BRAND-A-500.'])
                @include('products.input', ['label'=>'Barcode','name'=>'barcode','max'=>100,'hint'=>'Opsional. Gunakan barcode pada kemasan produk.'])
                @include('products.input', ['label'=>'Brand / merek','name'=>'brand','required'=>true,'max'=>150,'placeholder'=>'Contoh: Panadol','hint'=>'Untuk produk tanpa merek, isi “Generik” atau “Tanpa merek”.'])
                @include('products.select', ['label'=>'Kategori','name'=>'category','options'=>$categories,'required'=>true])
                @include('products.select', ['label'=>'Bentuk sediaan','name'=>'dosage_form','options'=>\App\Models\Product::FORMS])
            </div></section>
            @include('products.ingredients')
            <section class="card product-form-section"><header><span class="product-section-number">03</span><div><h2>Satuan & harga <x-help-tooltip label="Informasi Satuan & harga">Tentukan satuan stok, kemasan pembelian, dan harga jual untuk kasir.</x-help-tooltip></h2></div></header><div class="product-form-fields">
                @include('products.select', ['label'=>'Satuan dasar','name'=>'base_unit','options'=>\App\Models\Product::UNITS,'required'=>true,'hint'=>'Satuan stok terkecil yang digunakan.'])
            </div><div class="product-unit-group"><h3>Pembelian <x-help-tooltip label="Informasi Pembelian">Kemasan dari supplier dan harga beli acuan.</x-help-tooltip></h3><div class="product-form-fields">
                @include('products.select', ['label'=>'Satuan pembelian','name'=>'purchase_unit','options'=>\App\Models\Product::UNITS,'required'=>true])
                @include('products.input', ['label'=>'Isi per satuan pembelian','name'=>'purchase_conversion','type'=>'number','required'=>true,'min'=>'0.0001','step'=>'0.0001','hint'=>'Contoh: 1 box berisi 100 tablet → isi 100. Satuan sama → isi 1.'])
                @if(!empty($purchasePriceSource))<p class="product-full">Acuan penerimaan tertinggi: {{ $purchasePriceSource->number }} ? {{ $purchasePriceSource->supplier_name }} ? {{ \Illuminate\Support\Carbon::parse($purchasePriceSource->received_on)->format('d/m/Y') }}. Harga dapat diubah manual dan dihitung ulang ketika penerimaan berikutnya diposting.</p>@elseif(($canSeePurchaseSource ?? false) && $product->exists && $product->purchase_price !== null)<p class="product-full">Harga saat ini belum cocok dengan penerimaan tertinggi yang tercatat; periksa harga manual.</p>@endif
                @include('products.input', ['label'=>'Harga beli acuan tertinggi (Rp / satuan pembelian)','name'=>'purchase_price','type'=>'number','min'=>0,'step'=>'0.01','hint'=>'Opsional. Setelah penerimaan diposting, harga mengikuti harga aktual tertinggi dari seluruh supplier dalam satuan pembelian; bukan harga terakhir atau HPP lot.'])
            </div></div>
            @include('products.selling-units')
            </section>
            <section class="card product-form-section"><header><span class="product-section-number">04</span><div><h2>Pengendalian & catatan <x-help-tooltip label="Informasi Pengendalian & catatan">Atur batas stok dalam satuan dasar dan kebutuhan resep.</x-help-tooltip></h2></div></header><div class="product-form-fields">
                @include('products.input', ['label'=>'Stok minimum','name'=>'minimum_stock','type'=>'number','required'=>true,'min'=>0,'step'=>'0.0001'])
                @include('products.input', ['label'=>'Stok maksimum','name'=>'maximum_stock','type'=>'number','min'=>0,'step'=>'0.0001','hint'=>'Opsional. Harus sama atau lebih besar dari stok minimum.'])
                <label class="product-full">Pelaporan psikotropika<select name="is_psychotropic"><option value="0" @selected(!old('is_psychotropic', $product->is_psychotropic))>Tidak termasuk rekap psikotropika</option><option value="1" @selected(old('is_psychotropic', $product->is_psychotropic))>Masukkan ke rekap psikotropika</option></select><small>Penandaan dilakukan berdasarkan master obat yang telah ditinjau.</small></label>
<label class="product-full">Memerlukan resep<select name="requires_prescription"><option value="0" @selected(!old('requires_prescription', $product->requires_prescription))>Tidak</option><option value="1" @selected(old('requires_prescription', $product->requires_prescription))>Ya, memerlukan resep</option></select></label>
                <label class="product-full">Catatan internal <span class="product-optional">Opsional</span><textarea name="notes" maxlength="2000" rows="3" placeholder="Catatan untuk tim apotek">{{ old('notes', $product->notes) }}</textarea></label>
            </div></section>
        </div>
        <aside class="product-form-aside"><section class="card"><h2>Pencatatan per brand <x-help-tooltip label="Informasi Pencatatan per brand">Paracetamol dengan Brand A dan Brand B dicatat sebagai dua produk, masing-masing memiliki SKU, harga, dan kemasan sendiri.</x-help-tooltip></h2><div class="product-example"><strong>Paracetamol · Brand A</strong><span>500 mg · Tablet</span></div><div class="product-example"><strong>Paracetamol · Brand B</strong><span>500 mg · Tablet</span></div><p class="product-stock-note">Stok aktual akan berasal dari pergerakan barang. Formulir ini hanya mengatur batas stok.</p></section></aside>
        <div class="product-save-bar"><span>Field bertanda * wajib diisi.</span><div class="actions"><a class="btn" href="{{ route('products.index', $currentPharmacy) }}">Batal</a><button class="btn primary">{{ $product->exists ? 'Simpan perubahan' : 'Simpan produk' }}</button></div></div>
    </form>
</div>
@endsection
