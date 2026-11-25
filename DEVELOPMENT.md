# Rencana development sistem informasi apotek

Referensi pengerjaan modul resep dan penebusan: [Analisis dosis, substitusi, dan realisasi stok](Doc/Referensi-Modul-Resep-dan-Penebusan.md). Dokumen ini mencatat perbedaan kekuatan produk dan dosis, koreksi contoh 18/36 tablet, serta usulan verifikasi apoteker dan pencatatan penyerahan.

Tanggal evaluasi: 4 November 2026. Status: stack Laravel diterima; fase pertama user dan role management multi-apotek mulai diimplementasikan di `backend/`. Panduan aplikasi ada di `backend/README-ACCESS.md`. Scope komersial tetap mengacu pada kesepakatan proyek.

## Kondisi proyek

- `ui-apotek/` merupakan prototipe HTML, CSS, dan JavaScript tanpa backend. Data kembali ke contoh setelah reload.
- Enam pengujian alur klinis lulus dengan `node ui-apotek/tests/clinical.test.cjs`.
- Node.js, npm, dan Docker CLI tersedia. PHP, Composer, dan PostgreSQL tidak terdeteksi di PATH. Ketersediaan Docker engine belum diverifikasi.
- Acuan kebutuhan: `Spesifikasi_UI_Sistem_Informasi_Apotek_v1.md`. Keputusan operasional pada bagian 10 masih terbuka.

## Rekomendasi arsitektur

Gunakan monolit modular Laravel + PostgreSQL, Blade untuk halaman operasional, dan JavaScript terarah untuk interaksi POS. Pertahankan desain prototipe sebagai acuan; pindahkan aturan bisnisnya ke server, bukan menyalin seluruh handler browser ke produksi.

| Pilihan | Pertimbangan untuk proyek ini |
| --- | --- |
| Laravel + Blade + PostgreSQL | Pilihan utama: satu aplikasi untuk autentikasi, validasi, otorisasi, transaksi, cetak, dan laporan. Memerlukan setup PHP/Composer atau container. |
| Laravel + React/Inertia + PostgreSQL | Layak bila interaksi UI makin kompleks dan tim menguasai React; menambah pekerjaan migrasi komponen dan state frontend. |
| Node.js/TypeScript + PostgreSQL | Cocok bila keahlian tim dominan TypeScript; tetap perlu memilih serta mengintegrasikan autentikasi, otorisasi, validasi, dan struktur backend. Ketersediaan Node lokal saja bukan alasan cukup untuk memilihnya. |

Keputusan pengguna: aplikasi mendukung multi-apotek dengan role khusus per apotek dan izin per fungsi. Satu akun dapat menjadi anggota beberapa apotek. Target hosting VPS Biznet Gio; koneksi jaringan dan pembayaran manual menjadi asumsi awal, tanpa sinkronisasi offline.

Gunakan Docker Compose untuk lingkungan lokal setelah engine diverifikasi. Deployment awal dapat memakai satu server aplikasi dan PostgreSQL dengan penyimpanan persisten, HTTPS, backup terjadwal, serta uji restore. Redis belum menjadi kebutuhan awal; tambahkan bila beban antrean/cache memerlukannya.

## Urutan implementasi

1. Fondasi: scaffold aplikasi, migrasi database, login berbasis sesi, peran jamak, policy per aksi, audit, konfigurasi apotek, dan seed khusus development.
2. Master dan stok: obat, satuan serta konversi, pemasok, PO, penerimaan parsial, batch/expired, ledger mutasi, dan kartu stok.
3. Penjualan umum: pencarian, alokasi FEFO, pembayaran manual, transaksi final, struk, dan laporan penjualan. Tahap 1–3 menghasilkan alur operasional pertama yang dapat diuji menyeluruh.
4. Layanan klinis: pasien/dokter, kunjungan, draft/final SOAP, amendemen, resep dan snapshot tanda tangan, verifikasi serta penyerahan farmasi, penagihan kasir tanpa pemotongan stok kedua.
5. Opname dan penyelesaian MVP: snapshot, selisih, approval, koreksi stok, laporan utama, pembatasan ekspor, backup/restore, dan UAT tiap peran.

Tahap 4–5 tetap bagian MVP spesifikasi. Retur yang lebih lengkap dan integrasi lanjutan mengikuti prioritas serta kesepakatan scope sumber.

## Aturan implementasi yang harus dijaga

- Mutasi stok immutable dan memiliki referensi sumber. Saldo batch berubah dalam transaksi database yang sama dengan ledger.
- Saat posting, kunci batch terkait dan periksa saldo kembali; jangan percaya saldo dari browser. Urutan penguncian konsisten dan retry deadlock terbatas.
- Posting ulang permintaan yang sama tidak boleh menggandakan stok, pembayaran, atau nomor dokumen; gunakan constraint unik dan kunci idempotensi.
- Alokasi FEFO mengecualikan batch expired. Stok minus diblokir secara default.
- Konversi satuan, harga, instruksi resep, dan versi tanda tangan disimpan sebagai snapshot transaksi final.
- Uang memakai tipe numerik eksak dan aturan pembulatan eksplisit. Tanggal operasional memakai Asia/Jakarta; timestamp disimpan konsisten dan ditampilkan sesuai zona itu.
- Otorisasi diperiksa di server termasuk endpoint cetak/ekspor. Data klinis dan gambar tanda tangan disimpan privat; audit tidak menyalin isi medis ke log umum.
- Penjualan umum memotong stok saat final. Resep memotong stok saat penyerahan; kasir hanya mencatat pembayaran terkait.

## Verifikasi sebelum go-live

Uji menggunakan PostgreSQL: dua kasir berebut stok terakhir; penerimaan dua batch; FEFO dan expired; request posting berulang; rollback jika satu langkah gagal; penyerahan resep lalu pembayaran; amendemen rekam medis; akses kasir ditolak ke data klinis; approval opname; dan pemulihan backup. Pengujian prototipe yang lulus belum membuktikan perilaku backend ini.

## Keputusan bisnis yang perlu dibawa ke implementasi

Konfirmasi satu/multi-outlet dan pengguna simultan, pembayaran resep wajib atau opsional, penjualan per kemasan, termin utang pembelian, otorisasi koreksi/retur, format cetak, printer, serta laporan wajib. Keputusan ini tidak menghalangi penyusunan fondasi, tetapi harus selesai sebelum modul terkait difinalkan.

## Referensi teknis

- Transaksi Laravel: https://laravel.com/framework/docs/database/config
- Penguncian PostgreSQL: https://www.postgresql.org/docs/current/explicit-locking.html
