<?php

namespace App\Services;

use App\Models\Pharmacy;
use App\Support\Decimal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SaleReturns
{
    public function __construct(private AccessManager $access) {}

    public static function labels(): array
    {
        return ['PENDING' => 'Menunggu persetujuan', 'APPROVED' => 'Siap refund', 'COMPLETED' => 'Refund selesai', 'CANCELLED' => 'Dibatalkan'];
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['return' => $message]);
    }

    private function check(Pharmacy $pharmacy, int $actor, string $permission): void
    {
        $this->access->actor($pharmacy, $actor, 'returns.view');
        $this->access->actor($pharmacy, $actor, $permission);
        abort_unless($pharmacy->fresh()->status === 'ACTIVE', 422, 'Apotek harus aktif.');
    }

    public static function sale(Pharmacy $pharmacy, string $id, ?int $actor = null): object
    {
        $sale = DB::table('sales')->where('pharmacy_id', $pharmacy->id)->where('id', $id)->first();
        abort_unless($sale, 404);
        if ($sale->kind === 'PRESCRIPTION') {
            abort_unless($actor, 403);
            app(AccessManager::class)->actor($pharmacy, $actor, 'dispensing.view');
            abort_unless($sale->handed_over_at && PrescriptionRecords::query($pharmacy)->where('id', $sale->prescription_id)->where(fn ($q) => $q->where('status', 'FINAL')->orWhereNotNull('ready_at'))->exists(), 422, 'Retur resep hanya tersedia setelah obat diserahkan.');
        }
        abort_unless(in_array($sale->kind, ['GENERAL', 'PRESCRIPTION'], true) && $sale->status === 'PAID', 422, 'Retur hanya tersedia untuk transaksi lunas.');

        return $sale;
    }

    /** Rounded amount * part / whole without overflowing fixed-point integer multiplication. */
    public static function proportion(int $amount, int $part, int $whole): int
    {
        if ($amount === 0 || $part === 0) {
            return 0;
        }
        if ($whole <= 0 || $part < 0 || $part > $whole || $amount < 0) {
            throw new \LogicException('Proporsi refund tidak valid.');
        }
        $quotient = 0;
        $remainder = 0;
        foreach (str_split(decbin($amount)) as $bit) {
            $quotient *= 2;
            $remainder = $remainder * 2 + ($bit === '1' ? $part : 0);
            $quotient += intdiv($remainder, $whole);
            $remainder %= $whole;
        }

        return $quotient + ($remainder * 2 >= $whole ? 1 : 0);
    }

    public static function allocations(Pharmacy $pharmacy, string $sale): Collection
    {
        return DB::table('stock_movements as movement')->join('sale_items as item', 'item.id', '=', 'movement.sale_item_id')->join('sales', 'sales.id', '=', 'item.sale_id')->leftJoin('prescriptions as rx', fn ($join) => $join->whereRaw('rx.id = COALESCE(item.prescription_id, sales.prescription_id)'))->leftJoin('visits as patient_visit', 'patient_visit.id', '=', 'rx.visit_id')
            ->where('movement.pharmacy_id', $pharmacy->id)->where('item.sale_id', $sale)->where('movement.type', 'SALE')
            ->select('movement.*', 'item.product_name', 'item.sku', 'rx.number as rx_number', 'patient_visit.patient_name')->orderBy('item.id')->orderBy('movement.id')->get();
    }

    public static function claims(Pharmacy $pharmacy, string $sale): array
    {
        $rows = DB::table('sale_return_items as line')->join('sale_returns as header', 'header.id', '=', 'line.sale_return_id')
            ->where('header.pharmacy_id', $pharmacy->id)->where('header.sale_id', $sale)->whereIn('header.status', ['APPROVED', 'COMPLETED'])
            ->select('line.*')->get();
        $movements = [];
        $items = [];
        foreach ($rows as $row) {
            $quantity = Decimal::scaled($row->quantity, 4);
            $movements[$row->sale_movement_id] = ($movements[$row->sale_movement_id] ?? 0) + $quantity;
            $items[$row->sale_item_id]['quantity'] = ($items[$row->sale_item_id]['quantity'] ?? 0) + $quantity;
            $items[$row->sale_item_id]['amount'] = ($items[$row->sale_item_id]['amount'] ?? 0) + Decimal::scaled($row->refund_amount, 2);
        }

        return compact('movements', 'items');
    }

    private function quote(Pharmacy $pharmacy, object $sale, array $input): array
    {
        usort($input, fn ($left, $right) => strcmp($left['sale_movement_id'], $right['sale_movement_id']));
        $allocations = self::allocations($pharmacy, $sale->id)->keyBy('id');
        $claims = self::claims($pharmacy, $sale->id);
        $budgets = [];
        $original = [];
        $subtotal = Decimal::scaled($sale->subtotal, 2);
        $total = Decimal::scaled($sale->total, 2);
        $cumulative = 0;
        $assigned = 0;
        foreach (DB::table('sale_items')->where('sale_id', $sale->id)->orderBy('id')->get() as $item) {
            $cumulative += Decimal::scaled($item->total, 2);
            $share = $subtotal > 0 ? self::proportion($total, $cumulative, $subtotal) : 0;
            $budgets[$item->id] = $share - $assigned;
            $assigned = $share;
            $original[$item->id] = Decimal::scaled($item->base_quantity, 4);
        }
        $lines = [];
        $refund = 0;
        foreach ($input as $row) {
            $movement = $allocations->get($row['sale_movement_id']);
            if (! $movement) {
                $this->fail('Batch retur harus berasal dari transaksi penjualan ini.');
            }
            $quantity = Decimal::scaled($row['quantity'], 4);
            $sold = abs(StockLots::signed($movement->quantity));
            $remaining = $sold - ($claims['movements'][$movement->id] ?? 0);
            if ($quantity > $remaining) {
                $this->fail('Jumlah retur '.$movement->product_name.' melebihi sisa batch. Retur yang disetujui atau selesai ikut dihitung.');
            }
            if ($quantity === 0) {
                continue;
            }
            $item = $movement->sale_item_id;
            $remainingAmount = $budgets[$item] - ($claims['items'][$item]['amount'] ?? 0);
            $remainingQuantity = $original[$item] - ($claims['items'][$item]['quantity'] ?? 0);
            $amount = self::proportion($remainingAmount, $quantity, $remainingQuantity);
            $claims['movements'][$movement->id] = ($claims['movements'][$movement->id] ?? 0) + $quantity;
            $claims['items'][$item]['quantity'] = ($claims['items'][$item]['quantity'] ?? 0) + $quantity;
            $claims['items'][$item]['amount'] = ($claims['items'][$item]['amount'] ?? 0) + $amount;
            $lines[] = ['sale_item_id' => $item, 'sale_movement_id' => $movement->id, 'quantity' => Decimal::format($quantity, 4), 'disposition' => $row['disposition'], 'refund_amount' => Decimal::format($amount)];
            $refund += $amount;
        }
        if (! $lines) {
            $this->fail('Isi jumlah retur lebih dari nol untuk minimal satu batch.');
        }

        return ['lines' => $lines, 'amount' => Decimal::format($refund)];
    }

    public function create(Pharmacy $pharmacy, int $actor, string $saleId, array $input): string
    {
        $data = Validator::make($input, [
            'request_key' => 'required|uuid', 'reason' => 'required|string|max:1000', 'condition_checked' => 'required|accepted',
            'items' => 'required|array|min:1|max:300', 'items.*' => 'array:sale_movement_id,quantity,disposition',
            'items.*.sale_movement_id' => 'required|uuid|distinct', 'items.*.quantity' => 'required|numeric|min:0|max:100000000|decimal:0,4',
            'items.*.disposition' => ['required', Rule::in(['RESTOCK', 'QUARANTINE'])],
            'returner_type' => ['nullable', Rule::in(['self', 'representative'])],
            'returner_name' => 'nullable|string|max:150', 'returner_relationship' => 'nullable|string|max:100', 'returner_phone' => 'nullable|string|max:30',
        ])->validate();

        return $this->access->locked($pharmacy, function () use ($pharmacy, $actor, $saleId, $data) {
            $this->check($pharmacy, $actor, 'returns.create');
            $sale = self::sale($pharmacy, $saleId, $actor);
            $existing = DB::table('sale_returns')->where('pharmacy_id', $pharmacy->id)->where('request_key', $data['request_key'])->first();
            if ($existing) {
                abort_unless($existing->sale_id === $saleId, 422);

                return $existing->id;
            }
            $quote = $this->quote($pharmacy, $sale, $data['items']);
            $this->assertRestock($pharmacy, $quote['lines']);
            $id = (string) Str::uuid();
            $private = [];
            if ($sale->kind === 'PRESCRIPTION') {
                $returner = Validator::make($data, [
                    'returner_type' => ['required', Rule::in(['self', 'representative'])],
                    'returner_name' => 'nullable|required_if:returner_type,representative|string|max:150',
                    'returner_relationship' => 'nullable|required_if:returner_type,representative|string|max:100',
                    'returner_phone' => 'nullable|required_if:returner_type,representative|string|max:30',
                ])->validate();
                if (count(PrescriptionDispensing::salePrescriptionIds($sale)) > 1 && $returner['returner_type'] === 'self') $this->fail('Untuk transaksi gabungan, catat identitas pengembali sebagai perwakilan.');
                $prescription = PrescriptionRecords::query($pharmacy)->where('id', $sale->prescription_id)->first();
                $patient = ExaminationRecords::payload($prescription)['patient'];
                $identity = $returner['returner_type'] === 'self'
                    ? ['type' => 'self', 'name' => $patient['name'], 'relationship' => null, 'phone' => null]
                    : ['type' => 'representative', 'name' => $returner['returner_name'], 'relationship' => $returner['returner_relationship'], 'phone' => $returner['returner_phone']];
                $private = ['returner_encrypted' => Crypt::encryptString(json_encode($identity, JSON_THROW_ON_ERROR)), 'return_reason_encrypted' => Crypt::encryptString($data['reason'])];
            }
            DB::table('sale_returns')->insert(['id' => $id, 'pharmacy_id' => $pharmacy->id, 'sale_id' => $saleId, 'request_key' => $data['request_key'],
                'number' => 'RET-'.now($pharmacy->timezone ?: 'Asia/Jakarta')->format('Ymd').'-'.strtoupper(Str::random(8)),
                'status' => 'PENDING', 'revision' => 1, 'reason' => $sale->kind === 'PRESCRIPTION' ? 'Retur penebusan resep' : $data['reason'], 'refund_amount' => $quote['amount'], 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()] + $private);
            foreach ($quote['lines'] as $line) {
                DB::table('sale_return_items')->insert($line + ['id' => (string) Str::uuid(), 'sale_return_id' => $id]);
            }
            $this->access->audit($pharmacy, $actor, 'sale_return.created', $id, ['sale_id' => $saleId, 'refund_amount' => $quote['amount']]);

            return $id;
        });
    }

    private function record(Pharmacy $pharmacy, string $id): object
    {
        $record = DB::table('sale_returns')->where('pharmacy_id', $pharmacy->id)->where('id', $id)->lockForUpdate()->first();
        abort_unless($record, 404);

        return $record;
    }

    private function assertRestock(Pharmacy $pharmacy, array $lines): void
    {
        $today = now($pharmacy->timezone ?: 'Asia/Jakarta')->toDateString();
        foreach ($lines as $line) {
            if ($line['disposition'] !== 'RESTOCK') {
                continue;
            }
            $movement = DB::table('stock_movements')->where('pharmacy_id', $pharmacy->id)->where('id', $line['sale_movement_id'])->first();
            $product = $pharmacy->products()->find($movement->product_id);
            if (! $product || $product->base_unit !== $movement->base_unit || ($movement->expires_on && $movement->expires_on <= $today)) {
                $this->fail('Batch kedaluwarsa atau satuan berubah tidak boleh masuk stok jual. Ajukan ulang sebagai karantina.');
            }
        }
    }

    public function approve(Pharmacy $pharmacy, int $actor, string $id, int $revision): void
    {
        $this->access->locked($pharmacy, function () use ($pharmacy, $actor, $id, $revision) {
            $this->check($pharmacy, $actor, 'returns.approve');
            $record = $this->record($pharmacy, $id);
            $sale = self::sale($pharmacy, $record->sale_id, $actor);
            if (in_array($record->status, ['APPROVED', 'COMPLETED'], true)) {
                return;
            }
            if ($record->status !== 'PENDING' || (int) $record->revision !== $revision) {
                $this->fail('Pengajuan berubah atau dibatalkan. Muat ulang halaman.');
            }
            $lines = DB::table('sale_return_items')->where('sale_return_id', $id)->orderBy('sale_movement_id')->get();
            $quote = $this->quote($pharmacy, $sale, $lines->map(fn ($line) => (array) $line)->all());
            $this->assertRestock($pharmacy, $quote['lines']);
            $savedAmounts = $lines->pluck('refund_amount', 'sale_movement_id');
            if (Decimal::scaled($quote['amount'], 2) !== Decimal::scaled($record->refund_amount, 2)
                || collect($quote['lines'])->contains(fn ($line) => Decimal::scaled($line['refund_amount'], 2) !== Decimal::scaled($savedAmounts[$line['sale_movement_id']], 2))) {
                $this->fail('Sisa nilai refund berubah karena retur lain. Batalkan pengajuan dan ajukan ulang untuk meninjau nilai terbaru.');
            }
            DB::table('sale_returns')->where('id', $id)->update(['status' => 'APPROVED', 'approved_by' => $actor, 'approved_at' => now(), 'revision' => $revision + 1, 'updated_at' => now()]);
            $this->access->audit($pharmacy, $actor, 'sale_return.approved', $id);
        });
    }

    public function refund(Pharmacy $pharmacy, int $actor, string $id, array $input): void
    {
        $data = Validator::make($input, ['revision' => 'required|integer|min:1', 'refund_method' => ['required', Rule::in(['cash', 'transfer', 'qris', 'debit'])],
            'refund_reference' => 'nullable|required_unless:refund_method,cash|string|max:100', 'refund_confirmed' => 'required|accepted'])->validate();
        $this->access->locked($pharmacy, function () use ($pharmacy, $actor, $id, $data) {
            $this->check($pharmacy, $actor, 'returns.refund');
            $record = $this->record($pharmacy, $id);
            self::sale($pharmacy, $record->sale_id, $actor);
            if ($record->status === 'COMPLETED') {
                return;
            }
            if ($record->status !== 'APPROVED' || (int) $record->revision !== (int) $data['revision']) {
                $this->fail('Setujui retur terlebih dahulu atau muat ulang pengajuan terbaru.');
            }
            $lines = DB::table('sale_return_items')->where('sale_return_id', $id)->orderBy('id')->get();
            $this->assertRestock($pharmacy, $lines->map(fn ($line) => (array) $line)->all());
            $movements = self::allocations($pharmacy, $record->sale_id)->keyBy('id');
            $restockProducts = $lines->where('disposition', 'RESTOCK')->map(fn ($line) => $movements[$line->sale_movement_id]->product_id)->all();
            Stocktaking::assertAvailable($pharmacy, $restockProducts);
            foreach ($lines as $line) {
                if ($line->disposition !== 'RESTOCK') {
                    continue;
                }
                $sold = $movements[$line->sale_movement_id];
                $source = $sold->source_movement_id;
                if (! $source && $sold->source_receipt_item_id) {
                    $source = DB::table('stock_movements')->where('pharmacy_id', $pharmacy->id)->where('product_id', $sold->product_id)
                        ->where('type', 'PURCHASE_RECEIPT')->where('purchase_receipt_item_id', $sold->source_receipt_item_id)->value('id');
                }
                if (! $source) {
                    $this->fail('Sumber batch asal tidak ditemukan. Tidak ada refund atau stok yang diposting.');
                }
                DB::table('stock_movements')->insert(['id' => (string) Str::uuid(), 'pharmacy_id' => $pharmacy->id, 'product_id' => $sold->product_id,
                    'sale_return_item_id' => $line->id, 'source_movement_id' => $source, 'type' => 'SALE_RETURN', 'quantity' => $line->quantity,
                    'base_unit' => $sold->base_unit, 'batch_number' => $sold->batch_number, 'expires_on' => $sold->expires_on, 'location' => $sold->location,
                    'occurred_on' => now($pharmacy->timezone ?: 'Asia/Jakarta')->toDateString(), 'created_at' => now()]);
            }
            DB::table('sale_returns')->where('id', $id)->update(['status' => 'COMPLETED', 'refunded_by' => $actor, 'refunded_at' => now(),
                'refund_method' => $data['refund_method'], 'refund_reference' => $data['refund_reference'] ?? null, 'revision' => $record->revision + 1, 'updated_at' => now()]);
            Pharmacy::whereKey($pharmacy->id)->update(['has_transactions' => true]);
            CashManagement::record($pharmacy, $actor, 'SALE_REFUND', $id, $record->number, $data['refund_method'], -Decimal::scaled($record->refund_amount, 2));
            $this->access->audit($pharmacy, $actor, 'sale_return.refunded', $id, ['refund_amount' => $record->refund_amount, 'refund_method' => $data['refund_method']]);
        });
    }

    public function cancel(Pharmacy $pharmacy, int $actor, string $id, int $revision, string $reason): void
    {
        $data = Validator::make(['reason' => $reason], ['reason' => 'required|string|max:1000'])->validate();
        $this->access->locked($pharmacy, function () use ($pharmacy, $actor, $id, $revision, $data) {
            $this->check($pharmacy, $actor, 'returns.cancel');
            $record = $this->record($pharmacy, $id);
            $sale = self::sale($pharmacy, $record->sale_id, $actor);
            if (! in_array($record->status, ['PENDING', 'APPROVED'], true) || (int) $record->revision !== $revision) {
                $this->fail('Retur sudah berubah atau refund selesai; pembatalan ditolak.');
            }
            DB::table('sale_returns')->where('id', $id)->update(['status' => 'CANCELLED', 'cancellation_reason' => $sale->kind === 'PRESCRIPTION' ? 'Pembatalan retur resep' : $data['reason'], 'cancellation_encrypted' => $sale->kind === 'PRESCRIPTION' ? Crypt::encryptString($data['reason']) : null, 'cancelled_by' => $actor, 'cancelled_at' => now(), 'revision' => $revision + 1, 'updated_at' => now()]);
            $this->access->audit($pharmacy, $actor, 'sale_return.cancelled', $id);
        });
    }

    public function restoreEntitlement(Pharmacy $pharmacy, int $actor, string $id, int $revision, string $reason): void
    {
        $data = Validator::make(['reason' => $reason], ['reason' => 'required|string|max:1000'])->validate();
        $this->access->locked($pharmacy, function () use ($pharmacy, $actor, $id, $revision, $data) {
            $this->check($pharmacy, $actor, 'returns.restore_entitlement');
            $record = $this->record($pharmacy, $id);
            $sale = self::sale($pharmacy, $record->sale_id, $actor);
            if ($sale->kind !== 'PRESCRIPTION' || $record->status !== 'COMPLETED') {
                $this->fail('Pemulihan hak tebus hanya untuk retur resep yang refund-nya sudah selesai.');
            }
            if ($record->entitlement_restored_at) {
                return;
            }
            if ((int) $record->revision !== $revision) {
                $this->fail('Data retur berubah. Muat ulang sebelum menyetujui pemulihan hak tebus.');
            }
            foreach (PrescriptionDispensing::salePrescriptionIds($sale) as $rxId) PrescriptionRecords::query($pharmacy)->where('id', $rxId)->lockForUpdate()->first();
            DB::table('sale_returns')->where('id', $id)->update(['entitlement_restored_by' => $actor, 'entitlement_restored_at' => now(),
                'entitlement_reason_encrypted' => Crypt::encryptString($data['reason']), 'revision' => $revision + 1, 'updated_at' => now()]);
            foreach (PrescriptionDispensing::salePrescriptionIds($sale) as $rxId) app(PrescriptionDispensing::class)->updateStatus($pharmacy, $rxId);
            $this->access->audit($pharmacy, $actor, 'sale_return.entitlement_restored', $id, ['prescription_id' => $sale->prescription_id]);
        });
    }
}
