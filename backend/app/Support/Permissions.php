<?php

namespace App\Support;

class Permissions
{
    public static function groups(): array
    {
        $crud = ['view' => 'Lihat', 'create' => 'Tambah', 'update' => 'Ubah', 'deactivate' => 'Nonaktifkan'];

        return [
            'users' => ['Pengguna', ['view' => 'Lihat', 'invite' => 'Undang', 'update' => 'Ubah role', 'deactivate' => 'Aktif/nonaktif', 'remove' => 'Cabut keanggotaan']],
            'roles' => ['Role & izin', ['view' => 'Lihat', 'create' => 'Tambah', 'update' => 'Ubah izin', 'delete' => 'Hapus']],
            'pharmacy' => ['Master apotek', ['view' => 'Lihat', 'view_sensitive' => 'Lihat legalitas & pemilik', 'update' => 'Ubah', 'update_limited' => 'Ubah kontak & jam', 'personnel' => 'Kelola APJ', 'documents_view' => 'Lihat dokumen', 'documents_upload' => 'Unggah dokumen', 'documents_delete' => 'Hapus dokumen', 'audit' => 'Riwayat perubahan']],
            'audit' => ['Audit akses', ['view' => 'Lihat']],
            'dashboard' => ['Dashboard', ['view' => 'Lihat']],
            'drugs' => ['Obat & produk', $crud], 'suppliers' => ['Pemasok', $crud],
            'patients' => ['Pasien', $crud + ['view_sensitive' => 'Lihat NIK & catatan pribadi', 'view_clinical' => 'Lihat alergi', 'export' => 'Ekspor (belum tersedia)']],
            'doctors' => ['Dokter', $crud + ['link_account' => 'Tautkan akun dokter', 'signature_view' => 'Lihat versi tanda tangan', 'signature_update' => 'Unggah tanda tangan sendiri']],
            'purchases' => ['Pembelian', ['view' => 'Lihat', 'create' => 'Tambah PO', 'update' => 'Ubah draft', 'finalize' => 'Finalkan PO', 'cancel' => 'Batalkan']],
            'receipts' => ['Penerimaan', ['view' => 'Lihat', 'create' => 'Tambah', 'post' => 'Posting stok']],
            'payables' => ['Utang & pembayaran supplier', ['view' => 'Lihat tagihan & pembayaran', 'create' => 'Ajukan pembayaran', 'approve' => 'Setujui pembayaran', 'post' => 'Catat pembayaran', 'cancel' => 'Batalkan pengajuan', 'reconcile' => 'Konfirmasi saldo awal', 'proof_view' => 'Lihat bukti pembayaran', 'print' => 'Cetak bukti pencatatan']],
            'inventory' => ['Persediaan', ['view' => 'Lihat', 'export' => 'Ekspor']],
            'discounts' => ['Master diskon POS', $crud],
            'taxes' => ['Master PPN POS', $crud],
            'stocktakes' => ['Stok opname', ['view' => 'Lihat', 'create' => 'Tambah', 'update' => 'Input fisik', 'approve' => 'Setujui', 'post' => 'Posting koreksi', 'cancel' => 'Batalkan sesi']],
            'visits' => ['Kunjungan', ['view' => 'Lihat', 'create' => 'Daftar', 'update' => 'Ubah', 'cancel' => 'Batalkan', 'complete' => 'Selesaikan layanan']],
            'examinations' => ['Rekam medis', ['view' => 'Lihat pemeriksaan sendiri & riwayat pasien dalam layanan', 'view_all' => 'Lihat seluruh pemeriksaan apotek', 'update' => 'Isi draft sendiri', 'finalize' => 'Finalkan sendiri', 'amend' => 'Amendemen sendiri']],
            'prescriptions' => ['Resep', ['view' => 'Lihat sendiri', 'view_all' => 'Lihat seluruh resep apotek', 'create' => 'Tambah', 'update' => 'Ubah draft', 'finalize' => 'Finalkan', 'print' => 'Cetak', 'cancel' => 'Batalkan']],
            'dispensing' => ['Farmasi', ['view' => 'Lihat antrean & penebusan', 'verify' => 'Verifikasi resep', 'prepare' => 'Siapkan penebusan', 'multiple_prescriptions' => 'Tebus resep dari pemeriksaan dengan lebih dari satu resep', 'handover' => 'Serahkan obat', 'print' => 'Cetak etiket']],
            'sales' => ['Kasir', ['view' => 'Lihat', 'create' => 'Tambah', 'pay' => 'Pembayaran', 'discount' => 'Diskon', 'cancel' => 'Batalkan', 'print' => 'Cetak struk']],
            'returns' => ['Retur', ['view' => 'Lihat', 'create' => 'Ajukan', 'approve' => 'Setujui', 'refund' => 'Catat refund', 'cancel' => 'Batalkan pengajuan', 'print' => 'Cetak bukti refund', 'restore_entitlement' => 'Setujui pemulihan hak tebus resep']],
            'supplier_returns' => ['Retur pembelian supplier', ['view' => 'Lihat', 'create' => 'Ajukan', 'post' => 'Setujui & posting', 'cancel' => 'Batalkan pengajuan', 'recover' => 'Catat dana dari supplier']],
            'billing' => ['Tagihan layanan', ['view' => 'Lihat', 'create' => 'Buat tagihan', 'pay' => 'Bayar', 'cancel' => 'Batalkan draft', 'tariffs' => 'Kelola tarif', 'print' => 'Cetak tagihan']],
            'expenses' => ['Pengeluaran operasional', ['view' => 'Lihat', 'create' => 'Ajukan', 'approve' => 'Setujui', 'post' => 'Catat pembayaran', 'cancel' => 'Batalkan pengajuan', 'categories' => 'Kelola kategori']],
            'shifts' => ['Shift & tutup kas', ['view' => 'Lihat shift sendiri', 'create' => 'Buka shift', 'close' => 'Tutup shift', 'manage' => 'Lihat/tutup seluruh shift & tambah modal']],
            'reports' => ['Laporan', ['sales' => 'Penjualan', 'purchases' => 'Pembelian', 'inventory' => 'Stok', 'finance' => 'Mutasi dana', 'clinical' => 'Klinis (belum tersedia)', 'export' => 'Ekspor']],
            'settings' => ['Pengaturan (belum tersedia)', ['view' => 'Lihat', 'update' => 'Ubah']],
        ];
    }

    public static function all(): array
    {
        $result = [];
        foreach (self::groups() as $module => [$label, $actions]) {
            foreach ($actions as $action => $title) {
                $result["$module.$action"] = "$label: $title";
            }
        }

        return $result;
    }
}
