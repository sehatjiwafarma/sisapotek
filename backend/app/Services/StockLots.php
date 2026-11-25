<?php

namespace App\Services;

use App\Models\Pharmacy;
use App\Support\Decimal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class StockLots
{
    /** Sellable stock in base units (scaled to four decimals), without reserving it. */
    public function available(Pharmacy $pharmacy, array $products): array
    {
        $today = now($pharmacy->timezone ?: 'Asia/Jakarta')->toDateString();
        $used = DB::table('stock_movements as consumed')->where('consumed.pharmacy_id', $pharmacy->id)
            ->where(function ($query) {
                $query->whereColumn('consumed.source_movement_id', 'source.id')
                    ->orWhere(fn ($query) => $query->whereNull('consumed.source_movement_id')->whereColumn('consumed.source_receipt_item_id', 'source.purchase_receipt_item_id'));
            })->selectRaw('COALESCE(SUM(consumed.quantity),0)');
        $sources = DB::table('stock_movements as source')->where('source.pharmacy_id', $pharmacy->id)->whereIn('source.product_id', $products)
            ->whereIn('source.type', ['PURCHASE_RECEIPT', 'STOCKTAKE_IN'])->whereNull('source.source_movement_id')->where('source.quantity', '>', 0)
            ->where('source.occurred_on', '<=', $today)->where(fn ($query) => $query->whereNull('source.expires_on')->orWhere('source.expires_on', '>', $today))
            ->select('source.product_id', 'source.quantity')->selectSub($used, 'consumed')->get();
        $available = array_fill_keys($products, 0);
        foreach ($sources as $source) {
            $available[$source->product_id] += max(0, self::signed($source->quantity) + self::signed($source->consumed));
        }

        return $available;
    }

    public static function signed(mixed $value): int
    {
        $value = (string) $value;

        return (str_starts_with($value, '-') ? -1 : 1) * Decimal::scaled(ltrim($value, '-'), 4);
    }

    public static function format(int $value): string
    {
        return ($value < 0 ? '-' : '').Decimal::format(abs($value), 4);
    }

    /** Call only while holding the pharmacy row lock, before locking source rows. */
    public function sources(Pharmacy $pharmacy, string $product, bool $sale = false): Collection
    {
        $query = DB::table('stock_movements')->where('pharmacy_id', $pharmacy->id)->where('product_id', $product)
            ->whereIn('type', ['PURCHASE_RECEIPT', 'STOCKTAKE_IN'])->whereNull('source_movement_id')->where('quantity', '>', 0);
        if ($sale) {
            $today = now($pharmacy->timezone ?: 'Asia/Jakarta')->toDateString();
            $query->where('occurred_on', '<=', $today)->where(fn ($query) => $query->whereNull('expires_on')->orWhere('expires_on', '>', $today));
        }

        return $query->orderByRaw('CASE WHEN expires_on IS NULL THEN 1 ELSE 0 END')->orderBy('expires_on')->orderBy('created_at')->orderBy('id')->lockForUpdate()->get();
    }

    public function remaining(Pharmacy $pharmacy, object $source): int
    {
        $used = DB::table('stock_movements')->where('pharmacy_id', $pharmacy->id)->where(function ($query) use ($source) {
            $query->where('source_movement_id', $source->id);
            if ($source->purchase_receipt_item_id) {
                $query->orWhere(fn ($query) => $query->whereNull('source_movement_id')->where('source_receipt_item_id', $source->purchase_receipt_item_id));
            }
        })->sum('quantity');

        return self::signed($source->quantity) + self::signed($used);
    }
}
