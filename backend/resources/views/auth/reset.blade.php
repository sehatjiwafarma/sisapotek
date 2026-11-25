@extends('layouts.guest')
@section('title', 'Buat password baru')
@section('content')
<form method="post" action="{{ route('password.update') }}">@csrf<input type="hidden" name="token" value="{{ $token }}"><label>Email<input type="email" name="email" value="{{ old('email', request('email')) }}" required autocomplete="username"></label>
<label>Password baru<input type="password" name="password" minlength="12" required autocomplete="new-password"><small>Minimal 12 karakter, berisi huruf dan angka.</small></label><label>Konfirmasi password<input type="password" name="password_confirmation" required autocomplete="new-password"></label><button class="btn primary full">Simpan password baru</button></form>
@endsection
