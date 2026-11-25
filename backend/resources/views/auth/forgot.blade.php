@extends('layouts.guest')
@section('title', 'Reset password')
@section('content')
<p class="muted">Masukkan email akun untuk menerima tautan reset.</p><form method="post" action="{{ route('password.email') }}">@csrf<label>Email<input type="email" name="email" value="{{ old('email') }}" required autocomplete="email"></label><button class="btn primary full">Kirim tautan reset</button></form><p><a href="{{ route('login') }}">Kembali ke halaman masuk</a></p>
@endsection
