# Prototipe UI Apotek Sehat

Buka `index.html` di browser. Tidak memerlukan instalasi atau server.
Data hanya disimpan dalam memori sesi dan kembali ke data contoh saat reload.

## Alur layanan pasien terintegrasi

1. Buka **Layanan Pasien → Daftarkan kunjungan**. Pilih pasien dan dokter, isi keluhan. Jika pasien belum ada, gunakan **Daftarkan pasien baru**; setelah disimpan Anda kembali ke formulir pendaftaran dengan data sebelumnya tetap terisi.
2. Klik **Daftarkan & buka pemeriksaan**. Nomor kunjungan dan antrean dibuat otomatis. Pemeriksaan mengambil pasien, dokter, alergi, dan keluhan dari kunjungan tersebut.
3. Isi SOAP. **Simpan draft** untuk melanjutkan nanti dari daftar kunjungan, atau **Finalkan & lanjut ke resep**. Catatan final terkunci; koreksi dicatat sebagai amendemen per kunjungan.
4. Editor resep otomatis membawa pasien, dokter, nomor kunjungan, dan diagnosis. Identitas tidak perlu dipilih ulang. Isi obat, dosis, jumlah, serta aturan pakai. Jika tanda tangan dokter belum ada, unggah PNG/JPG maksimum 2 MB langsung pada editor.
5. Klik **Finalkan & kirim ke farmasi**, lalu **Buka antrean farmasi**. Resep memiliki referensi pasien, kunjungan, dokter, dan snapshot tanda tangan.
6. Di Farmasi, verifikasi dan siapkan resep, lalu konfirmasi penyerahan. Stok dipotong saat penyerahan. Pembayaran melalui kasir tidak memotong stok kembali.

Pasien bisa memiliki banyak kunjungan. Kunjungan baru tidak mengubah pemeriksaan atau resep historis. Kunjungan aktif pasien pada tanggal yang sama diblokir agar tidak terdaftar ganda. Satu kunjungan pada prototipe ini memiliki satu resep final.

Riwayat per kunjungan tersedia melalui **Layanan Pasien → Rekam medis**. Contoh resep bawaan juga telah diberi hubungan kunjungan; resep Siti berasal dari kunjungan hari sebelumnya.

## Pemeriksaan logika

Jalankan `node tests/clinical.test.cjs` dari folder ini. Pengujian mencakup hubungan kunjungan, draft/final pemeriksaan, prasyarat resep, duplikasi, dan catatan kunjungan lama.

Prototipe belum memiliki backend, login, atau pembatasan akses produksi. Pembuatan resep dan transaksi merupakan simulasi UI.
