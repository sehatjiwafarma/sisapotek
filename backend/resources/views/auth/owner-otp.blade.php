@extends('layouts.guest')
@section('title','Konfirmasi login Owner')
@section('content')
<p class="muted">Password telah diperiksa. Akses aplikasi menunggu kode OTP dari Owner lainnya.</p>
<div class="otp-context"><small>Akun yang akan masuk</small><strong>{{ $user->email }}</strong><small>Kode dikirim oleh email aplikasi ke <strong>{{ $recipient }}</strong>.</small><small>Referensi {{ substr($challenge->id,0,8) }} · berlaku hingga {{ \Illuminate\Support\Carbon::parse($challenge->expires_at)->timezone('Asia/Jakarta')->format('H:i') }} WIB.</small></div>
<form method="post" action="{{ route('owner-otp.verify') }}" data-busy-label="Memverifikasi…">@csrf
    <label for="owner-otp">Kode OTP 6 digit<input class="otp-input" id="owner-otp" name="otp" type="text" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" minlength="6" maxlength="6" placeholder="000000" required autofocus></label>
    <button class="btn primary full">Verifikasi & masuk</button>
</form>
<p class="otp-help">Hubungi Owner lainnya untuk meminta kode pada email dengan referensi yang sama. Gunakan kode terbaru; kode hanya berlaku sekali.</p>
<div class="otp-secondary"><form method="post" action="{{ route('owner-otp.resend') }}">@csrf<button class="btn small">Kirim ulang kode</button></form><form method="post" action="{{ route('owner-otp.cancel') }}">@csrf<button class="btn small">Batalkan login</button></form></div>
<small>Tunggu 60 detik sebelum mengirim ulang. Maksimal 3 pengiriman dalam 15 menit dan 5 percobaan kode. Fitur “Ingat saya” tidak melewati OTP Owner.</small>
@endsection
