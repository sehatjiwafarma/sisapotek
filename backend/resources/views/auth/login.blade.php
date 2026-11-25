@extends('layouts.guest')
@section('title', 'Selamat datang kembali')
@section('auth-class', 'login-page')
@push('styles')
<link rel="stylesheet" href="{{ asset('css/login.css') }}?v={{ filemtime(public_path('css/login.css')) }}">
@endpush
@section('content')
<p class="muted login-intro">Masuk untuk mengelola apotek dan akses tim Anda.</p>
<form class="login-form" method="post" action="{{ route('login') }}">
    @csrf
    <label for="login-email">Email
        <input id="login-email" type="email" name="email" value="{{ old('email') }}" required autocomplete="username" placeholder="nama@email.com" autofocus>
    </label>
    <label for="login-password">Password
        <input id="login-password" type="password" name="password" required autocomplete="current-password" placeholder="Masukkan password Anda">
    </label>
    <div class="login-options">
        <label class="check"><input type="checkbox" name="remember" value="1"><span>Ingat saya di perangkat ini</span></label>
        <a href="{{ route('password.request') }}">Lupa password?</a>
    </div>
    <button class="btn primary full" type="submit">Masuk</button>
</form>
<p class="login-register">Belum punya akun? <a href="{{ route('register') }}">Buat akun</a></p>
@endsection
