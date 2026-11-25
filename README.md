# Sistem Informasi Apotek

## Aplikasi development

Backend dan halaman aplikasi aktif berada di [`backend/`](backend/README-ACCESS.md), menggunakan Laravel 13 + Blade + PostgreSQL. Fase pertama menyediakan multi-apotek, akun, role khusus per apotek, izin per fungsi, undangan pengguna, dan audit perubahan akses.

Jika layanan Docker lokal sudah berjalan, buka **http://localhost:8000**. Buat akun sendiri lalu daftarkan apotek pertama; tidak ada kredensial default.

Panduan instalasi, pengujian, alur penggunaan, dan konfigurasi email: [`backend/README-ACCESS.md`](backend/README-ACCESS.md).

## Acuan proyek

- [`ui-apotek/`](ui-apotek/README.md): prototipe desain, data simulasi dalam memori.
- [`Spesifikasi_UI_Sistem_Informasi_Apotek_v1.md`](Spesifikasi_UI_Sistem_Informasi_Apotek_v1.md): kebutuhan dan aturan operasional.
- [`DEVELOPMENT.md`](DEVELOPMENT.md): evaluasi arsitektur dan tahapan pengembangan.

Modul transaksi apotek belum diimplementasikan pada backend fase pertama. Katalog izinnya telah disiapkan untuk integrasi berikutnya.
