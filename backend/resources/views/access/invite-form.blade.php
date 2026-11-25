@extends('layouts.app')
@section('title', 'Undang pengguna')
@section('content')
<div class="heading"><div><h1>Undang pengguna</h1><p>Berikan akses ke {{ $currentPharmacy->name }} melalui undangan email.</p></div><a class="btn" href="{{ route('users.index', $currentPharmacy) }}">Kembali</a></div><section class="card padded narrow"><form method="post" action="{{ route('users.send-invite', $currentPharmacy) }}">@csrf<label>Email penerima<input name="email" type="email" value="{{ old('email') }}" required maxlength="255" autocomplete="email"></label>@include('access.role-checkboxes')<div class="notice">Penerima menentukan password sendiri. Undangan berlaku 72 jam; akun yang sudah ada dapat menerima akses apotek tambahan.</div><button class="btn primary">Kirim undangan</button></form></section>
@endsection
