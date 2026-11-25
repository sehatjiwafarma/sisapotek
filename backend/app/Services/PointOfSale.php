<?php

namespace App\Services;

use App\Models\Pharmacy;
use App\Models\Product;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PointOfSale
{
    public function __construct(private AccessManager $access) {}

    private function fail(string $message, string $field = 'items'): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    private function check(Pharmacy $pharmacy, int $actor, string $permission): void
    {
        $this->access->actor($pharmacy, $actor, $permission);
        if (Pharmacy::whereKey($pharmacy->id)->value('status') !== 'ACTIVE') {
            $this->fail('Apotek harus aktif untuk memproses penjualan.', 'pharmacy');
        }
    }

    private function unitPrice(Product $product, string $unitType): array
    {
        if (preg_match('/^s([0-9]+)$/', $unitType, $match)) {
            $unit = ($product->selling_units ?? [])[(int) $match[1]] ?? null;
            if (! $unit) {
                $this->fail('Satuan jual tidak tersedia. Muat ulang produk.');
            }

            return [Decimal::scaled($unit['price'], 2), Decimal::scaled($unit['conversion'], 4), $unit['unit']];
        }
        $conversion = $unitType === 'base' ? 10000 : Decimal::scaled($product->purchase_conversion, 4);
        $basePrice = Decimal::scaled($product->selling_price, 2);
        if ($conversion <= 0 || $conversion > 100000000 || $basePrice > 1000000000) {
            $this->fail('Harga atau konversi '.$product->sku.' melebihi batas POS. Konversi maksimal 10.000 dan harga Rp10.000.000 per satuan.');
        }
        $price = Decimal::rounded($basePrice * $conversion, 10000);
        if ($price > 1000000000) {
            $this->fail('Harga kemasan '.$product->sku.' melebihi Rp10.000.000. Gunakan satuan dasar.');
        }

        if ($unitType === 'purchase') {
            foreach ($product->selling_units ?? [] as $unit) {
                if ($unit['unit'] === $product->purchase_unit && Decimal::scaled($unit['conversion'], 4) === $conversion) {
                    $price = Decimal::scaled($unit['price'], 2);
                }
            }
        }

        return [$price, $conversion, $unitType === 'base' ? $product->base_unit : $product->purchase_unit];
    }

    public function save(Pharmacy $pharmacy, int $actor, array $data, ?string $id = null): string
    {
        return $this->access->locked($pharmacy, function () use ($pharmacy, $actor, $data, $id) {
            $this->check($pharmacy, $actor, 'sales.create');
            $sale = $id ? DB::table('sales')->where('pharmacy_id', $pharmacy->id)->where('id', $id)->lockForUpdate()->first() : null;
            if ($id) {
                abort_unless($sale, 404);
                if ($sale->kind !== 'GENERAL') {
                    $this->fail('Penebusan resep tidak dapat diubah melalui keranjang umum. Batalkan draft dan siapkan ulang dari resep.');
                }
                if ($sale->status !== 'DRAFT' || $sale->revision !== (int) $data['revision']) {
                    $this->fail('Draft sudah berubah atau difinalkan. Muat ulang sebelum mengedit.');
                }
            } else {
                $existing = DB::table('sales')->where('pharmacy_id', $pharmacy->id)->where('request_key', $data['request_key'])->first();
                if ($existing) {
                    abort_unless($existing->kind === 'GENERAL', 422);

                    return $existing->id;
                }
            }
            $id ??= (string) Str::uuid();
            $subtotal = 0;
            $lines = [];
            foreach ($data['items'] as $row) {
                $product = $pharmacy->products()->where('active', true)->where('requires_prescription', false)->find($row['product_id']);
                if (! $product) {
                    $this->fail('Pilih produk aktif tanpa kewajiban resep dari apotek ini.');
                }
                [$price, $conversion, $unit] = $this->unitPrice($product, $row['unit_type']);
                $quantity = Decimal::scaled($row['quantity'], 3);
                $baseQuantity = Decimal::rounded($quantity * $conversion, 1000);
                if ($baseQuantity <= 0) {
                    $this->fail('Jumlah dalam satuan dasar terlalu kecil untuk dicatat.');
                }
                $total = Decimal::rounded($quantity * $price, 1000);
                $subtotal += $total;
                $lines[] = ['id' => (string) Str::uuid(), 'sale_id' => $id, 'product_id' => $product->id,
                    'product_name' => $product->name, 'brand' => $product->brand, 'sku' => $product->sku,
                    'unit_type' => $row['unit_type'], 'unit' => $unit,
                    'base_unit' => $product->base_unit, 'conversion' => Decimal::format($conversion, 4),
                    'quantity' => Decimal::format($quantity, 3), 'base_quantity' => Decimal::format($baseQuantity, 4),
                    'unit_price' => Decimal::format($price), 'total' => Decimal::format($total)];
            }
            $pricing = app(PosPricing::class)->calculate($pharmacy, $subtotal, $data);
            if (Decimal::scaled($pricing['discount'], 2) > 0 || $pricing['discount_rule_id']) {
                $this->access->actor($pharmacy, $actor, 'sales.discount');
            }
            $values = ['customer_name' => $data['customer_name'] ?? null, 'notes' => $data['notes'] ?? null,
                'subtotal' => Decimal::format($subtotal), 'updated_at' => now()] + $pricing;
            if ($sale) {
                DB::table('sales')->where('id', $id)->update($values + ['revision' => $sale->revision + 1]);
                DB::table('sale_items')->where('sale_id', $id)->delete();
            } else {
                DB::table('sales')->insert($values + ['id' => $id, 'pharmacy_id' => $pharmacy->id, 'request_key' => $data['request_key'],
                    'number' => 'POS-'.now($pharmacy->timezone ?: 'Asia/Jakarta')->format('Ymd').'-'.strtoupper(Str::random(8)),
                    'kind' => 'GENERAL', 'status' => 'DRAFT', 'revision' => 1, 'created_by' => $actor, 'created_at' => now()]);
            }
            DB::table('sale_items')->insert($lines);
            $this->access->audit($pharmacy, $actor, $sale ? 'sale.updated' : 'sale.created', $id, ['total' => $values['total']]);

            return $id;
        });
    }

    public function pay(Pharmacy $pharmacy, int $actor, string $id, array $data, ?string $serviceBill = null): void
    {
        $this->access->locked($pharmacy, function () use ($pharmacy, $actor, $id, $data, $serviceBill) {
            $this->check($pharmacy, $actor, 'sales.pay');
            $sale = DB::table('sales')->where('pharmacy_id', $pharmacy->id)->where('id', $id)->lockForUpdate()->first();
            abort_unless($sale, 404);
            if ($sale->kind === 'PRESCRIPTION') {
                $this->access->actor($pharmacy, $actor, 'dispensing.view');
            }
            if ($sale->status === 'PAID') {
                return;
            }
            ServiceBilling::assertSaleUnlocked($pharmacy, $id, $serviceBill);
            if ($sale->status !== 'DRAFT' || $sale->revision !== (int) $data['revision']) {
                $this->fail('Draft sudah berubah atau dibatalkan. Muat ulang sebelum pembayaran.');
            }
            if ($sale->kind === 'PRESCRIPTION') {
                if (DB::table('sale_items')->where('sale_id', $sale->id)->whereNull('prescription_id')->exists()) {
                    $sale = app(PrescriptionDispensing::class)->applyCashierDetails($pharmacy, $actor, $sale, $data);
                }
                app(PrescriptionDispensing::class)->assertPayable($pharmacy, $actor, $sale);
            }
            if (Decimal::scaled($sale->discount, 2) > 0 || $sale->discount_rule_id) {
                $this->access->actor($pharmacy, $actor, 'sales.discount');
            }
            app(PosPricing::class)->verify($pharmacy, $sale);
            $total = Decimal::scaled($sale->total, 2);
            $received = Decimal::scaled($data['amount_received'], 2);
            if ($received < $total || ($data['payment_method'] !== 'cash' && $received !== $total)) {
                $this->fail('Tunai harus mencukupi total. Non-tunai harus tepat sebesar total.', 'amount_received');
            }
            $today = now($pharmacy->timezone ?: 'Asia/Jakarta')->toDateString();
            $items = DB::table('sale_items')->where('sale_id', $id)->orderBy('product_id')->get();
            if ($items->isEmpty()) {
                $this->fail('Penjualan harus memiliki minimal satu item.');
            }
            Stocktaking::assertAvailable($pharmacy, $items->pluck('product_id')->all());
            foreach ($items as $item) {
                $product = $pharmacy->products()->lockForUpdate()->find($item->product_id);
                if (! $product || ! $product->active || ($sale->kind !== 'PRESCRIPTION' && $product->requires_prescription)) {
                    $this->fail('Produk '.$item->sku.' sudah nonaktif atau memerlukan resep. Perbarui draft.');
                }
                [$price, $conversion, $unit] = $this->unitPrice($product, $item->unit_type);
                if ($price !== Decimal::scaled($item->unit_price, 2) || $conversion !== Decimal::scaled($item->conversion, 4)
                    || $product->base_unit !== $item->base_unit || $unit !== $item->unit) {
                    $this->fail('Harga atau satuan '.$item->sku.' berubah. Edit dan simpan ulang draft sebelum pembayaran.');
                }
                /** Pharmacy lock serializes receipts and sales; lock source rows in FEFO order as well. */
                $stockLots = app(StockLots::class);
                $lots = $stockLots->sources($pharmacy, $product->id, true);
                $needed = Decimal::scaled($item->base_quantity, 4);
                foreach ($lots as $lot) {
                    $available = $stockLots->remaining($pharmacy, $lot);
                    $take = min($needed, max(0, $available));
                    if ($take <= 0) {
                        continue;
                    }
                    DB::table('stock_movements')->insert(['id' => (string) Str::uuid(), 'pharmacy_id' => $pharmacy->id, 'product_id' => $product->id,
                        'sale_item_id' => $item->id, 'source_receipt_item_id' => $lot->purchase_receipt_item_id, 'source_movement_id' => $lot->id, 'purchase_receipt_item_id' => null,
                        'occurred_on' => $today, 'type' => 'SALE', 'quantity' => '-'.Decimal::format($take, 4), 'base_unit' => $lot->base_unit,
                        'batch_number' => $lot->batch_number, 'expires_on' => $lot->expires_on, 'location' => $lot->location, 'created_at' => now()]);
                    $needed -= $take;
                    if ($needed === 0) {
                        break;
                    }
                }
                if ($needed > 0) {
                    $this->fail('Stok layak jual '.$item->sku.' tidak mencukupi. Perbarui jumlah atau tunggu penerimaan barang.');
                }
            }
            DB::table('sales')->where('id', $id)->update(['status' => 'PAID', 'paid_at' => now(), 'sold_on' => $today, 'paid_by' => $actor,
                'payment_method' => $data['payment_method'], 'payment_reference' => $data['payment_reference'] ?? null,
                'amount_received' => Decimal::format($received), 'change_amount' => Decimal::format($received - $total),
                'pharmacy_name' => $pharmacy->name, 'pharmacy_address' => $pharmacy->address,
                'receipt_footer' => $pharmacy->settings()->value('receipt_footer'), 'revision' => $sale->revision + 1, 'updated_at' => now()]);
            Pharmacy::whereKey($pharmacy->id)->update(['has_transactions' => true]);
            if ($sale->kind === 'PRESCRIPTION') {
                foreach (PrescriptionDispensing::salePrescriptionIds($sale) as $rxId) app(PrescriptionDispensing::class)->updateStatus($pharmacy, $rxId);
            }
            CashManagement::record($pharmacy, $actor, 'SALE', $id, $sale->number, $data['payment_method'], $total);
            $this->access->audit($pharmacy, $actor, 'sale.paid', $id, ['number' => $sale->number, 'total' => $sale->total, 'payment_method' => $data['payment_method']]);
        });
    }

    public function cancel(Pharmacy $pharmacy, int $actor, string $id, string $reason): void
    {
        $this->access->locked($pharmacy, function () use ($pharmacy, $actor, $id, $reason) {
            $this->check($pharmacy, $actor, 'sales.cancel');
            $sale = DB::table('sales')->where('pharmacy_id', $pharmacy->id)->where('id', $id)->lockForUpdate()->first();
            abort_unless($sale, 404);
            if ($sale->kind === 'PRESCRIPTION') {
                $this->access->actor($pharmacy, $actor, 'dispensing.view');
            }
            ServiceBilling::assertSaleUnlocked($pharmacy, $id);
            if ($sale->status !== 'DRAFT') {
                $this->fail('Hanya draft yang dapat dibatalkan. Penjualan lunas memerlukan alur retur.');
            }
            DB::table('sales')->where('id', $id)->update(['status' => 'CANCELLED', 'cancellation_reason' => $reason, 'revision' => $sale->revision + 1, 'updated_at' => now()]);
            $this->access->audit($pharmacy, $actor, 'sale.cancelled', $id, ['reason' => $reason]);
        });
    }
}
