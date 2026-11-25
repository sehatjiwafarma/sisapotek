@extends('layouts.app')
@section('title', $supplier->exists ? 'Edit supplier' : 'Tambah supplier')
@include('procurement.assets')
@section('content')
<div class="proc-page proc-form-width"><div class="heading"><div><span class="eyebrow">PEMBELIAN / SUPPLIER</span><h1>{{ $supplier->exists ? 'Edit supplier' : 'Tambah supplier' }}</h1><p>Informasi pemasok untuk pesanan dan penerimaan barang.</p></div><a class="btn" href="{{ route('suppliers.index', $currentPharmacy) }}">Kembali</a></div>
<form method="post" action="{{ $supplier->exists ? route('suppliers.update', [$currentPharmacy, $supplier]) : route('suppliers.store', $currentPharmacy) }}">@csrf @if($supplier->exists) @method('PUT') @endif
<section class="card"><header class="proc-section-header"><div class="help-heading"><h2>Identitas & kontak</h2><x-help-tooltip label="Informasi Identitas & kontak">Kode supplier dibuat otomatis. Supplier baru berstatus aktif.</x-help-tooltip></div></header><div class="proc-fields">
@include('procurement.field', ['label'=>'Nama supplier','name'=>'name','value'=>$supplier->name,'required'=>true,'max'=>150])
@include('procurement.field', ['label'=>'Nama kontak / PIC','name'=>'contact_name','value'=>$supplier->contact_name,'max'=>100])
@include('procurement.field', ['label'=>'Telepon','name'=>'phone','value'=>$supplier->phone,'type'=>'tel','max'=>30])
@include('procurement.field', ['label'=>'Email','name'=>'email','value'=>$supplier->email,'type'=>'email','max'=>254])
@include('procurement.field', ['label'=>'NPWP','name'=>'tax_number','value'=>$supplier->tax_number,'max'=>30])
@include('procurement.field', ['label'=>'Termin pembayaran (hari)','name'=>'payment_days','value'=>$supplier->payment_days,'type'=>'number','required'=>true,'hint'=>'Isi 0 untuk tunai. Dapat disesuaikan pada setiap PO.'])
<div class="proc-full">@include('procurement.field', ['label'=>'Alamat','name'=>'address','value'=>$supplier->address,'type'=>'textarea','max'=>1000])</div>
</div></section><div class="proc-save-bar"><span>Field bertanda * wajib diisi.</span><div class="actions"><a class="btn" href="{{ route('suppliers.index', $currentPharmacy) }}">Batal</a><button class="btn primary">Simpan supplier</button></div></div></form></div>
@endsection
