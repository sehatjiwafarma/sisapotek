<?php

namespace App\Services;

use App\Models\Pharmacy;
use App\Support\Decimal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SupplierReturns
{
    public function __construct(private AccessManager $access, private StockLots $lots) {}

    public static function lines(Pharmacy $pharmacy, string $receipt): Collection
    {
        return DB::table('purchase_receipt_items as line')->join('purchase_order_items as item', 'item.id', '=', 'line.purchase_order_item_id')
            ->join('purchase_receipts as receipt', 'receipt.id', '=', 'line.purchase_receipt_id')->where('receipt.pharmacy_id', $pharmacy->id)->where('receipt.id', $receipt)
            ->select('line.*', 'item.product_name', 'item.brand', 'item.sku', 'item.unit', 'item.product_id', 'item.conversion', 'item.base_unit')->orderBy('line.id')->get();
    }

    private function receipt(Pharmacy $pharmacy, string $id): object
    {
        $receipt = DB::table('purchase_receipts')->where('pharmacy_id', $pharmacy->id)->where('id', $id)->lockForUpdate()->first();
        abort_unless($receipt, 404);
        if ($receipt->status !== 'POSTED') {
            CashManagement::fail('Retur hanya untuk penerimaan yang sudah diposting.');
        }

        return $receipt;
    }

    public static function returned(Pharmacy $pharmacy, string $line): int
    {
        return Decimal::scaled(DB::table('supplier_return_items as line')->join('supplier_returns as header', 'header.id', '=', 'line.supplier_return_id')->where('header.pharmacy_id', $pharmacy->id)->where('header.status', 'POSTED')->where('line.purchase_receipt_item_id', $line)->sum('line.quantity'), 3);
    }

    private function quote(Pharmacy $pharmacy, object $receipt, array $rows): array
    {
        $lines = self::lines($pharmacy, $receipt->id);
        $weights = $lines->mapWithKeys(fn ($line) => [$line->id => Decimal::rounded(Decimal::scaled($line->quantity, 3) * Decimal::scaled($line->unit_price, 2), 1000)])->all();
        if (array_sum($weights) === 0) {
            $weights = $lines->mapWithKeys(fn ($line) => [$line->id => Decimal::scaled($line->quantity, 3)])->all();
        }
        $whole = array_sum($weights);
        $prefix = 0;
        $full = [];
        foreach ($weights as $key => $weight) {
            $full[$key] = SaleReturns::proportion(Decimal::scaled($receipt->total, 2), $prefix + $weight, $whole) - SaleReturns::proportion(Decimal::scaled($receipt->total, 2), $prefix, $whole);
            $prefix += $weight;
        }
        $result = [];
        $total = 0;
        foreach ($rows as $row) {
            $quantity = Decimal::scaled($row['quantity'], 3);
            if ($quantity === 0) {
                continue;
            }
            $line = $lines->firstWhere('id', $row['id']);
            abort_unless($line, 404);
            $prior = self::returned($pharmacy, $line->id);
            $received = Decimal::scaled($line->quantity, 3);
            if ($prior + $quantity > $received) {
                CashManagement::fail('Jumlah retur '.$line->sku.' melebihi sisa penerimaan.');
            }
            $source = DB::table('stock_movements')->where('pharmacy_id', $pharmacy->id)->where('purchase_receipt_item_id', $line->id)->where('type', 'PURCHASE_RECEIPT')->lockForUpdate()->first();
            abort_unless($source, 422);
            $base = SaleReturns::proportion(Decimal::scaled($source->quantity, 4), $prior + $quantity, $received) - SaleReturns::proportion(Decimal::scaled($source->quantity, 4), $prior, $received);
            if ($base <= 0 || $this->lots->remaining($pharmacy, $source) < $base) {
                CashManagement::fail('Stok batch '.$line->sku.' tidak mencukupi untuk retur.');
            }
            Stocktaking::assertAvailable($pharmacy, [$line->product_id]);
            $amount = SaleReturns::proportion($full[$line->id], $prior + $quantity, $received) - SaleReturns::proportion($full[$line->id], $prior, $received);
            $result[] = ['purchase_receipt_item_id' => $line->id, 'source_movement_id' => $source->id, 'product_name' => $line->product_name.' · '.$line->brand, 'unit' => $line->unit,
                'quantity' => Decimal::format($quantity, 3), 'base_quantity' => Decimal::format($base, 4), 'total' => Decimal::format($amount)];
            $total += $amount;
        }
        if (! $result) {
            CashManagement::fail('Isi minimal satu jumlah retur.');
        }

        return ['lines' => $result, 'total' => $total];
    }

    public function create(Pharmacy $pharmacy, int $actor, array $input): string
    {
        $data = Validator::make($input, ['request_key' => 'required|uuid', 'receipt_id' => 'required|uuid', 'reason' => 'required|string|max:2000', 'items' => 'required|array|min:1|max:100', 'items.*.id' => 'required|uuid|distinct', 'items.*.quantity' => 'required|numeric|min:0|max:10000|decimal:0,3'])->validate();

        return $this->access->locked($pharmacy, function () use ($pharmacy, $actor, $data) {
            CashManagement::check($pharmacy, $actor, 'supplier_returns.create');
            if ($id = DB::table('supplier_returns')->where('pharmacy_id', $pharmacy->id)->where('request_key', $data['request_key'])->value('id')) {
                return $id;
            }
            $receipt = $this->receipt($pharmacy, $data['receipt_id']);
            $quote = $this->quote($pharmacy, $receipt, $data['items']);
            $id = (string) Str::uuid();
            DB::table('supplier_returns')->insert(['id' => $id, 'pharmacy_id' => $pharmacy->id, 'purchase_receipt_id' => $receipt->id, 'request_key' => $data['request_key'], 'number' => CashManagement::number('RS', $pharmacy), 'reason' => $data['reason'], 'total' => Decimal::format($quote['total']), 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
            foreach ($quote['lines'] as $line) {
                DB::table('supplier_return_items')->insert($line + ['id' => (string) Str::uuid(), 'supplier_return_id' => $id]);
            }
            $this->access->audit($pharmacy, $actor, 'supplier_return.created', $id, ['receipt_id' => $receipt->id]);

            return $id;
        });
    }

    private function record(Pharmacy $pharmacy, string $id): object
    {
        $record = DB::table('supplier_returns')->where('pharmacy_id', $pharmacy->id)->where('id', $id)->lockForUpdate()->first();
        abort_unless($record, 404);

        return $record;
    }

    public function post(Pharmacy $pharmacy, int $actor, string $id, array $input): void
    {
        $data = Validator::make($input, ['revision' => 'required|integer|min:1', 'accepted' => 'required|accepted'])->validate();
        $this->access->locked($pharmacy, function () use ($pharmacy, $actor, $id, $data) {
            CashManagement::check($pharmacy, $actor, 'supplier_returns.post');
            $record = $this->record($pharmacy, $id);
            if ($record->status === 'POSTED') {
                return;
            }
            if ($record->status !== 'PENDING' || (int) $record->revision !== (int) $data['revision']) {
                CashManagement::fail('Retur berubah atau dibatalkan. Muat ulang halaman.');
            }
            $receipt = $this->receipt($pharmacy, $record->purchase_receipt_id);
            $items = DB::table('supplier_return_items')->where('supplier_return_id', $id)->orderBy('purchase_receipt_item_id')->get();
            $quote = $this->quote($pharmacy, $receipt, $items->map(fn ($line) => ['id' => $line->purchase_receipt_item_id, 'quantity' => $line->quantity])->all());
            if ($quote['total'] !== Decimal::scaled($record->total, 2)) {
                CashManagement::fail('Nilai retur berubah karena pembulatan retur lain. Batalkan dan buat pengajuan terbaru.');
            }
            $invoice = DB::table('supplier_invoices')->where('pharmacy_id', $pharmacy->id)->where('purchase_receipt_id', $receipt->id)->lockForUpdate()->first();
            $credit = 0;
            if ($invoice) {
                if (! $invoice->reconciled_at) {
                    CashManagement::fail('Konfirmasikan saldo awal tagihan sebelum posting retur.');
                }
                $balance = SupplierPayables::balances($pharmacy, $invoice);
                if ($balance['reserved'] > 0) {
                    CashManagement::fail('Selesaikan atau batalkan pembayaran yang disetujui pada tagihan ini sebelum retur.');
                }
                $credit = min($quote['total'], $balance['outstanding']);
                DB::table('supplier_invoices')->where('id', $invoice->id)->update(['credit_amount' => Decimal::format(Decimal::scaled($invoice->credit_amount, 2) + $credit), 'revision' => $invoice->revision + 1, 'updated_at' => now()]);
            }
            foreach ($quote['lines'] as $line) {
                $source = DB::table('stock_movements')->find($line['source_movement_id']);
                DB::table('stock_movements')->insert(['id' => (string) Str::uuid(), 'pharmacy_id' => $pharmacy->id, 'product_id' => $source->product_id, 'source_movement_id' => $source->id,
                    'source_receipt_item_id' => $source->purchase_receipt_item_id, 'type' => 'SUPPLIER_RETURN', 'quantity' => '-'.$line['base_quantity'], 'base_unit' => $source->base_unit,
                    'batch_number' => $source->batch_number, 'expires_on' => $source->expires_on, 'location' => $source->location, 'adjustment_key' => 'SR:'.$id.':'.substr(hash('sha256', $source->id), 0, 16),
                    'occurred_on' => now($pharmacy->timezone ?: 'Asia/Jakarta')->toDateString(), 'created_at' => now()]);
            }
            DB::table('supplier_returns')->where('id', $id)->update(['status' => 'POSTED', 'credit_amount' => Decimal::format($credit), 'refund_due' => Decimal::format($quote['total'] - $credit), 'posted_by' => $actor, 'posted_at' => now(), 'revision' => $record->revision + 1, 'updated_at' => now()]);
            $this->access->audit($pharmacy, $actor, 'supplier_return.posted', $id, ['credit_amount' => Decimal::format($credit), 'refund_due' => Decimal::format($quote['total'] - $credit)]);
        });
    }

    public function cancel(Pharmacy $pharmacy, int $actor, string $id, array $input): void
    {
        $data = Validator::make($input, ['revision' => 'required|integer|min:1', 'reason' => 'required|string|max:1000'])->validate();
        $this->access->locked($pharmacy, function () use ($pharmacy, $actor, $id, $data) {
            CashManagement::check($pharmacy, $actor, 'supplier_returns.cancel');
            $record = $this->record($pharmacy, $id);
            if ($record->status === 'CANCELLED') {
                return;
            }
            if ($record->status !== 'PENDING' || (int) $record->revision !== (int) $data['revision']) {
                CashManagement::fail('Hanya pengajuan terbaru yang dapat dibatalkan.');
            }
            DB::table('supplier_returns')->where('id', $id)->update(['status' => 'CANCELLED', 'cancellation_reason' => $data['reason'], 'revision' => $record->revision + 1, 'updated_at' => now()]);
            $this->access->audit($pharmacy, $actor, 'supplier_return.cancelled', $id);
        });
    }

    public function recover(Pharmacy $pharmacy, int $actor, string $id, array $input): void
    {
        $data = Validator::make($input, ['revision' => 'required|integer|min:1', 'confirmed' => 'required|accepted', 'method' => ['required', Rule::in(array_keys(SupplierPayables::methods()))], 'reference' => 'nullable|required_unless:method,cash|string|max:100'])->validate();
        $this->access->locked($pharmacy, function () use ($pharmacy, $actor, $id, $data) {
            CashManagement::check($pharmacy, $actor, 'supplier_returns.recover');
            $record = $this->record($pharmacy, $id);
            if ($record->recovered_at) {
                return;
            }
            if ($record->status !== 'POSTED' || (int) $record->revision !== (int) $data['revision'] || Decimal::scaled($record->refund_due, 2) <= 0) {
                CashManagement::fail('Tidak ada pengembalian dana yang dapat dicatat.');
            }
            CashManagement::record($pharmacy,$actor,'SUPPLIER_RECOVERY',$id,$record->number,$data['method'],Decimal::scaled($record->refund_due,2));
            DB::table('supplier_returns')->where('id',$id)->update(['recovered_at' => now(), 'recovered_by' => $actor, 'recovery_method' => $data['method'], 'recovery_reference' => $data['reference'] ?? null, 'revision' => $record->revision + 1, 'updated_at' => now()]);
            $this->access->audit($pharmacy,$actor,'supplier_return.recovered',$id);
        });
    }
}
