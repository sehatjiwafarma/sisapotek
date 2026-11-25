@extends('layouts.app')
@section('title', 'Beranda apotek')
@push('styles')
<link rel="stylesheet" href="{{ asset('css/home.css') }}?v={{ filemtime(public_path('css/home.css')) }}">
@endpush
@section('content')
@if($pharmacy->status!=='ACTIVE')<div class="notice warning">Status apotek: <strong>{{ $pharmacy->status }}</strong>. Lengkapi <a href="{{ route('master.show',$pharmacy) }}">Master apotek</a> untuk ditinjau Admin Sistem sebelum transaksi operasional.</div>@endif
<section class="welcome-banner" aria-labelledby="welcome-title">
    <div class="welcome-copy">
        <span class="welcome-eyebrow">RUANG KERJA {{ $pharmacy->name }}</span>
        <h1 id="welcome-title">Selamat datang, {{ auth()->user()->name }}! <span aria-hidden="true">👋</span></h1>
        <p>Bersama Sehat Jiwa, mari hadirkan pelayanan yang ramah, tepat, dan tepercaya di {{ $pharmacy->name }}.</p>
    </div>
    <div class="welcome-art" aria-hidden="true">
        <span class="welcome-orbit"></span>
        <div class="welcome-mark"><img src="{{ asset('brand/sejiwa-mark.svg') }}" width="80" height="80" alt=""></div>
        <span class="welcome-spark welcome-spark-one">+</span>
        <span class="welcome-spark welcome-spark-two">+</span>
    </div>
</section>
@include('partials.soap-reminder-card')
@include('partials.quick-tasks')
<div class="section-heading"><h2>Kelola workspace</h2></div><div class="cards-grid">
@foreach([['users.view','users.index','Pengguna','Undang tim dan kelola keanggotaan.'],['roles.view','roles.index','Role & izin','Atur hak akses setiap fungsi.'],['audit.view','audit.index','Audit aktivitas','Telusuri perubahan akses tim.'],['pharmacy.view','pharmacy.edit','Profil apotek','Perbarui identitas apotek.']] as [$permission,$route,$label,$description])
@if($access->allows($permission))<a class="card padded feature-card" href="{{ route($route, $pharmacy) }}"><h2>{{ $label }} ↗</h2><p>{{ $description }}</p></a>@endif
@endforeach</div>
@include('access.access-summary')
@endsection
