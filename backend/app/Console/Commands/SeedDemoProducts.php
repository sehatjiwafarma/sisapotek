<?php

namespace App\Console\Commands;

use App\Models\Pharmacy;
use App\Services\AccessManager;
use Illuminate\Console\Command;

class SeedDemoProducts extends Command
{
    protected $signature = 'apotek:demo-products {pharmacy : Kode atau UUID apotek tujuan}';

    protected $description = 'Isi katalog contoh berlabel simulasi tanpa mengubah produk yang sudah ada.';

    public function handle(AccessManager $manager): int
    {
        $pharmacy = (new Pharmacy)->resolveRouteBinding($this->argument('pharmacy'), 'code')
            ?? Pharmacy::find($this->argument('pharmacy'));
        if (! $pharmacy) {
            $this->error('Apotek tidak ditemukan.');

            return self::FAILURE;
        }

        // All brands, packaging and prices are fictitious UI fixtures, not a clinical catalog.
        $rows = [
            ['PCM-A', 'Paracetamol', 'A', 'Obat', 'Paracetamol', 'Tablet', 'Tablet', 'Box', 100, 20000, 500],
            ['PCM-B', 'Paracetamol', 'B', 'Obat', 'Paracetamol', 'Tablet', 'Tablet', 'Box', 100, 30000, 750],
            ['PCM-C', 'Paracetamol', 'C', 'Obat', 'Paracetamol', 'Sirup', 'Botol', 'Box', 12, 90000, 12000],
            ['IBU-A', 'Ibuprofen', 'A', 'Obat', 'Ibuprofen', 'Tablet', 'Tablet', 'Box', 100, 40000, 1000],
            ['IBU-B', 'Ibuprofen', 'B', 'Obat', 'Ibuprofen', 'Tablet', 'Tablet', 'Box', 100, 50000, 1250],
            ['CTZ-A', 'Cetirizine', 'A', 'Obat', 'Cetirizine', 'Tablet', 'Tablet', 'Box', 50, 25000, 1000],
            ['CTZ-B', 'Cetirizine', 'B', 'Obat', 'Cetirizine', 'Tablet', 'Tablet', 'Box', 50, 35000, 1500],
            ['ANT-A', 'Antasida', 'A', 'Obat', 'Antasida', 'Tablet', 'Tablet', 'Box', 100, 25000, 600],
            ['ANT-B', 'Antasida', 'B', 'Obat', 'Antasida', 'Suspensi', 'Botol', 'Box', 12, 120000, 16000],
            ['VC-A', 'Vitamin C', 'A', 'Vitamin & suplemen', 'Vitamin C', 'Tablet', 'Tablet', 'Box', 100, 30000, 750],
            ['VC-B', 'Vitamin C', 'B', 'Vitamin & suplemen', 'Vitamin C', 'Tablet', 'Tablet', 'Box', 100, 45000, 1000],
            ['VD-A', 'Vitamin D', 'A', 'Vitamin & suplemen', 'Vitamin D', 'Kapsul', 'Kapsul', 'Botol', 30, 30000, 1500],
            ['MULTI-A', 'Multivitamin', 'A', 'Vitamin & suplemen', null, 'Tablet', 'Tablet', 'Botol', 30, 45000, 2500],
            ['MASK-A', 'Masker medis', 'A', 'Alat kesehatan', null, null, 'Pcs', 'Box', 50, 20000, 1000],
            ['MASK-B', 'Masker medis', 'B', 'Alat kesehatan', null, null, 'Pcs', 'Box', 50, 30000, 1500],
            ['KASA-A', 'Kasa steril', 'A', 'Alat kesehatan', null, null, 'Pack', 'Box', 10, 25000, 5000],
            ['PLES-A', 'Plester luka', 'A', 'Alat kesehatan', null, null, 'Pcs', 'Box', 100, 30000, 750],
            ['THERM-A', 'Termometer digital', 'A', 'Alat kesehatan', null, null, 'Pcs', 'Box', 10, 250000, 40000],
            ['HAND-A', 'Pembersih tangan', 'A', 'Perawatan tubuh', null, 'Gel', 'Botol', 'Box', 12, 84000, 12000],
            ['SOAP-A', 'Sabun cair', 'A', 'Perawatan tubuh', null, null, 'Botol', 'Box', 12, 120000, 16000],
            ['LOTION-A', 'Losion tubuh', 'A', 'Perawatan tubuh', null, null, 'Botol', 'Box', 12, 180000, 25000],
            ['COTTON-A', 'Kapas', 'A', 'Produk lainnya', null, null, 'Pack', 'Box', 20, 60000, 5000],
            ['TISSUE-A', 'Tisu basah', 'A', 'Produk lainnya', null, null, 'Pack', 'Box', 24, 72000, 6000],
            ['BAG-A', 'Kantong obat', 'A', 'Produk lainnya', null, null, 'Pcs', 'Pack', 100, 15000, 300],
        ];

        $created = $manager->locked($pharmacy, function () use ($rows, $pharmacy, $manager) {
            $count = 0;
            foreach ($rows as [$sku, $name, $brand, $category, $generic, $form, $base, $purchase, $conversion, $buy, $sell]) {
                if ($pharmacy->products()->where('sku', 'DEMO-'.$sku)->exists()) {
                    continue;
                }
                $product = $pharmacy->products()->create([
                    'sku' => 'DEMO-'.$sku, 'name' => $name.' (Contoh)', 'brand' => 'Brand '.$brand.' (Simulasi)',
                    'generic_name' => $generic, 'category' => $category, 'dosage_form' => $form, 'strength' => null,
                    'base_unit' => $base, 'purchase_unit' => $purchase, 'purchase_conversion' => $conversion,
                    'purchase_price' => $buy, 'selling_price' => $sell, 'minimum_stock' => 10, 'maximum_stock' => 200,
                    'active' => true, 'requires_prescription' => $category === 'Obat',
                    'notes' => 'DATA SIMULASI untuk uji UI. Brand, kemasan, harga, dan batas stok bersifat fiktif. Tidak ada saldo stok awal. Penanda resep pada contoh obat diaktifkan untuk uji alur, bukan klasifikasi resmi. Verifikasi seluruh data dengan produk fisik dan kebijakan apotek sebelum operasional.',
                ]);
                $manager->audit($pharmacy, null, 'product.demo_created', $product->id, ['sku' => $product->sku, 'demo' => true]);
                $count++;
            }

            return $count;
        });
        $this->info($created.' produk contoh ditambahkan ke '.$pharmacy->name.' ('.$pharmacy->code.'). Produk yang sudah ada tidak diubah.');

        return self::SUCCESS;
    }
}
