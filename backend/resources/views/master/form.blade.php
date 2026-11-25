@extends('layouts.app')
@section('title', $pharmacy->exists ? 'Edit master apotek' : 'Registrasi master apotek')
@include('master.assets')
@section('content')
@php($field=fn($label,$name,$value='',$required=false,$type='text',$max=null)=>view('master.field',compact('label','name','value','required','type','max'))->render())
<div class="heading"><div><span class="eyebrow">MASTER APOTEK</span><h1>{{ $pharmacy->exists ? 'Edit profil apotek' : 'Daftarkan apotek' }}</h1><p>Simpan identitas sebagai draft, lalu lengkapi legalitas dan APJ sebelum aktivasi oleh Admin Sistem.</p></div><a class="btn" href="{{ $pharmacy->exists ? route('master.show',$pharmacy) : route('pharmacies.index') }}">Kembali</a></div>
@if($provinces->isEmpty())<div class="notice warning">Master wilayah belum tersedia. Operator server perlu mengimpor master wilayah sebelum registrasi dapat disimpan.</div>@endif
<form method="post" enctype="multipart/form-data" action="{{ $pharmacy->exists ? route('master.update',$pharmacy) : route('pharmacies.store') }}">@csrf @if($pharmacy->exists) @method('PATCH') @endif
<div class="master-tabs" role="tablist" aria-label="Bagian profil apotek"><button type="button" data-master-tab="general">Informasi umum</button><button type="button" data-master-tab="address">Alamat & kontak</button><button type="button" data-master-tab="owner">Pemilik</button></div>
<section class="card padded" data-master-panel="general"><h2>Informasi umum</h2><div class="master-fields">
{!! $field('Kode apotek','code',$pharmacy->code,true,'text',20) !!}{!! $field('Nama apotek','name',$pharmacy->name,true,'text',150) !!}
<label>Jenis kepemilikan *<select name="ownership_type" required><option value="Individual" @selected(old('ownership_type',$pharmacy->ownership_type)==='Individual')>Perorangan</option><option value="Legal Entity" @selected(old('ownership_type',$pharmacy->ownership_type)==='Legal Entity')>Badan usaha</option></select></label>
<label>Bentuk badan hukum<select name="legal_form"><option value="">Pilih jika badan usaha</option>@foreach(['PT','CV','Koperasi','Yayasan','Lainnya'] as $form)<option @selected(old('legal_form',$pharmacy->legal_form)===$form)>{{ $form }}</option>@endforeach</select></label>
{!! $field('Nama badan usaha','business_name',$pharmacy->business_name,false,'text',200) !!}
<label>Logo apotek<input type="file" name="logo" accept="image/jpeg,image/png,image/webp" data-logo-input><small>JPG, PNG atau WEBP. Maksimal 2 MB.</small><img class="master-logo-preview" data-logo-preview @if($pharmacy->logo_path) src="{{ route('master.logo',$pharmacy) }}" @else hidden @endif alt="Pratinjau logo"></label></div></section>
<section class="card padded" data-master-panel="address"><h2>Alamat & kontak</h2><div class="master-fields">
{!! $field('Nomor telepon','phone',$pharmacy->phone,true,'tel',20) !!}{!! $field('Email','email',$pharmacy->email,false,'email',254) !!}{!! $field('Website','website',$pharmacy->website,false,'url',500) !!}
{!! $field('Alamat lengkap','address',$pharmacy->address,true,'textarea',500) !!}
@foreach(['province'=>'Provinsi','city'=>'Kabupaten/kota','district'=>'Kecamatan','village'=>'Kelurahan/desa'] as $level=>$label)
<div class="region-field"><label id="region-label-{{ $level }}" for="region-select-{{ $level }}">{{ $label }} *</label><select id="region-select-{{ $level }}" data-region-label="{{ $label }}" name="{{ $level }}_code" data-region="{{ $level }}" data-region-url="{{ route('master.regions') }}" required><option value="">Pilih {{ mb_strtolower($label) }}</option>@foreach(($level==='province'?$provinces:$regionOptions[$level]) as $region)<option value="{{ $region->code }}" @selected(old($level.'_code',$pharmacy->{$level.'_code'})===$region->code)>{{ $region->name }}</option>@endforeach</select></div>
@endforeach
{!! $field('Kode pos','postal_code',$pharmacy->postal_code,false,'text',10) !!}{!! $field('Latitude','latitude',$pharmacy->latitude,false,'number') !!}{!! $field('Longitude','longitude',$pharmacy->longitude,false,'number') !!}{!! $field('Google Maps URL','maps_url',$pharmacy->maps_url,false,'url',500) !!}
</div><p class="muted" id="region-feedback" role="status"></p></section>
<section class="card padded" data-master-panel="owner"><h2>Informasi pemilik</h2><p>Tambahkan seluruh pemilik beserta persentase sahamnya. Total saham harus 100% (maksimal 2 angka desimal).</p>
<div data-owners>
@foreach(old('owners', $pharmacy->owners ?: [['name'=>'','phone'=>'','email'=>'','share_percentage'=>100]]) as $index => $owner)
@include('master.owner-row', ['index'=>$index, 'owner'=>$owner])
@endforeach
</div>
<template data-owner-template>@include('master.owner-row', ['index'=>'__INDEX__', 'owner'=>[]])</template>
<button class="btn" type="button" data-owner-add>Tambah pemilik</button>
@error('owners')<p class="notice error">{{ $message }}</p>@enderror
</section>
<div class="sticky-actions"><span>Field bertanda * wajib diisi. Apotek baru berstatus DRAFT.</span><button class="btn primary">{{ $pharmacy->exists ? 'Simpan perubahan' : 'Simpan draft' }}</button></div></form>
@endsection
