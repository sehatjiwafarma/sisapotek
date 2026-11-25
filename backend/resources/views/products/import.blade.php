@extends('layouts.app')
@section('title','Impor obat dari Excel')
@include('products.assets')
@section('content')
<div class="products-page">
<div class="heading"><div><span class="eyebrow">MASTER DATA / OBAT & PRODUK</span><h1>Impor obat dari Excel <x-help-tooltip label="Informasi Impor obat dari Excel">Tambahkan produk baru sekaligus menggunakan template XLSX.</x-help-tooltip></h1></div><a class="btn" href="{{ route('products.index',$currentPharmacy) }}">Kembali ke katalog</a></div>
<section class="card padded"><h2>1. Unduh dan isi template</h2><p>Isi sheet <strong>Obat</strong>. Sheet <strong>Kandungan</strong> dan <strong>Satuan jual</strong> opsional, dihubungkan melalui SKU. Panduan serta pilihan kategori dan satuan tersedia di sheet Panduan.</p><p>Template berisi 2 contoh obat: Paracetamol dan Vitamin C, beserta kandungan dan harga per strip. Ganti atau hapus data contoh pada ketiga sheet sebelum mengunggah; contoh yang dibiarkan akan ikut diimpor. Harga contoh hanya simulasi.</p><a class="btn" href="{{ route('products.import.template',$currentPharmacy) }}">Unduh template Excel</a></section>
<section class="card padded"><h2>2. Unggah file</h2><p>Maksimal 500 produk per file, ukuran maksimal 5 MB. SKU dan barcode wajib unik. Produk yang sudah ada tidak ditimpa. Impor tidak menambahkan saldo stok.</p>
<form method="post" enctype="multipart/form-data" action="{{ route('products.import.store',$currentPharmacy) }}" data-confirm="Impor seluruh produk baru dari file ini? Data hanya disimpan jika semua baris valid." data-confirm-title="Impor obat" data-confirm-label="Impor produk">
@csrf<label>File Excel (.xlsx) *<input type="file" name="file" required accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"></label>
<p class="muted">Jika ditemukan kesalahan, seluruh impor dibatalkan. Perbaiki baris yang dilaporkan lalu unggah kembali. Maksimal 50 pesan kesalahan ditampilkan.</p>
<button class="btn primary">Unggah & impor</button>
</form></section></div>
@endsection
