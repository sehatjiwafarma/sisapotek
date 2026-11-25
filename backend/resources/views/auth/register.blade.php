@extends('layouts.guest')
@section('title', 'Buat akun Anda')
@section('content')
<p class="muted">Satu akun untuk apotek yang Anda kelola. Untuk menerima undangan, gunakan email penerima.</p><form method="post" action="{{ route('register') }}">@csrf
<label>Nama lengkap<input name="name" value="{{ old('name') }}" maxlength="150" required autocomplete="name"></label>
<label>Email<input type="email" name="email" value="{{ old('email') }}" required autocomplete="username"></label>
<label>Password<input type="password" name="password" minlength="12" required autocomplete="new-password"><small>Minimal 12 karakter, berisi huruf dan angka.</small></label>
<label>Konfirmasi password<input type="password" name="password_confirmation" required autocomplete="new-password"></label><button class="btn primary full">Buat akun</button></form><p>Sudah punya akun? <a href="{{ route('login') }}">Masuk</a></p>
@endsection
