@extends('layouts.app')
@section('title', 'Ubah role pengguna')
@section('content')
<div class="heading"><div><h1>Ubah role pengguna</h1><p>{{ $member->user->name }} · {{ $member->user->email }}</p></div><a class="btn" href="{{ route('users.index', $currentPharmacy) }}">Kembali</a></div><section class="card padded narrow"><form method="post" action="{{ route('users.update', [$currentPharmacy, $member]) }}">@csrf @method('PUT')@include('access.role-checkboxes', ['selected' => $member->roles->pluck('id')->all()])<p class="muted">Izin seluruh role digabungkan dan berlaku hanya di apotek ini. Minimal satu Owner harus tetap aktif.</p><button class="btn primary">Simpan role</button></form></section>
@endsection
