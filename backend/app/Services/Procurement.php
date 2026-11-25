<?php

namespace App\Services;

use App\Models\Pharmacy;
use App\Models\PurchaseOrder;
use App\Models\PurchaseReceipt;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class Procurement
{
    public function __construct(private AccessManager $access) {}

    private function check(Pharmacy $pharmacy, int $actor, string $permission, bool $operational = true): void
    {
        $this->access->actor($pharmacy, $actor, $permission);
        if ($operational && Pharmacy::findOrFail($pharmacy->id)->status !== 'ACTIVE') {
            throw ValidationException::withMessages(['pharmacy' => 'Apotek harus aktif untuk memproses transaksi pembelian.']);
        }
    }

    private function fail(string $message, string $field = 'items'): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    public function saveOrder(Pharmacy $pharmacy, int $actor, array $data, ?string $id = null): PurchaseOrder
    {
        return $this->access->locked($pharmacy, function () use ($pharmacy, $actor, $data, $id) {
            $this->check($pharmacy, $actor, $id ? 'purchases.update' : 'purchases.create');
            $order = $id ? $pharmacy->purchaseOrders()->lockForUpdate()->findOrFail($id) : $pharmacy->purchaseOrders()->make();
            if ($id && $order->status !== 'DRAFT') {
                $this->fail('Hanya PO draft yang dapat diubah.');
            }
            $supplier = $pharmacy->suppliers()->where('active', true)->find($data['supplier_id']);
            if (! $supplier) {
                $this->fail('Pilih supplier aktif dari apotek ini.', 'supplier_id');
            }
            $lines = [];
            $total = 0;
            foreach ($data['items'] as $index => $row) {
                $product = $pharmacy->products()->where('active', true)->find($row['product_id']);
                if (! $product) {
                    $this->fail('Produk baris '.($index + 1).' tidak aktif atau tidak tersedia di apotek ini.');
                }
                $conversion = $row['unit_type'] === 'base' ? '1.0000' : (string) $product->purchase_conversion;
                if (Decimal::scaled($conversion, 4) > 100000000) {
                    $this->fail('Konversi produk maksimal 10.000 satuan dasar per kemasan untuk pembelian.');
                }
                $quantity = Decimal::scaled($row['quantity'], 3);
                $price = Decimal::scaled($row['unit_price'], 2);
                $subtotal = Decimal::rounded($quantity * $price, 1000);
                $discount = Decimal::scaled($row['discount'] ?? 0, 2);
                $tax = Decimal::scaled($row['tax'] ?? 0, 2);
                if ($discount > $subtotal) {
                    $this->fail('Diskon baris '.($index + 1).' melebihi subtotal.');
                }
                $lineTotal = $subtotal - $discount + $tax;
                $total += $lineTotal;
                $lines[] = ['product_id' => $product->id, 'product_name' => $product->name, 'brand' => $product->brand, 'sku' => $product->sku,
                    'unit_type' => $row['unit_type'], 'unit' => $row['unit_type'] === 'base' ? $product->base_unit : $product->purchase_unit,
                    'base_unit' => $product->base_unit, 'conversion' => $conversion, 'quantity' => Decimal::format($quantity, 3),
                    'unit_price' => Decimal::format($price), 'discount' => Decimal::format($discount), 'tax' => Decimal::format($tax), 'total' => Decimal::format($lineTotal)];
            }
            $order->fill(collect($data)->except(['items'])->all());
            $order->supplier_name = $supplier->name;
            $order->payment_days = $data['payment_type'] === 'cash' ? 0 : $data['payment_days'];
            $order->total = Decimal::format($total);
            if (! $id) {
                $order->number = 'PO-'.now()->format('Ymd').'-'.strtoupper(Str::random(8));
                $order->status = 'DRAFT';
                $order->created_by = $actor;
            }
            $order->save();
            $order->items()->delete();
            $order->items()->createMany($lines);
            $this->access->audit($pharmacy, $actor, $id ? 'purchase.updated' : 'purchase.created', $order->id, ['number' => $order->number, 'total' => $order->total]);

            return $order;
        });
    }

    public function finalize(Pharmacy $pharmacy, int $actor, string $id): void
    {
        $this->access->locked($pharmacy, function () use ($pharmacy, $actor, $id) {
            $this->check($pharmacy, $actor, 'purchases.finalize');
            $order = $pharmacy->purchaseOrders()->with('items')->lockForUpdate()->findOrFail($id);
            if ($order->status !== 'DRAFT' || $order->items->isEmpty()) {
                $this->fail('PO harus berupa draft dan memiliki minimal satu item.');
            }
            if (! $pharmacy->suppliers()->where('active', true)->whereKey($order->supplier_id)->exists()) {
                $this->fail('Supplier sudah nonaktif. Perbarui draft sebelum konfirmasi.');
            }
            foreach ($order->items as $item) {
                $product = $pharmacy->products()->where('active', true)->find($item->product_id);
                if (! $product || $product->base_unit !== $item->base_unit || ($item->unit_type === 'purchase' && ($product->purchase_unit !== $item->unit || Decimal::scaled($product->purchase_conversion, 4) !== Decimal::scaled($item->conversion, 4)))) {
                    $this->fail('Produk atau konversi satuan berubah. Edit dan simpan ulang draft sebelum konfirmasi.');
                }
            }
            $order->update(['status' => 'ORDERED', 'finalized_at' => now()]);
            $this->access->audit($pharmacy, $actor, 'purchase.finalized', $order->id, ['number' => $order->number]);
        });
    }

    public function cancel(Pharmacy $pharmacy, int $actor, string $id, string $reason): void
    {
        $this->access->locked($pharmacy, function () use ($pharmacy, $actor, $id, $reason) {
            $this->check($pharmacy, $actor, 'purchases.cancel');
            $order = $pharmacy->purchaseOrders()->lockForUpdate()->findOrFail($id);
            if (! in_array($order->status, ['DRAFT', 'ORDERED'], true) || $order->receipts()->where('status', 'POSTED')->exists()) {
                $this->fail('PO yang sudah menerima barang atau sudah batal tidak dapat dibatalkan.');
            }
            $order->update(['status' => 'CANCELLED', 'cancellation_reason' => $reason]);
            $this->access->audit($pharmacy, $actor, 'purchase.cancelled', $order->id, ['reason' => $reason]);
        });
    }

    public function receive(Pharmacy $pharmacy, int $actor, string $id, array $data, ?string $receiptId = null): PurchaseReceipt
    {
        return $this->access->locked($pharmacy, function () use ($pharmacy, $actor, $id, $data, $receiptId) {
            $this->check($pharmacy, $actor, 'receipts.create');
            $order = $pharmacy->purchaseOrders()->with('items')->lockForUpdate()->findOrFail($id);
            if (! in_array($order->status, ['ORDERED', 'PARTIAL'], true)) {
                $this->fail('Penerimaan hanya dapat dibuat untuk PO dipesan atau diterima sebagian.');
            }
            if ($data['received_on'] < $order->ordered_on->format('Y-m-d')) {
                $this->fail('Tanggal penerimaan tidak boleh sebelum tanggal PO.', 'received_on');
            }
            $settings = $pharmacy->settings()->first();
            $lines = [];
            $subtotal = 0;
            $lineSubtotals = [];
            $submitted = collect($data['items'])->keyBy('order_item_id');
            foreach ($data['items'] as $row) {
                if (! $order->items->contains('id', $row['order_item_id'])) {
                    $this->fail('Item penerimaan tidak termasuk PO ini.');
                }
            }
            $partial = false;
            foreach ($order->items as $item) {
                $remaining = Decimal::scaled($item->quantity, 3) - Decimal::scaled($item->received_quantity, 3);
                $row = $submitted->get($item->id);
                $qty = Decimal::scaled($row['quantity'] ?? 0, 3);
                if ($qty > $remaining) {
                    $this->fail('Jumlah diterima untuk '.$item->product_name.' melebihi sisa pesanan.');
                }
                if ($qty < $remaining) {
                    $partial = true;
                }
                if (! $qty) {
                    continue;
                }
                if ($settings?->track_batch && empty($row['batch_number'])) {
                    $this->fail('Nomor batch wajib diisi untuk setiap barang yang diterima.');
                }
                if (($settings?->track_expiry && empty($row['expires_on'])) || (! empty($row['expires_on']) && $row['expires_on'] <= $data['received_on'])) {
                    $this->fail('Kedaluwarsa wajib setelah tanggal penerimaan; isi tanggal kedaluwarsa sesuai pengaturan apotek.');
                }
                $lineSubtotal = Decimal::rounded($qty * Decimal::scaled($row['unit_price'], 2), 1000);
                $lineSubtotals[] = $lineSubtotal;
                $subtotal += $lineSubtotal;
                $lines[] = ['purchase_order_item_id' => $item->id, 'quantity' => Decimal::format($qty, 3), 'unit_price' => $row['unit_price'],
                    'batch_number' => $row['batch_number'] ?? null, 'expires_on' => $row['expires_on'] ?? null, 'location' => $row['location'] ?? null];
            }
            if (! count($lines)) {
                $this->fail('Isi minimal satu jumlah barang yang diterima lebih dari 0.');
            }
            if ($partial && empty(trim($data['notes'] ?? ''))) {
                $this->fail('Isi catatan selisih untuk penerimaan sebagian.', 'notes');
            }
            $receipt = $receiptId ? $pharmacy->purchaseReceipts()->where('purchase_order_id', $order->id)->lockForUpdate()->findOrFail($receiptId) : $pharmacy->purchaseReceipts()->make();
            if ($receiptId && $receipt->status !== 'DRAFT') {
                $this->fail('Penerimaan yang sudah diposting tidak dapat diubah.');
            }
            $ppnType = $data['ppn_type'] ?? 'nominal';
            $ppnValue = Decimal::scaled($data['ppn_value'] ?? 0, 2);
            $scope = $data['ppn_scope'] ?? 'total';
            $tax = fn (int $amount) => intdiv($amount, 10000) * $ppnValue + Decimal::rounded(($amount % 10000) * $ppnValue, 10000);
            $ppnAmount = $ppnType === 'percentage' ? ($scope === 'product' ? array_sum(array_map($tax, $lineSubtotals)) : $tax($subtotal)) : $ppnValue;
            $receipt->fill(['ppn_scope' => $scope, 'ppn_type' => $ppnType, 'ppn_value' => Decimal::format($ppnValue), 'ppn_amount' => Decimal::format($ppnAmount),
                'subtotal' => Decimal::format($subtotal), 'total' => Decimal::format($subtotal + $ppnAmount), 'received_on' => $data['received_on'], 'invoice_number' => $data['invoice_number'] ?? null, 'notes' => $data['notes'] ?? null]);
            if (! $receiptId) {
                $receipt->purchase_order_id = $order->id;
                $receipt->number = 'RCV-'.now()->format('Ymd').'-'.strtoupper(Str::random(8));
                $receipt->created_by = $actor;
                $receipt->status = 'DRAFT';
            }
            $receipt->save();
            $receipt->items()->delete();
            $receipt->items()->createMany($lines);
            $this->access->audit($pharmacy, $actor, $receiptId ? 'receipt.updated' : 'receipt.created', $receipt->id, ['order' => $order->number]);

            return $receipt;
        });
    }

    public function post(Pharmacy $pharmacy, int $actor, string $id): void
    {
        $this->access->locked($pharmacy, function () use ($pharmacy, $actor, $id) {
            $this->check($pharmacy, $actor, 'receipts.post');
            $receipt = $pharmacy->purchaseReceipts()->with('items')->lockForUpdate()->findOrFail($id);
            if ($receipt->status !== 'DRAFT') {
                $this->fail('Penerimaan ini sudah diposting. Stok tidak ditambahkan ulang.');
            }
            $order = $pharmacy->purchaseOrders()->with('items')->lockForUpdate()->findOrFail($receipt->purchase_order_id);
            if (! in_array($order->status, ['ORDERED', 'PARTIAL'], true)) {
                $this->fail('Status PO tidak mengizinkan posting penerimaan.');
            }
            Stocktaking::assertAvailable($pharmacy, $order->items->whereIn('id', $receipt->items->pluck('purchase_order_item_id'))->pluck('product_id')->all());
            foreach ($receipt->items as $line) {
                $item = $order->items->firstWhere('id', $line->purchase_order_item_id);
                $qty = Decimal::scaled($line->quantity, 3);
                $received = Decimal::scaled($item->received_quantity, 3) + $qty;
                if ($received > Decimal::scaled($item->quantity, 3)) {
                    $this->fail('Sisa pesanan telah berubah. Penerimaan ini melebihi sisa dan tidak dapat diposting.');
                }
                $baseQty = Decimal::rounded($qty * Decimal::scaled($item->conversion, 4), 1000);
                if ($baseQty <= 0) {
                    $this->fail('Jumlah dalam satuan dasar terlalu kecil untuk dicatat (presisi 4 desimal).');
                }
                DB::table('stock_movements')->insert(['id' => (string) Str::uuid(), 'pharmacy_id' => $pharmacy->id, 'product_id' => $item->product_id,
                    'purchase_receipt_item_id' => $line->id, 'occurred_on' => $receipt->received_on->format('Y-m-d'), 'type' => 'PURCHASE_RECEIPT',
                    'quantity' => Decimal::format($baseQty, 4), 'base_unit' => $item->base_unit, 'batch_number' => $line->batch_number,
                    'expires_on' => $line->expires_on, 'location' => $line->location, 'created_at' => now()]);
                $item->update(['received_quantity' => Decimal::format($received, 3)]);
            }
            $complete = $order->items->every(fn ($item) => Decimal::scaled($item->received_quantity, 3) === Decimal::scaled($item->quantity, 3));
            $order->update(['status' => $complete ? 'RECEIVED' : 'PARTIAL']);
            $receipt->update(['status' => 'POSTED', 'posted_at' => now()]);
            // Compare actual posted prices after converting each PO unit to the current purchase unit.
            foreach ($order->items->whereIn('id', $receipt->items->pluck('purchase_order_item_id'))->pluck('product_id')->unique() as $productId) {
                $product = $pharmacy->products()->whereKey($productId)->lockForUpdate()->firstOrFail();
                $prices = DB::table('purchase_receipt_items as ri')
                    ->join('purchase_receipts as r', 'r.id', '=', 'ri.purchase_receipt_id')
                    ->join('purchase_order_items as oi', 'oi.id', '=', 'ri.purchase_order_item_id')
                    ->where('r.pharmacy_id', $pharmacy->id)->where('r.status', 'POSTED')->where('oi.product_id', $productId)
                    ->get(['ri.unit_price', 'oi.conversion']);
                $highest = $prices->max(fn ($row) => (float) $row->unit_price / (float) $row->conversion * (float) $product->purchase_conversion);
                $product->update(['purchase_price' => number_format($highest, 2, '.', '')]);
            }
            SupplierPayables::issue($pharmacy, $actor, $receipt, $order);
            Pharmacy::whereKey($pharmacy->id)->update(['has_transactions' => true]);
            $this->access->audit($pharmacy, $actor, 'receipt.posted', $receipt->id, ['order' => $order->number, 'status' => $order->status]);
        });
    }
}
