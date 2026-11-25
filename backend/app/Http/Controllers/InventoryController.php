<?php

namespace App\Http\Controllers;

use App\Models\Pharmacy;
use App\Models\Product;
use App\Support\TableSort;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class InventoryController extends Controller
{
    private function dates(Pharmacy $pharmacy): array
    {
        $today = now($pharmacy->timezone ?: 'Asia/Jakarta')->startOfDay();

        return [$today->toDateString(), $today->copy()->addDays($pharmacy->settings?->expiry_reminder_days ?? 90)->toDateString()];
    }

    private function movements(Pharmacy $pharmacy): Builder
    {
        return DB::table('stock_movements')->where('pharmacy_id', $pharmacy->id);
    }

    public function index(Request $request, Pharmacy $pharmacy): View
    {
        $filters = $request->validate(['q' => 'nullable|string|max:150', 'category' => ['nullable', Rule::in(Product::CATEGORIES)],
            'status' => ['nullable', Rule::in(['empty', 'low', 'near', 'expired'])]]);
        [$today, $until] = $this->dates($pharmacy);
        $totals = $this->movements($pharmacy)->select('product_id')->selectRaw('SUM(quantity) AS physical')
            ->selectRaw('SUM(CASE WHEN expires_on IS NULL OR expires_on > ? THEN quantity ELSE 0 END) AS available', [$today])
            ->selectRaw('SUM(CASE WHEN expires_on <= ? THEN quantity ELSE 0 END) AS expired', [$today])
            ->selectRaw('SUM(CASE WHEN expires_on > ? AND expires_on <= ? THEN quantity ELSE 0 END) AS near_expiry', [$today, $until])->groupBy('product_id');
        $base = DB::table('products')->where('products.pharmacy_id', $pharmacy->id)->leftJoinSub($totals, 'stock', 'stock.product_id', '=', 'products.id')
            ->select('products.*')->selectRaw('COALESCE(physical, 0) AS physical, COALESCE(available, 0) AS available, COALESCE(expired, 0) AS expired, COALESCE(near_expiry, 0) AS near_expiry');
        $counts = ['all' => (clone $base)->count(), 'empty' => (clone $base)->whereRaw('COALESCE(available,0) <= 0')->count(),
            'low' => (clone $base)->whereRaw('COALESCE(available,0) > 0 AND COALESCE(available,0) <= minimum_stock')->count(),
            'near' => (clone $base)->where('near_expiry', '>', 0)->count(), 'expired' => (clone $base)->where('expired', '>', 0)->count()];
        if ($term = trim($filters['q'] ?? '')) {
            $base->where(function (Builder $query) use ($term) {
                foreach (['name', 'brand', 'sku', 'barcode', 'generic_name'] as $column) {
                    $query->orWhereRaw('LOWER(products.'.$column.') LIKE ?', ['%'.mb_strtolower($term).'%']);
                }
                $query->orWhereExists(function (Builder $ingredients) use ($term) {
                    $ingredients->selectRaw('1')->from('product_ingredients')->whereColumn('product_ingredients.product_id', 'products.id')->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($term).'%']);
                });
            });
        }
        $base->when($filters['category'] ?? null, fn (Builder $query, string $category) => $query->where('category', $category));
        match ($filters['status'] ?? '') {
            'empty' => $base->whereRaw('COALESCE(available,0) <= 0'),
            'low' => $base->whereRaw('COALESCE(available,0) > 0 AND COALESCE(available,0) <= minimum_stock'),
            'near' => $base->where('near_expiry', '>', 0),
            'expired' => $base->where('expired', '>', 0),
            default => null,
        };
        TableSort::apply($base, ['name' => 'products.name', 'physical' => 'physical', 'available' => 'available', 'minimum' => 'minimum_stock'], 'name');

        return view('inventory.index', ['products' => $base->orderBy('products.id')->paginate(15)->withQueryString(), 'counts' => $counts, 'today' => $today, 'until' => $until]);
    }

    public function show(Request $request, Pharmacy $pharmacy, string $product): View
    {
        $product = $pharmacy->products()->with('ingredients')->findOrFail($product);
        [$today, $until] = $this->dates($pharmacy);
        $batches = $this->movements($pharmacy)->where('product_id', $product->id)
            ->select('batch_number', 'expires_on', 'location', 'base_unit')->selectRaw('SUM(quantity) AS quantity')
            ->groupBy('batch_number', 'expires_on', 'location', 'base_unit')->havingRaw('SUM(quantity) <> 0')
            ->orderByRaw('CASE WHEN expires_on IS NULL THEN 1 ELSE 0 END')->orderBy('expires_on')->orderBy('batch_number')->orderBy('location')->get();

        return view('inventory.show', compact('product', 'batches', 'today', 'until'));
    }

    public function ledger(Request $request, Pharmacy $pharmacy, string $product): View
    {
        $product = $pharmacy->products()->with('ingredients')->findOrFail($product);
        [$today] = $this->dates($pharmacy);
        $filters = $request->validate(['from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d|after_or_equal:from']);
        $from = $filters['from'] ?? substr($today, 0, 7).'-01';
        $to = $filters['to'] ?? max($today, $from);
        if ($from > $to) {
            throw ValidationException::withMessages(['to' => 'Tanggal akhir harus sama atau setelah tanggal awal.']);
        }
        $base = $this->movements($pharmacy)->where('product_id', $product->id);
        $opening = (clone $base)->where('occurred_on', '<', $from)->sum('quantity');
        $period = (clone $base)->whereBetween('occurred_on', [$from, $to]);
        $incoming = (clone $period)->where('quantity', '>', 0)->sum('quantity');
        $outgoing = (clone $period)->where('quantity', '<', 0)->sum('quantity');
        $closing = (clone $base)->where('occurred_on', '<=', $to)->sum('quantity');
        $running = (clone $base)->select('stock_movements.*')->selectRaw('SUM(quantity) OVER (ORDER BY occurred_on, created_at, id ROWS UNBOUNDED PRECEDING) AS balance');
        $query = DB::query()->fromSub($running, 'movement')
            ->leftJoin('purchase_receipt_items as line', 'line.id', '=', 'movement.purchase_receipt_item_id')
            ->leftJoin('purchase_receipts as receipt', function ($join) use ($pharmacy) {
                $join->on('receipt.id', '=', 'line.purchase_receipt_id')->where('receipt.pharmacy_id', '=', $pharmacy->id);
            })->leftJoin('sale_items as sale_line', 'sale_line.id', '=', 'movement.sale_item_id')
            ->leftJoin('sales as sale', function ($join) use ($pharmacy) {
                $join->on('sale.id', '=', 'sale_line.sale_id')->where('sale.pharmacy_id', '=', $pharmacy->id);
            })->leftJoin('stocktake_lines as count_line', 'count_line.id', '=', 'movement.stocktake_line_id')
            ->leftJoin('stocktakes as stocktake', function ($join) use ($pharmacy) {
                $join->on('stocktake.id', '=', 'count_line.stocktake_id')->where('stocktake.pharmacy_id', '=', $pharmacy->id);
            })->leftJoin('sale_return_items as return_line', 'return_line.id', '=', 'movement.sale_return_item_id')
            ->leftJoin('sale_returns as return_header', function ($join) use ($pharmacy) {
                $join->on('return_header.id', '=', 'return_line.sale_return_id')->where('return_header.pharmacy_id', '=', $pharmacy->id);
            })->leftJoin('sales as return_sale', fn ($join) => $join->on('return_sale.id', '=', 'return_header.sale_id')->where('return_sale.pharmacy_id', $pharmacy->id))
            ->select('movement.*', 'receipt.id as receipt_id', 'receipt.number as receipt_number', 'sale.id as sale_id', 'sale.number as sale_number', 'sale.kind as sale_kind', 'return_header.id as return_id', 'return_header.number as return_number', 'return_sale.kind as return_kind',
                'stocktake.id as stocktake_id', 'stocktake.number as stocktake_number')->whereBetween('occurred_on', [$from, $to]);
        TableSort::apply($query, ['date' => fn (Builder $query, string $direction) => $query->orderBy('occurred_on', $direction)->orderBy('movement.created_at', $direction)->orderBy('movement.id', $direction)], 'date');

        return view('inventory.ledger', ['product' => $product, 'movements' => $query->paginate(20)->withQueryString(),
            'from' => $from, 'to' => $to, 'opening' => $opening, 'incoming' => $incoming, 'outgoing' => $outgoing, 'closing' => $closing]);
    }
}
