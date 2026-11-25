@extends('layouts.app')
@section('title',$doctor ? 'Edit dokter' : 'Tambah dokter')
@include('clinical.assets')
@section('content')
<div class="products-page"><div class="heading"><div><span class="eyebrow">LAYANAN PASIEN / PROFIL DOKTER</span><h1>{{ $doctor ? 'Edit profil dokter' : 'Tambah dokter' }}</h1><p>{{ $doctor?->code ?? 'Kode dokter dibuat otomatis setelah data disimpan.' }}</p></div><a class="btn" href="{{ $doctor ? route('doctors.show',[$currentPharmacy,$doctor->id]) : route('doctors.index',$currentPharmacy) }}">Kembali</a></div>
<form class="card clinical-form" method="post" action="{{ $doctor ? route('doctors.update',[$currentPharmacy,$doctor->id]) : route('doctors.store',$currentPharmacy) }}">@csrf @if($doctor) @method('PUT')<input type="hidden" name="revision" value="{{ $doctor->revision }}">@else<input type="hidden" name="request_key" value="{{ old('request_key',$requestKey) }}">@endif
<header class="clinical-section"><div class="help-heading"><h2>Identitas dokter</h2><x-help-tooltip label="Informasi Identitas dokter">Isi identitas sesuai dokumen dokter. SIP dan STR dicatat sebagai data profil; pemeriksaan administratif dilakukan pengelola.</x-help-tooltip></div></header><div class="clinical-fields">
@include('clinical.input',['label'=>'Nama lengkap dokter','name'=>'name','value'=>$doctor?->name,'max'=>150,'required'=>true,'wide'=>true])
@include('clinical.input',['label'=>'Nomor SIP','name'=>'sip_number','value'=>$doctor?->sip_number,'max'=>80])
@include('clinical.input',['label'=>'Nomor STR','name'=>'str_number','value'=>$doctor?->str_number,'max'=>80])
@include('clinical.input',['label'=>'Spesialisasi','name'=>'specialty','value'=>$doctor?->specialty,'max'=>100])
@include('clinical.input',['label'=>'Telepon','name'=>'phone','value'=>$doctor?->phone,'max'=>30,'type'=>'tel'])
</div><div class="clinical-section"><p>Unggah tanda tangan melalui halaman detail dokter. Owner dapat mengunggah untuk dokter ini tanpa menautkan akun; pengguna lain hanya dapat mengunggah untuk akun dokter miliknya. Perubahan nama, SIP, atau STR mengharuskan unggah versi tanda tangan baru untuk penggunaan berikutnya; versi lama tetap disimpan.</p>@if($doctor && $access->isOwner() && $doctor->active)<a class="btn" href="{{ route('doctors.show', [$currentPharmacy, $doctor->id]) }}#signature-upload">Unggah tanda tangan dokter</a>@endif</div><div class="clinical-save"><p>Tidak ada penghapusan permanen profil dokter.</p><button class="btn primary">Simpan profil dokter</button></div></form></div>
@endsection
