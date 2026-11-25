<?php

namespace App\Console\Commands;

use App\Models\Pharmacy;
use App\Services\AccessManager;
use App\Services\Procurement;
use Illuminate\Console\Command;

class SeedDemoProcurement extends Command
{
    protected $signature = 'apotek:demo-procurement {pharmacy : Kode apotek tujuan}';

    protected $description = 'Isi supplier, PO, dan penerimaan simulasi tanpa mengubah dokumen yang sudah ada.';

    public function handle(AccessManager $manager, Procurement $service): int
    {
        $pharmacy = Pharmacy::where('code', $this->argument('pharmacy'))->first();
        if (! $pharmacy || $pharmacy->status !== 'ACTIVE') {
            $this->error('Pilih apotek aktif yang tersedia.');

            return self::FAILURE;
        }
        $owner = $pharmacy->memberships()->where('active', true)->whereHas('roles', fn ($q) => $q->where('is_owner', true))->first();
        $products = $pharmacy->products()->where('active', true)->where('sku', 'like', 'DEMO-%')->orderBy('sku')->limit(3)->get();
        if (! $owner || $products->count() < 3) {
            $this->error('Diperlukan Owner aktif dan minimal 3 produk DEMO aktif.');

            return self::FAILURE;
        }
        $today = now($pharmacy->settings?->timezone ?? 'Asia/Jakarta')->startOfDay();
        $result = $manager->locked($pharmacy, function () use ($pharmacy, $manager, $service, $owner, $products, $today) {
            $suppliers = collect();
            $createdSuppliers = 0;
            foreach (['Distribusi Sehat', 'Mitra Farmasi', 'Medika Nusantara', 'Sarana Alkes', 'Pemasok Arsip'] as $index => $name) {
                $supplier = $pharmacy->suppliers()->firstOrCreate(['code' => 'DEMO-SUP-'.($index + 1)], [
                    'name' => $name.' (Dummy)', 'contact_name' => 'PIC Simulasi '.($index + 1), 'phone' => null,
                    'email' => 'supplier'.($index + 1).'@example.invalid',
                    'address' => 'ALAMAT DUMMY — Jalan Contoh No. '.($index + 1).', bukan alamat operasional.',
                    'payment_days' => $index % 2 ? 0 : 30, 'active' => $index !== 4,
                ]);
                if ($supplier->wasRecentlyCreated) {
                    $createdSuppliers++;
                    $manager->audit($pharmacy, null, 'supplier.demo_created', $supplier->id, ['demo' => true, 'code' => $supplier->code]);
                }
                $suppliers->push($supplier);
            }
            $createdOrders = 0;
            foreach (['DRAFT', 'ORDERED', 'PARTIAL', 'RECEIVED', 'CANCELLED'] as $index => $status) {
                $number = 'DEMO-PO-'.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT);
                if ($pharmacy->purchaseOrders()->where('number', $number)->exists()) {
                    continue;
                }
                $supplier = $suppliers[$index % 4];
                $order = $service->saveOrder($pharmacy, $owner->user_id, [
                    'supplier_id' => $supplier->id, 'ordered_on' => $today->copy()->subDays(5 - $index)->toDateString(),
                    'expected_on' => $today->copy()->addDays(2)->toDateString(), 'payment_type' => $supplier->payment_days ? 'credit' : 'cash',
                    'payment_days' => $supplier->payment_days, 'notes' => 'DATA DUMMY untuk uji alur. Supplier, harga, pajak, dan stok bersifat simulasi. Dokumen tidak dikirim ke pihak luar.',
                    'items' => $products->map(fn ($product, $i) => ['product_id' => $product->id, 'unit_type' => 'purchase',
                        'quantity' => $i + 2, 'unit_price' => $product->purchase_price ?? 10000, 'discount' => 0, 'tax' => 0])->all(),
                ]);
                $order->update(['number' => $number]);
                if ($status !== 'DRAFT') {
                    $service->finalize($pharmacy, $owner->user_id, $order->id);
                }
                if ($status === 'CANCELLED') {
                    $service->cancel($pharmacy, $owner->user_id, $order->id, 'PEMBATALAN DUMMY — simulasi barang tidak tersedia dari supplier.');
                }
                if (in_array($status, ['ORDERED', 'PARTIAL', 'RECEIVED'], true)) {
                    $receipt = $service->receive($pharmacy, $owner->user_id, $order->id, [
                        'received_on' => $today->toDateString(), 'invoice_number' => 'DEMO-INV-'.($index + 1),
                        'notes' => $status === 'RECEIVED' ? 'PENERIMAAN DUMMY — barang diterima lengkap untuk simulasi.' : 'PENERIMAAN DUMMY — sebagian barang menyusul pada pengiriman berikutnya.',
                        'items' => $order->items()->get()->map(fn ($item, $i) => [
                            'order_item_id' => $item->id, 'quantity' => $status === 'RECEIVED' ? $item->quantity : 1,
                            'unit_price' => $item->unit_price, 'batch_number' => 'DEMO-BATCH-'.($index + 1).'-'.($i + 1),
                            'expires_on' => $today->copy()->addYears(2)->toDateString(), 'location' => 'Rak Simulasi '.($i + 1),
                        ])->all(),
                    ]);
                    $receipt->update(['number' => 'DEMO-RCV-'.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT)]);
                    if ($status !== 'ORDERED') {
                        $service->post($pharmacy, $owner->user_id, $receipt->id);
                    }
                }
                $manager->audit($pharmacy, null, 'purchase.demo_seeded', $order->id, ['demo' => true, 'number' => $number, 'actor_context' => 'Perintah pengisian simulasi; validasi layanan menggunakan Owner aktif.']);
                $createdOrders++;
            }

            return [$createdSuppliers, $createdOrders];
        });
        $this->info($result[0].' supplier dan '.$result[1].' PO dummy ditambahkan ke '.$pharmacy->name.' ('.$pharmacy->code.').');
        $this->info('Termasuk penerimaan draft dan posting stok simulasi. Dokumen yang sudah ada tidak diubah.');

        return self::SUCCESS;
    }
}
