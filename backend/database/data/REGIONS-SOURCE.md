# Sumber data wilayah

Terakhir diverifikasi dan disinkronkan ke database development: 5 November 2026.

- Sumber: https://github.com/cahyadsn/wilayah
- Berkas: https://raw.githubusercontent.com/cahyadsn/wilayah/master/db/wilayah.sql
- Rujukan README sumber: Kepmendagri No. 300.2.2-2430 Tahun 2025.
- Header SQL sumber masih menyebut Kepmendagri No. 300.2.2-2138 Tahun 2025, dengan last edit 2026-02-13 16:45:21. Perbedaan rujukan ini mengikuti sumber, bukan klaim verifikasi setiap baris terhadap lampiran keputusan.
- SHA-256 wilayah.sql: c4c3396d9380d4edee072af1d9dff83573b574d7cd00a6562cf82e200e954031
- Lisensi: MIT, lihat REGIONS-LICENSE.

Snapshot yang diunduh saat verifikasi identik dengan wilayah.sql lokal. regions.csv digunakan untuk impor transaksional melalui `php artisan apotek:import-regions database/data/regions.csv`; SQL sumber tidak dieksekusi.

Hasil impor: 38 provinsi, 514 kabupaten/kota, 7.285 kecamatan, dan 83.762 desa/kelurahan (91.599 wilayah). Tidak ditemukan wilayah tanpa induk. Impor melakukan upsert dan tidak menghapus kode yang telah digunakan.

Data merupakan snapshot publik terbaru yang ditemukan dari sumber tersebut pada tanggal pemeriksaan, bukan sinkronisasi langsung dengan sistem Kemendagri. Perubahan administrasi setelah snapshot perlu diverifikasi kembali.
