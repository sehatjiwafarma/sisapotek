<!doctype html><html lang="id"><head><meta charset="utf-8"><title>Persetujuan login Owner</title></head><body style="margin:0;padding:24px;background:#f6f8f6;color:#243b33;font-family:Arial,sans-serif">
<div style="max-width:560px;margin:auto;background:#fff;border:1px solid #dce4de;border-radius:12px;padding:28px">
    <h1 style="font-size:22px;margin-top:0">Permintaan login Owner</h1>
    <p>Akun <strong>{{ $loginEmail }}</strong> meminta akses ke Sehat Jiwa.</p>
    <p>Berikan kode berikut kepada Owner tersebut hanya jika Anda menyetujui permintaan login ini.</p>
    <p style="font-size:32px;letter-spacing:8px;font-weight:bold;padding:18px;background:#edf5f0;text-align:center">{{ $code }}</p>
    <p><strong>Referensi:</strong> {{ $reference }}<br><strong>Diminta:</strong> {{ $requestedAt }}<br><strong>Berlaku hingga:</strong> {{ $expiresAt }}<br><strong>Alamat IP:</strong> {{ $ipAddress }}<br><strong>Perangkat:</strong> {{ $device }}</p>
    <p>Kode hanya berlaku sekali untuk permintaan login ini. Kode sebelumnya tidak berlaku jika kode baru diminta.</p>
    <p>Jika Anda tidak mengenali permintaan ini, jangan berikan kode dan hubungi Owner yang bersangkutan.</p>
    <hr style="border:0;border-top:1px solid #dce4de"><small>Email otomatis dari aplikasi Sehat Jiwa. Tidak perlu membalas email ini.</small>
</div></body></html>
