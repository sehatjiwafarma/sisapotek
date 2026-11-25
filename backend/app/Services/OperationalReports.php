<?php

namespace App\Services;

use App\Models\Pharmacy;
use App\Support\Decimal;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class OperationalReports
{
    public const TYPES = ['sales' => 'Penjualan & layanan', 'purchases' => 'Pembelian & retur supplier', 'inventory' => 'Persediaan saat ini', 'finance' => 'Mutasi dana'];

    private static function localDate(Builder $query, string $column, Pharmacy $pharmacy): Builder
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            return $query->selectRaw('CAST('.$column.' AT TIME ZONE ? AS DATE) as date', [$pharmacy->timezone ?: 'Asia/Jakarta']);
        }

        return $query->selectRaw('DATE('.$column.', ?) as date', [(string) now($pharmacy->timezone ?: 'Asia/Jakarta')->offset.' seconds']);
    }

    public static function query(Pharmacy $pharmacy, string $kind, string $from, string $to): Builder
    {
        if ($kind === 'sales') {
            $sales = DB::table('sales')->where('pharmacy_id', $pharmacy->id)->where('status', 'PAID')->whereBetween('sold_on', [$from, $to])
                ->select('id', 'number', 'sold_on as date', 'kind as category', 'total as amount')->selectRaw("'PAID' as status");
            $services = DB::table('service_bills')->where('pharmacy_id', $pharmacy->id)->where('status', 'PAID')->whereBetween('paid_on', [$from, $to])
                ->select('id', 'number', 'paid_on as date')->selectRaw("'SERVICE' as category, services_total as amount, 'PAID' as status");
            $start = Carbon::parse($from, $pharmacy->timezone ?: 'Asia/Jakarta')->startOfDay()->utc();
            $end = Carbon::parse($to, $pharmacy->timezone ?: 'Asia/Jakarta')->endOfDay()->utc();
            $refunds = DB::table('sale_returns')->where('pharmacy_id', $pharmacy->id)->where('status', 'COMPLETED')->whereBetween('refunded_at', [$start, $end])->select('id', 'number');
            self::localDate($refunds, 'refunded_at', $pharmacy)->selectRaw("'REFUND' as category, -refund_amount as amount, 'COMPLETED' as status");

            return DB::query()->fromSub($sales->unionAll($services)->unionAll($refunds), 'report');
        }
        if ($kind === 'purchases') {
            $receipts = DB::table('purchase_receipts')->where('pharmacy_id', $pharmacy->id)->where('status', 'POSTED')->whereBetween('received_on', [$from, $to])
                ->select('id', 'number', 'received_on as date')->selectRaw("'Penerimaan' as category, total as amount, 'POSTED' as status");
            $start = Carbon::parse($from, $pharmacy->timezone ?: 'Asia/Jakarta')->startOfDay()->utc();
            $end = Carbon::parse($to, $pharmacy->timezone ?: 'Asia/Jakarta')->endOfDay()->utc();
            $returns = DB::table('supplier_returns')->where('pharmacy_id', $pharmacy->id)->where('status', 'POSTED')->whereBetween('posted_at', [$start, $end])->select('id', 'number');
            self::localDate($returns, 'posted_at', $pharmacy)->selectRaw("'Retur supplier' as category, -total as amount, 'POSTED' as status");

            return DB::query()->fromSub($receipts->unionAll($returns), 'report');
        }
        if ($kind === 'finance') {
            return DB::query()->fromSub(DB::table('cash_entries')->where('pharmacy_id', $pharmacy->id)->whereBetween('occurred_on', [$from, $to])
                ->select('id', 'number', 'occurred_on as date', 'source_type as category', 'amount', 'method as status'), 'report');
        }
        $today = now($pharmacy->timezone ?: 'Asia/Jakarta')->toDateString();
        $until = now($pharmacy->timezone ?: 'Asia/Jakarta')->addDays($pharmacy->settings?->expiry_reminder_days ?? 90)->toDateString();
        $stock = DB::table('stock_movements')->where('pharmacy_id', $pharmacy->id)->select('product_id')->selectRaw('SUM(quantity) as physical')
            ->selectRaw('SUM(CASE WHEN expires_on IS NULL OR expires_on > ? THEN quantity ELSE 0 END) as available', [$today])
            ->selectRaw('SUM(CASE WHEN expires_on > ? AND expires_on <= ? THEN quantity ELSE 0 END) as near_expiry', [$today, $until])
            ->selectRaw('SUM(CASE WHEN expires_on <= ? THEN quantity ELSE 0 END) as expired', [$today])->groupBy('product_id');
        $products = DB::table('products as product')->where('product.pharmacy_id', $pharmacy->id)->leftJoinSub($stock, 'stock', 'stock.product_id', '=', 'product.id')
            ->select('product.id', 'product.sku as number', 'product.name as category', 'product.base_unit as status', 'product.minimum_stock')
            ->selectRaw('COALESCE(physical,0) as amount, COALESCE(available,0) as available, COALESCE(near_expiry,0) as near_expiry, COALESCE(expired,0) as expired');

        return DB::query()->fromSub($products, 'report');
    }

    /** Estimated medicine margin, excluding purchase VAT, services and overhead. Unknown lot costs suppress the result. */
    public static function margin(Pharmacy $pharmacy, Builder $report): array
    {
        $sales = (clone $report)->whereIn('category', ['GENERAL', 'PRESCRIPTION'])->select('id');
        $refunds = (clone $report)->where('category', 'REFUND')->select('id');
        $revenue = 0;
        $cost = 0;
        $unknown = 0;
        foreach (DB::table('sales')->where('pharmacy_id', $pharmacy->id)->whereIn('id', $sales)->get(['total', 'tax_amount']) as $sale) {
            $revenue += Decimal::scaled($sale->total, 2) - Decimal::scaled($sale->tax_amount, 2);
        }
        $refundRows = DB::table('sale_returns as r')->join('sales as s', 's.id', '=', 'r.sale_id')->where('r.pharmacy_id', $pharmacy->id)->whereIn('r.id', $refunds)
            ->get(['r.refund_amount', 's.total', 's.tax_amount']);
        foreach ($refundRows as $row) {
            $amount = Decimal::scaled($row->refund_amount, 2);
            $revenue -= $amount - SaleReturns::proportion(Decimal::scaled($row->tax_amount, 2), $amount, max(1, Decimal::scaled($row->total, 2)));
        }
        $movements = DB::table('stock_movements as m')->join('sale_items as i', 'i.id', '=', 'm.sale_item_id')->where('m.pharmacy_id', $pharmacy->id)->where('m.type', 'SALE')->whereIn('i.sale_id', $sales)
            ->leftJoin('stock_movements as source', 'source.id', '=', 'm.source_movement_id')
            ->leftJoin('purchase_receipt_items as receipt', fn ($join) => $join->on('receipt.id', '=', DB::raw('COALESCE(source.purchase_receipt_item_id,m.source_receipt_item_id)')))
            ->leftJoin('purchase_order_items as po', 'po.id', '=', 'receipt.purchase_order_item_id')->select('m.quantity', 'receipt.unit_price', 'po.conversion');
        foreach ($movements->cursor() as $row) {
            if ($row->unit_price === null || ! $row->conversion) {
                $unknown++;

                continue;
            }
            $cost += self::cost($row);
        }
        $restocked = DB::table('sale_return_items as line')->join('stock_movements as m', 'm.id', '=', 'line.sale_movement_id')->whereIn('line.sale_return_id', $refunds)->where('line.disposition', 'RESTOCK')
            ->where('m.pharmacy_id', $pharmacy->id)->leftJoin('stock_movements as source', 'source.id', '=', 'm.source_movement_id')
            ->leftJoin('purchase_receipt_items as receipt', fn ($join) => $join->on('receipt.id', '=', DB::raw('COALESCE(source.purchase_receipt_item_id,m.source_receipt_item_id)')))
            ->leftJoin('purchase_order_items as po', 'po.id', '=', 'receipt.purchase_order_item_id')->select('line.quantity', 'receipt.unit_price', 'po.conversion');
        foreach ($restocked->cursor() as $row) {
            if ($row->unit_price === null || ! $row->conversion) {
                $unknown++;

                continue;
            }
            $cost -= self::cost($row);
        }

        return ['margin' => $unknown ? null : $revenue - $cost, 'revenue' => $revenue, 'cost' => $cost, 'unknown' => $unknown];
    }

    private static function cost(object $row): int
    {
        $quantity = Decimal::scaled(ltrim((string) $row->quantity, '-'), 4);
        $conversion = Decimal::scaled($row->conversion, 4);
        $price = Decimal::scaled($row->unit_price, 2);

        return intdiv($quantity, $conversion) * $price + SaleReturns::proportion($price, $quantity % $conversion, $conversion);
    }
}
