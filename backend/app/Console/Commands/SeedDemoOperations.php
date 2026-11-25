<?php

namespace App\Console\Commands;

use App\Models\Pharmacy;
use App\Services\AccessManager;
use App\Services\ClinicalMaster;
use App\Services\ExaminationRecords;
use App\Services\PointOfSale;
use App\Services\PrescriptionDispensing;
use App\Services\PrescriptionRecords;
use App\Services\Procurement;
use App\Services\ServiceBilling;
use App\Services\Stocktaking;
use App\Services\VisitRegistration;
use App\Support\Decimal;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SeedDemoOperations extends Command
{
    protected $signature = 'apotek:demo-operations {pharmacy : Kode atau UUID apotek tujuan}';

    protected $description = 'Tambahkan skenario DUMMY pembelian, pelayanan, tanda tangan dan stok secara konsisten dan tanpa duplikasi.';

    private function key(int $type, int $index): string
    {
        return 'd00d'.str_pad((string) $type, 4, '0', STR_PAD_LEFT).'-0000-4000-8000-'.str_pad((string) $index, 12, '0', STR_PAD_LEFT);
    }

    public function handle(AccessManager $access, ClinicalMaster $clinical): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('Perintah simulasi hanya tersedia di local/testing.');

            return self::FAILURE;
        }
        $target = $this->argument('pharmacy');
        $pharmacy = Pharmacy::where('code', $target)->first() ?? (Str::isUuid($target) ? Pharmacy::find($target) : null);
        if (! $pharmacy || $pharmacy->status !== 'ACTIVE') {
            $this->error('Pilih apotek aktif yang tersedia.');

            return self::FAILURE;
        }
        $owner = $pharmacy->memberships()->where('active', true)->whereHas('roles', fn ($q) => $q->where('is_owner', true))->first();
        if (! $owner) {
            $this->error('Diperlukan Owner aktif sebagai konteks validasi transaksi simulasi.');

            return self::FAILURE;
        }
        $paths = [];
        try {
            $counts = $access->locked($pharmacy, function () use ($pharmacy, $owner, $clinical, $access, &$paths) {
                $actor = $owner->user_id;
                $counts = ['signatures' => 0, 'purchases' => 0, 'visits' => 0, 'stocktakes' => 0];
                $today = now($pharmacy->timezone)->toDateString();
                // Only explicitly identified demo doctors are eligible; preserve all existing signatures.
                for ($i = 1; $i <= 4; $i++) {
                    $doctor = ClinicalMaster::doctors($pharmacy)->where('request_key', $this->key(2, $i))->first();
                    if (! $doctor || ! $doctor->active || DB::table('doctor_signatures')->where('doctor_id', $doctor->id)->exists()) {
                        continue;
                    }
                    $file = new UploadedFile(resource_path('demo/doctor-signature.png'), 'DUMMY-signature.png', 'image/png', null, true);
                    $clinical->signature($pharmacy, $actor, $doctor->id, $doctor->revision, $file, $today);
                    $paths[] = DB::table('doctor_signatures')->where('doctor_id', $doctor->id)->orderByDesc('version')->value('path');
                    $counts['signatures']++;
                }
                $doctor = ClinicalMaster::doctors($pharmacy)->where('request_key', $this->key(2, 1))->where('active', true)->first();
                if (! $doctor) {
                    throw new \RuntimeException('Jalankan apotek:demo-clinical terlebih dahulu untuk menyediakan dokter dummy.');
                }
                $products = collect();
                for ($i = 1; $i <= 7; $i++) {
                    $product = $pharmacy->products()->firstOrCreate(['sku' => 'DEMO-OPS-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT)], [
                        'name' => 'Produk Simulasi '.$i.' (Dummy)', 'brand' => 'DUMMY Operasional', 'category' => 'Obat',
                        'base_unit' => 'Tablet', 'purchase_unit' => 'Box', 'purchase_conversion' => 10,
                        'purchase_price' => 10000, 'selling_price' => 1500, 'minimum_stock' => $i >= 6 ? 150 : 10,
                        'requires_prescription' => true, 'active' => true,
                    ]);
                    if ($product->wasRecentlyCreated) {
                        $access->audit($pharmacy, null, 'product.demo_created', $product->id, ['demo' => true, 'sku' => $product->sku]);
                    }
                    $products->push($product);
                }
                $this->purchases($pharmacy, $actor, $products, $counts);
                $this->visits($pharmacy, $actor, $doctor->id, $products, $counts);
                // Open counting sessions are intentionally last so they cannot block demo sales/receipts.
                $this->stocktakes($pharmacy, $actor, $products, $counts);
                $access->audit($pharmacy, null, 'operations.demo_seeded', 'demo_operations_v1', $counts + ['demo' => true, 'actor_context' => 'Simulasi lokal menggunakan konteks Owner, bukan tindakan dokter/pasien nyata.']);

                return $counts;
            });
        } catch (\Throwable $error) {
            foreach ($paths as $path) {
                Storage::disk('local')->delete($path);
            }
            throw $error;
        }
        $this->info('Data DUMMY ditambahkan ke '.$pharmacy->name.' ('.$pharmacy->code.').');
        $this->table(['Tanda tangan', 'Pembelian', 'Pelayanan', 'Stok opname'], [array_values($counts)]);
        $this->info('Dokumen lama dipertahankan. Mutasi stok dan kas hanya berasal dari layanan transaksi aplikasi. Jalankan ulang aman tanpa menggandakan skenario.');

        return self::SUCCESS;
    }

    private function purchases(Pharmacy $pharmacy, int $actor, $products, array &$counts): void
    {
        $service = app(Procurement::class);
        $today = now($pharmacy->timezone)->startOfDay();
        $supplier = $pharmacy->suppliers()->firstOrCreate(['code' => 'DEMO-OPS-SUP'], [
            'name' => 'Supplier Simulasi Operasional (Dummy)', 'address' => 'ALAMAT DUMMY - bukan alamat operasional',
            'email' => 'demo-operations@example.invalid', 'payment_days' => 30, 'active' => true,
        ]);
        foreach (['DRAFT', 'ORDERED', 'PARTIAL', 'RECEIVED', 'CANCELLED'] as $index => $status) {
            $number = 'DEMO-OPS-PO-'.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT);
            if ($pharmacy->purchaseOrders()->where('number', $number)->exists()) {
                continue;
            }
            $items = $products->take($status === 'RECEIVED' ? 6 : 3);
            $order = $service->saveOrder($pharmacy, $actor, [
                'supplier_id' => $supplier->id, 'ordered_on' => $today->copy()->subDays(4 - $index)->toDateString(),
                'expected_on' => $today->copy()->addDays(3)->toDateString(), 'payment_type' => 'credit', 'payment_days' => 30,
                'notes' => 'DATA DUMMY OPERASIONAL - simulasi status '.$status.'. Tidak ada pemesanan nyata.',
                'items' => $items->map(fn ($product) => ['product_id' => $product->id, 'unit_type' => 'purchase', 'quantity' => 12, 'unit_price' => 10000, 'discount' => 0, 'tax' => 0])->all(),
            ]);
            $order->update(['number' => $number]);
            if ($status !== 'DRAFT') {
                $service->finalize($pharmacy, $actor, $order->id);
            }
            if ($status === 'CANCELLED') {
                $service->cancel($pharmacy, $actor, $order->id, 'DUMMY - pembatalan pemesanan simulasi.');
            }
            if (in_array($status, ['ORDERED', 'PARTIAL', 'RECEIVED'], true)) {
                $receipt = $service->receive($pharmacy, $actor, $order->id, [
                    'received_on' => $today->toDateString(), 'invoice_number' => 'DEMO-OPS-INV-'.($index + 1), 'notes' => 'PENERIMAAN DUMMY - hanya untuk simulasi.',
                    'items' => $order->items()->get()->map(fn ($line, $i) => ['order_item_id' => $line->id, 'quantity' => $status === 'RECEIVED' ? 12 : 1,
                        'unit_price' => $line->unit_price, 'batch_number' => 'DEMO-OPS-BATCH-'.($index + 1).'-'.($i + 1),
                        'expires_on' => $today->copy()->addYears(2)->toDateString(), 'location' => 'Rak DUMMY Operasional'])->all(),
                ]);
                $receipt->update(['number' => 'DEMO-OPS-RCV-'.($index + 1)]);
                if ($status !== 'ORDERED') {
                    $service->post($pharmacy, $actor, $receipt->id);
                }
            }
            $counts['purchases']++;
        }
    }

    private function visits(Pharmacy $pharmacy, int $actor, string $doctor, $products, array &$counts): void
    {
        $billing = app(ServiceBilling::class);
        $tariff = DB::table('service_tariffs')->where('pharmacy_id', $pharmacy->id)->where('name', 'Konsultasi Simulasi (Dummy)')->value('id');
        $tariff ??= $billing->tariff($pharmacy, $actor, ['name' => 'Konsultasi Simulasi (Dummy)', 'price' => 50000, 'active' => true]);
        $visits = app(VisitRegistration::class);
        $rx = app(PrescriptionRecords::class);
        $dispensing = app(PrescriptionDispensing::class);
        foreach (['Menunggu', 'Dalam Layanan', 'Tagihan Draft', 'Tagihan Batal', 'Lunas Sebagian Obat', 'Selesai Multi Resep', 'Kunjungan Batal'] as $index => $name) {
            $key = $this->key(31, $index + 1);
            if (VisitRegistration::query($pharmacy)->where('request_key', $key)->exists()) {
                continue;
            }
            $patient = app(ClinicalMaster::class)->savePatient($pharmacy, $actor, ['request_key' => $this->key(30, $index + 1),
                'name' => 'Pasien '.$name.' (Dummy Operasional)', 'birth_date' => '1995-01-15', 'sex' => $index % 2 ? 'male' : 'female',
                'address' => 'ALAMAT DUMMY - bukan alamat pasien nyata', 'notes' => 'DATA DUMMY untuk pengujian status pelayanan.']);
            $visit = $visits->save($pharmacy, $actor, ['request_key' => $key, 'patient_id' => $patient, 'doctor_id' => $doctor,
                'type' => 'new', 'complaint' => 'DATA DUMMY - simulasi pelayanan '.$name.'.', 'services' => [['tariff_id' => $tariff, 'quantity' => 1]]]);
            if ($index === 6) {
                $visits->transition($pharmacy, $actor, $visit, ['revision' => 1, 'action' => 'cancel', 'reason' => 'DUMMY - pasien simulasi membatalkan kunjungan.']);
            } elseif ($index > 0) {
                $bill = $billing->create($pharmacy, $actor, ['request_key' => $this->key(50, $index + 1), 'visit_id' => $visit,
                    'items' => [['tariff_id' => $tariff, 'quantity' => 1]]]);
                if ($index === 3) {
                    $billing->cancel($pharmacy, $actor, $bill, ['revision' => 1, 'reason' => 'DUMMY - pembatalan tagihan untuk simulasi.']);
                }
                if (in_array($index, [2, 3], true)) {
                    $counts['visits']++;
                    continue;
                }
                $total = DB::table('service_bills')->where('id', $bill)->value('total');
                $billing->pay($pharmacy, $actor, $bill, ['revision' => 1, 'payment_method' => 'transfer', 'payment_reference' => 'DUMMY-PEMBAYARAN-LAYANAN', 'amount_received' => $total, 'confirmed' => 1]);
                $visits->transition($pharmacy, $actor, $visit, ['revision' => 1, 'action' => 'start']);
                $exam = app(ExaminationRecords::class);
                $exam->draft($pharmacy, $actor, $visit, 0, ['subjective' => 'DUMMY - keluhan simulasi.', 'objective' => 'DUMMY - pemeriksaan contoh, bukan hasil medis.', 'assessment' => 'DUMMY - penilaian simulasi.', 'plan' => 'DUMMY - rencana pelayanan contoh.']);
                if ($index > 1) {
                    $exam->finalize($pharmacy, $actor, $visit, 1);
                }
                $sale = null;
                foreach (range(0, $index === 5 ? 1 : 0) as $n) {
                    $product = $products[$n];
                    $id = $rx->save($pharmacy, $actor, $visit, 0, ['prescription' => 'new', 'rx_request_key' => $this->key(32 + $index, $n + 1), 'valid_until' => now($pharmacy->timezone ?: 'Asia/Jakarta')->addDays(30)->toDateString(),
                        'notes' => 'DATA DUMMY - bukan resep untuk digunakan pasien.', 'items' => [['product_id' => $product->id, 'quantity' => 3,
                            'dose' => 'DUMMY - dosis simulasi', 'frequency' => 'DUMMY - frekuensi simulasi', 'instructions' => 'CONTOH PENGUJIAN - tidak untuk digunakan sebagai terapi.']]]);
                    if ($index > 1) {
                        $rx->finalize($pharmacy, $actor, $visit, 1, $id);
                    }
                    if (in_array($index, [4, 5], true)) {
                        $sale = $dispensing->create($pharmacy, $actor, $id, ['request_key' => $this->key(42 + $index, $n + 1), 'verified' => 1,
                            'recipient_type' => 'self', 'items' => [['product_id' => $product->id, 'quantity' => $index === 4 ? 1 : 3]]]);
                        if (in_array($index, [4, 5], true)) {
                            $transaction = DB::table('sales')->where('id', $sale)->first();
                            app(PointOfSale::class)->pay($pharmacy, $actor, $sale, ['revision' => $transaction->revision, 'payment_method' => 'transfer', 'payment_reference' => 'DUMMY-PEMBAYARAN-OBAT', 'amount_received' => $transaction->total]);
                            $dispensing->handover($pharmacy, $actor, $sale);
                        }
                    }
                }
                if ($index === 5) {
                    $visits->complete($pharmacy, $actor, $visit, 2, null);
                }
            }
            $counts['visits']++;
        }
    }

    private function stocktakes(Pharmacy $pharmacy, int $actor, $products, array &$counts): void
    {
        $service = app(Stocktaking::class);
        foreach (['POSTED_MINUS', 'POSTED_PLUS', 'COUNTING', 'APPROVED', 'CANCELLED'] as $index => $status) {
            $key = $this->key(60, $index + 1);
            if (DB::table('stocktakes')->where('pharmacy_id', $pharmacy->id)->where('request_key', $key)->exists()) {
                continue;
            }
            $product = $products[$index < 2 ? 2 : $index + 1];
            $id = $service->start($pharmacy, $actor, ['request_key' => $key, 'product_id' => $product->id, 'notes' => 'DATA DUMMY OPERASIONAL - simulasi '.$status]);
            DB::table('stocktakes')->where('id', $id)->update(['number' => 'DEMO-OPS-SO-'.($index + 1)]);
            if ($status === 'CANCELLED') {
                $service->cancel($pharmacy, $actor, $id, 1, 'DUMMY - pembatalan sesi hitung simulasi.');
            } else {
                $lines = DB::table('stocktake_lines')->where('stocktake_id', $id)->orderBy('id')->get();
                $service->count($pharmacy, $actor, $id, ['revision' => 1, 'lines' => $lines->map(fn ($line, $i) => [
                    'id' => $line->id, 'physical_quantity' => Decimal::format(Decimal::scaled($line->system_quantity, 4) + ($i === 0 ? ($status === 'POSTED_MINUS' ? -20000 : ($status === 'POSTED_PLUS' ? 30000 : 0)) : 0), 4),
                    'physical_card_quantity' => $line->system_quantity, 'reason' => 'DUMMY - selisih penghitungan simulasi.'])->all()]);
                if ($status !== 'COUNTING') {
                    $service->approve($pharmacy, $actor, $id, 2);
                    if (str_starts_with($status, 'POSTED')) {
                        $service->post($pharmacy, $actor, $id, 3);
                    }
                }
            }
            $counts['stocktakes']++;
        }
    }
}
