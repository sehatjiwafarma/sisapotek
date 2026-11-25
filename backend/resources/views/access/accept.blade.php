@extends('layouts.guest')
@section('title', 'Undangan bergabung')
@section('content')
<p>Anda diundang bergabung ke <strong>{{ $invite->pharmacy->name }}</strong>.</p><p class="muted">Berlaku sampai {{ $invite->expires_at->timezone('Asia/Jakarta')->format('d/m/Y H:i') }} WIB.</p>
@auth
@if(auth()->user()->email === $invite->email)<form method="post" action="{{ route('invitations.accept', $token) }}">@csrf<button class="btn primary full">Terima undangan</button></form>@else<div class="notice warning">Akun saat ini berbeda dari email penerima undangan. Keluar lalu masuk dengan akun penerima.</div><form method="post" action="{{ route('logout') }}">@csrf<button class="btn">Keluar</button></form>@endif
@else<p>Masuk atau buat akun dengan email penerima undangan.</p><div class="actions"><a class="btn primary" href="{{ route('login') }}">Masuk</a><a class="btn" href="{{ route('register') }}">Buat akun</a></div>@endauth
@endsection
