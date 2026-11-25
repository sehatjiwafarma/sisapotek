<?php

namespace App\Http\Controllers;

use App\Models\Pharmacy;
use App\Services\PointOfSale;
use App\Services\PosPricing;
use App\Services\SaleReturns;
use App\Support\Decimal;
use App\Support\TableSort;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SaleController extends Controller
{
    private function sale(Pharmacy $pharmacy, string $id): object
    {
        $sale = DB::table('sales')->where('pharmacy_id', $pharmacy->id)->where('id', $id)->first();
        abort_unless($sale, 404);
        if ($sale->kind === 'PRESCRIPTION') {
            abort_unless(request()->attributes->get('membership')->allows('dispensing.view'), 403);
        }

        return $sale;
    }

    public function index(Request $request, Pharmacy $pharmacy): View
    {
        $filters = $request->validate(['q' => 'nullable|string|max:150', 'status' => ['nullable', Rule::in(['DRAFT', 'PAID', 'CANCELLED'])],
            'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d|after_or_equal:from']);
        $prescriptionEntries = DB::table('sale_items as si')
            ->join('sales as source', 'source.id', '=', 'si.sale_id')
            ->where('source.pharmacy_id', $pharmacy->id)->where('source.kind', 'PRESCRIPTION')
            ->select('si.sale_id')->selectRaw('COALESCE(si.prescription_id, source.prescription_id) as rx_id')
            ->groupBy('si.sale_id')->groupByRaw('COALESCE(si.prescription_id, source.prescription_id)');
        $query = DB::table('sales')->leftJoinSub($prescriptionEntries, 'entry', 'entry.sale_id', '=', 'sales.id')
            ->leftJoin('prescriptions as rx', fn ($join) => $join->on('rx.id', '=', 'entry.rx_id')->where('rx.pharmacy_id', $pharmacy->id))
            ->leftJoin('visits as visit', fn ($join) => $join->on('visit.id', '=', 'rx.visit_id')->where('visit.pharmacy_id', $pharmacy->id))
            ->where('sales.pharmacy_id', $pharmacy->id)
            ->select('sales.*', 'entry.rx_id', 'rx.number as rx_number', 'visit.patient_name');
        if (! $request->attributes->get('membership')->allows('dispensing.view')) {
            $query->where('sales.kind', 'GENERAL');
        }
        if ($term = trim($filters['q'] ?? '')) {
            $query->where(fn (Builder $query) => $query->whereRaw('LOWER(sales.number) LIKE ?', ['%'.mb_strtolower($term).'%'])->orWhereRaw('LOWER(sales.customer_name) LIKE ?', ['%'.mb_strtolower($term).'%'])->orWhereRaw('LOWER(rx.number) LIKE ?', ['%'.mb_strtolower($term).'%'])->orWhereRaw('LOWER(visit.patient_name) LIKE ?', ['%'.mb_strtolower($term).'%']));
        }
        $query->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('sales.status', $status))
            ->when($filters['from'] ?? null, fn (Builder $query, string $from) => $query->where('sales.created_at', '>=', Carbon::parse($from, $pharmacy->timezone ?: 'Asia/Jakarta')->startOfDay()->utc()))
            ->when($filters['to'] ?? null, fn (Builder $query, string $to) => $query->where('sales.created_at', '<', Carbon::parse($to, $pharmacy->timezone ?: 'Asia/Jakarta')->addDay()->startOfDay()->utc()));
        TableSort::apply($query, ['number' => 'sales.number', 'date' => 'sales.created_at', 'customer' => fn ($q, $direction) => $q->orderByRaw("COALESCE(visit.patient_name, sales.customer_name, '') ".$direction), 'status' => 'sales.status'], 'date', 'desc');

        $sales = $query->orderBy('sales.id')->orderBy('entry.rx_id')->paginate(15)->withQueryString();
        $saleIds = $sales->getCollection()->where('kind', 'PRESCRIPTION')->pluck('id')->unique();
        $lines = DB::table('sale_items')->whereIn('sale_id', $saleIds)->orderBy('id')->get()->groupBy('sale_id');
        foreach ($sales as $sale) {
            $sale->entry_total = $sale->total;
            if ($sale->kind !== 'PRESCRIPTION' || ! $sale->rx_id) continue;
            $subtotal = Decimal::scaled($sale->subtotal, 2);
            $total = Decimal::scaled($sale->total, 2);
            $cumulative = 0; $assigned = 0; $amount = 0;
            foreach ($lines->get($sale->id, collect()) as $line) {
                $cumulative += Decimal::scaled($line->total, 2);
                $share = $subtotal > 0 ? SaleReturns::proportion($total, $cumulative, $subtotal) : 0;
                if (($line->prescription_id ?? $sale->prescription_id) === $sale->rx_id) $amount += $share - $assigned;
                $assigned = $share;
            }
            $sale->entry_total = Decimal::format($amount);
        }

        return view('sales.index', ['sales' => $sales]);
    }

    private function catalogQuery(Pharmacy $pharmacy, string $term = ''): Builder
    {
        $today = now($pharmacy->timezone ?: 'Asia/Jakarta')->toDateString();
        $stock = DB::table('stock_movements')->where('pharmacy_id', $pharmacy->id)->where('occurred_on', '<=', $today)
            ->where(fn (Builder $query) => $query->whereNull('expires_on')->orWhere('expires_on', '>', $today))
            ->select('product_id')->selectRaw('SUM(quantity) AS available')->groupBy('product_id');
        $counting = DB::table('stocktake_products as count_scope')->join('stocktakes as count_session', 'count_session.id', '=', 'count_scope.stocktake_id')
            ->where('count_session.pharmacy_id', $pharmacy->id)->whereIn('count_session.status', ['COUNTING', 'APPROVED'])
            ->select('count_scope.product_id')->selectRaw('MIN(count_session.number) AS stocktake_number')->groupBy('count_scope.product_id');
        $query = DB::table('products')->where('products.pharmacy_id', $pharmacy->id)->where('active', true)->where('requires_prescription', false)
            ->leftJoinSub($stock, 'stock', 'stock.product_id', '=', 'products.id')
            ->leftJoinSub($counting, 'counting', 'counting.product_id', '=', 'products.id')
            ->select('products.id', 'name', 'brand', 'sku', 'barcode', 'base_unit', 'purchase_unit', 'purchase_conversion', 'selling_price', 'selling_units', 'counting.stocktake_number')->selectRaw('COALESCE(available,0) AS available');
        if ($term !== '') {
            $query->where(function (Builder $query) use ($term) {
                foreach (['name', 'brand', 'sku', 'barcode', 'generic_name'] as $column) {
                    $query->orWhereRaw('LOWER(products.'.$column.') LIKE ?', ['%'.mb_strtolower($term).'%']);
                }
                $query->orWhereExists(fn (Builder $ingredients) => $ingredients->selectRaw('1')->from('product_ingredients')
                    ->whereColumn('product_ingredients.product_id', 'products.id')->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($term).'%']));
            });
        }

        return $query;
    }

    public function catalog(Request $request, Pharmacy $pharmacy): JsonResponse
    {
        $data = $request->validate(['q' => 'nullable|string|max:150', 'page' => 'nullable|integer|min:1|max:100000']);

        $catalog = $this->catalogQuery($pharmacy, trim($data['q'] ?? ''))->orderBy('name')->orderBy('products.id')->paginate(12);
        $catalog->getCollection()->transform(function ($product) use ($pharmacy) {
            $product->composition = \App\Support\ProductComposition::label($pharmacy, $product->id);
            return $product;
        });
        return response()->json($catalog);
    }

    public function create(Request $request, Pharmacy $pharmacy): View
    {
        return $this->form($request, $pharmacy);
    }

    public function edit(Request $request, Pharmacy $pharmacy, string $sale): View
    {
        $sale = $this->sale($pharmacy, $sale);
        abort_unless($sale->kind === 'GENERAL', 422, 'Batalkan draft penebusan dan siapkan ulang melalui halaman resep.');
        abort_unless($sale->status === 'DRAFT', 409, 'Hanya draft yang dapat diedit.');

        return $this->form($request, $pharmacy, $sale);
    }

    private function form(Request $request, Pharmacy $pharmacy, ?object $sale = null): View
    {
        $items = $request->old('items', $sale ? DB::table('sale_items')->where('sale_id', $sale->id)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all() : []);
        $items = is_array($items) ? array_slice($items, 0, 100) : [];
        $ids = collect($items)->pluck('product_id')->filter(fn ($id) => is_string($id) && Str::isUuid($id))->all();
        $products = $this->catalogQuery($pharmacy)->whereIn('products.id', $ids)->get()->each(function ($product) use ($pharmacy) {
            $product->composition = \App\Support\ProductComposition::label($pharmacy, $product->id);
        })->keyBy('id');
        $cart = collect($items)->map(fn ($item) => ['product' => $products->get($item['product_id'] ?? ''),
            'product_id' => $item['product_id'] ?? '', 'quantity' => $item['quantity'] ?? 1, 'unit_type' => $item['unit_type'] ?? 'base'])->values();

        return view('sales.form', ['sale' => $sale, 'cart' => $cart, 'requestKey' => $request->old('request_key', (string) Str::uuid()),
            'discountRules' => PosPricing::available($pharmacy, 'discount')->orderByDesc('is_default')->orderBy('name')->get(),
            'taxRules' => PosPricing::available($pharmacy, 'tax')->orderByDesc('is_default')->orderBy('name')->get()]);
    }

    private function validatedDraft(Request $request, bool $updating): array
    {
        return $request->validate([
            'request_key' => $updating ? 'nullable|uuid' : 'required|uuid', 'revision' => $updating ? 'required|integer|min:1' : 'nullable',
            'customer_name' => 'nullable|string|max:150', 'notes' => 'nullable|string|max:2000',
            'discount' => 'nullable|numeric|min:0|max:10000000000000|decimal:0,2',
            'discount_rule_id' => 'nullable|uuid', 'tax_rule_id' => 'nullable|uuid',
            'items' => 'required|array|min:1|max:100', 'items.*' => 'array:product_id,quantity,unit_type',
            'items.*.product_id' => 'required|uuid|distinct', 'items.*.quantity' => 'required|numeric|gt:0|max:10000|decimal:0,3',
            'items.*.unit_type' => ['required', 'string', 'regex:/^(base|purchase|s[0-9]{1,2})$/'],
        ]);
    }

    public function store(Request $request, Pharmacy $pharmacy, PointOfSale $pos): RedirectResponse
    {
        $id = $pos->save($pharmacy, $request->user()->id, $this->validatedDraft($request, false));

        return redirect()->route('sales.show', [$pharmacy, $id])->with('status', 'Draft disimpan. Periksa barang dan proses pembayaran untuk menyelesaikan penjualan.');
    }

    public function update(Request $request, Pharmacy $pharmacy, string $sale, PointOfSale $pos): RedirectResponse
    {
        $pos->save($pharmacy, $request->user()->id, $this->validatedDraft($request, true), $sale);

        return redirect()->route('sales.show', [$pharmacy, $sale])->with('status', 'Draft penjualan diperbarui.');
    }

    public function show(Pharmacy $pharmacy, string $sale): View
    {
        $details = $this->details($pharmacy, $sale);
        $allergies = null;
        $canSeeAllergies = request()->attributes->get('membership')->allows('patients.view_clinical');
        if ($canSeeAllergies && $details['prescription']) {
            $patientId = DB::table('visits')->where('pharmacy_id', $pharmacy->id)->where('id', $details['prescription']->visit_id)->value('patient_id');
            $encrypted = \App\Services\ClinicalMaster::patients($pharmacy)->where('id', $patientId)->value('allergies_encrypted');
            $allergies = $encrypted ? Crypt::decryptString($encrypted) : null;
        }
        return view('sales.show', $details + ['allergies' => $allergies, 'canSeeAllergies' => $canSeeAllergies,
            'discountRules' => \App\Services\PosPricing::available($pharmacy, 'discount')->orderBy('name')->get(),
            'taxRules' => \App\Services\PosPricing::available($pharmacy, 'tax')->orderBy('name')->get()]);
    }

    private function details(Pharmacy $pharmacy, string $id): array
    {
        $sale = $this->sale($pharmacy, $id);
        $items = DB::table('sale_items')->where('sale_id', $id)->orderBy('id')->get();
        $allocations = DB::table('stock_movements')->where('pharmacy_id', $pharmacy->id)->whereIn('sale_item_id', $items->pluck('id'))->orderBy('expires_on')->get()->groupBy('sale_item_id');
        $cashier = $sale->paid_by ? DB::table('users')->where('id', $sale->paid_by)->value('name') : null;
        $prescription = null;
        $recipient = null;
        $prescriptions = collect();
        if ($sale->kind === 'PRESCRIPTION') {
            $prescription = DB::table('prescriptions')->where('pharmacy_id', $pharmacy->id)->where('id', $sale->prescription_id)->first(['id', 'number', 'visit_id']);
            $prescriptions = DB::table('prescriptions as rx')->join('visits as v', 'v.id', '=', 'rx.visit_id')->where('rx.pharmacy_id', $pharmacy->id)->whereIn('rx.id', \App\Services\PrescriptionDispensing::salePrescriptionIds($sale))->get(['rx.id', 'rx.number', 'v.patient_name']);
            $recipient = $sale->recipient_encrypted ? json_decode(Crypt::decryptString($sale->recipient_encrypted), true, 512, JSON_THROW_ON_ERROR) : null;
        }

        $serviceBill = DB::table('service_bills')->where('pharmacy_id', $pharmacy->id)->where('sale_id', $id)->whereIn('status', ['DRAFT', 'PAID'])->first(['id', 'number']);

        return compact('sale', 'items', 'allocations', 'cashier', 'prescription', 'prescriptions', 'recipient', 'serviceBill');
    }

    public function pay(Request $request, Pharmacy $pharmacy, string $sale, PointOfSale $pos): RedirectResponse
    {
        $data = $request->validate(['revision' => 'required|integer|min:1', 'payment_method' => ['required', Rule::in(['cash', 'transfer', 'qris', 'debit'])],
            'payment_reference' => 'nullable|string|max:100', 'amount_received' => 'required|numeric|min:0|max:100000000000000|decimal:0,2',
            'recipient_type' => ['nullable', Rule::in(['self', 'representative'])], 'recipient_name' => 'nullable|required_if:recipient_type,representative|string|max:150',
            'recipient_relationship' => 'nullable|required_if:recipient_type,representative|string|max:100', 'recipient_phone' => 'nullable|required_if:recipient_type,representative|string|max:30',
            'discount_rule_id' => 'nullable|uuid', 'tax_rule_id' => 'nullable|uuid', 'print_receipt' => 'sometimes|boolean']);
        if (! empty($data['print_receipt'])) abort_unless($request->attributes->get('membership')->allows('sales.print'), 403);
        $pos->pay($pharmacy, $request->user()->id, $sale, $data);

        if (! empty($data['print_receipt'])) {
            return redirect()->route('sales.receipt', [$pharmacy, $sale])->with('print_receipt', true);
        }
        return redirect()->route('sales.show', [$pharmacy, $sale])->with('status', 'Penjualan lunas. Pembayaran dan pengeluaran stok telah dicatat.');
    }

    public function cancel(Request $request, Pharmacy $pharmacy, string $sale, PointOfSale $pos): RedirectResponse
    {
        $data = $request->validate(['reason' => 'required|string|max:1000']);
        $pos->cancel($pharmacy, $request->user()->id, $sale, $data['reason']);

        return redirect()->route('sales.show', [$pharmacy, $sale])->with('status', 'Draft penjualan dibatalkan.');
    }

    public function receipt(Pharmacy $pharmacy, string $sale): View
    {
        $data = $this->details($pharmacy, $sale);
        abort_unless($data['sale']->status === 'PAID', 409, 'Struk hanya tersedia untuk penjualan lunas.');

        return view('sales.receipt', $data);
    }
}
