<?php

namespace App\Http\Controllers;

use App\Models\Pharmacy;
use App\Models\Product;
use App\Services\Stocktaking;
use App\Support\TableSort;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StocktakeController extends Controller
{
    public const STATUSES = ['COUNTING' => 'Penghitungan', 'APPROVED' => 'Disetujui', 'POSTED' => 'Diposting', 'CANCELLED' => 'Dibatalkan'];

    public function index(Request $request, Pharmacy $pharmacy): View
    {
        $data = $request->validate(['q' => 'nullable|string|max:150', 'status' => ['nullable', Rule::in(array_keys(self::STATUSES))]]);
        $query = DB::table('stocktakes')->where('pharmacy_id', $pharmacy->id);
        if ($term = trim($data['q'] ?? '')) {
            $query->where(fn ($query) => $query->whereRaw('LOWER(number) LIKE ?', ['%'.mb_strtolower($term).'%'])->orWhereRaw('LOWER(scope) LIKE ?', ['%'.mb_strtolower($term).'%']));
        }
        $query->when($data['status'] ?? null, fn ($query, $status) => $query->where('status', $status));
        TableSort::apply($query, ['number' => 'number', 'date' => 'created_at', 'scope' => 'scope', 'status' => 'status'], 'date', 'desc');

        return view('stocktakes.index', ['sessions' => $query->orderBy('id')->paginate(15)->withQueryString()]);
    }

    public function create(Pharmacy $pharmacy): View
    {
        return view('stocktakes.create', ['products' => $pharmacy->products()->orderBy('name')->orderBy('id')->get(['id', 'name', 'brand', 'sku']),
            'requestKey' => (string) Str::uuid()]);
    }

    public function store(Request $request, Pharmacy $pharmacy, Stocktaking $stocktaking): RedirectResponse
    {
        $data = $request->validate(['request_key' => 'required|uuid', 'product_id' => 'nullable|uuid', 'category' => ['nullable', Rule::in(Product::CATEGORIES)], 'notes' => 'nullable|string|max:2000']);
        $id = $stocktaking->start($pharmacy, $request->user()->id, $data);

        return redirect()->route('stocktakes.show', [$pharmacy, $id])->with('status', 'Snapshot dibuat. Produk dalam sesi dihentikan sementara dari perubahan stok sampai sesi selesai atau dibatalkan.');
    }

    public function show(Request $request, Pharmacy $pharmacy, string $stocktake): View
    {
        $session = DB::table('stocktakes')->where('pharmacy_id', $pharmacy->id)->where('id', $stocktake)->first();
        abort_unless($session, 404);
        $data = $request->validate(['q' => 'nullable|string|max:150', 'filter' => ['nullable', Rule::in(['pending', 'difference'])]]);
        $base = DB::table('stocktake_lines')->where('stocktake_id', $stocktake);
        $summary = ['all' => (clone $base)->count(), 'pending' => (clone $base)->whereNull('physical_quantity')->count(),
            'difference' => (clone $base)->whereColumn('physical_quantity', '<>', 'system_quantity')->count()];
        $query = clone $base;
        if ($term = trim($data['q'] ?? '')) {
            $query->where(fn ($query) => $query->whereRaw('LOWER(product_name) LIKE ?', ['%'.mb_strtolower($term).'%'])->orWhereRaw('LOWER(sku) LIKE ?', ['%'.mb_strtolower($term).'%'])->orWhereRaw('LOWER(batch_number) LIKE ?', ['%'.mb_strtolower($term).'%']));
        }
        if (($data['filter'] ?? '') === 'pending') {
            $query->whereNull('physical_quantity');
        } elseif (($data['filter'] ?? '') === 'difference') {
            $query->whereColumn('physical_quantity', '<>', 'system_quantity');
        }
        TableSort::apply($query, ['name' => 'product_name', 'batch' => 'batch_number', 'expiry' => 'expires_on', 'system' => 'system_quantity', 'ledger' => 'physical_card_quantity', 'physical' => 'physical_quantity'], 'name');
        $products = $pharmacy->products()->whereIn('id', DB::table('stocktake_products')->where('stocktake_id', $stocktake)->select('product_id'))->orderBy('name')->get(['id', 'name', 'sku', 'base_unit']);
        $actors = DB::table('users')->whereIn('id', array_filter([$session->created_by, $session->approved_by, $session->posted_by]))->pluck('name', 'id');

        return view('stocktakes.show', ['session' => $session, 'lines' => $query->orderBy('id')->paginate(25)->withQueryString(), 'summary' => $summary, 'products' => $products, 'actors' => $actors]);
    }

    private function redirect(Request $request, Pharmacy $pharmacy, string $id, string $message): RedirectResponse
    {
        $navigation = array_filter($request->only(['q', 'filter', 'sort', 'direction', 'page']), fn ($value) => is_string($value));

        return redirect()->route('stocktakes.show', ['pharmacy' => $pharmacy, 'stocktake' => $id] + $navigation)->with('status', $message);
    }

    public function counts(Request $request, Pharmacy $pharmacy, string $stocktake, Stocktaking $stocktaking): RedirectResponse
    {
        $data = $request->validate(['revision' => 'required|integer|min:1', 'lines' => 'required|array|min:1|max:25', 'lines.*' => 'array:id,physical_quantity,physical_card_quantity,reason',
            'lines.*.physical_card_quantity' => 'nullable|numeric|min:0|max:10000000000|decimal:0,4',
            'lines.*.id' => 'required|uuid|distinct', 'lines.*.physical_quantity' => 'required|numeric|min:0|max:10000000000|decimal:0,4', 'lines.*.reason' => 'nullable|string|max:1000']);
        $stocktaking->count($pharmacy, $request->user()->id, $stocktake, $data);

        return $this->redirect($request, $pharmacy, $stocktake, 'Hitungan halaman ini disimpan.');
    }

    public function addLine(Request $request, Pharmacy $pharmacy, string $stocktake, Stocktaking $stocktaking): RedirectResponse
    {
        $data = $request->validate(['revision' => 'required|integer|min:1', 'product_id' => 'required|uuid', 'batch_number' => 'nullable|string|max:80',
            'expires_on' => 'nullable|date_format:Y-m-d', 'location' => 'nullable|string|max:100']);
        $stocktaking->addLine($pharmacy, $request->user()->id, $stocktake, $data);

        return redirect()->route('stocktakes.show', [$pharmacy, $stocktake, 'filter' => 'pending'])->with('status', 'Batch ditemukan ditambahkan. Isi dan simpan jumlah fisiknya.');
    }

    public function approve(Request $request, Pharmacy $pharmacy, string $stocktake, Stocktaking $stocktaking): RedirectResponse
    {
        $data = $request->validate(['revision' => 'required|integer|min:1']);
        $stocktaking->approve($pharmacy, $request->user()->id, $stocktake, (int) $data['revision']);

        return $this->redirect($request, $pharmacy, $stocktake, 'Hasil penghitungan disetujui. Periksa dan posting koreksi untuk menyelesaikan sesi.');
    }

    public function post(Request $request, Pharmacy $pharmacy, string $stocktake, Stocktaking $stocktaking): RedirectResponse
    {
        $data = $request->validate(['revision' => 'required|integer|min:1']);
        $stocktaking->post($pharmacy, $request->user()->id, $stocktake, (int) $data['revision']);

        return $this->redirect($request, $pharmacy, $stocktake, 'Koreksi opname diposting. Transaksi stok untuk produk sesi dapat dilanjutkan.');
    }

    public function cancel(Request $request, Pharmacy $pharmacy, string $stocktake, Stocktaking $stocktaking): RedirectResponse
    {
        $data = $request->validate(['revision' => 'required|integer|min:1', 'reason' => 'required|string|max:1000']);
        $stocktaking->cancel($pharmacy, $request->user()->id, $stocktake, (int) $data['revision'], $data['reason']);

        return $this->redirect($request, $pharmacy, $stocktake, 'Sesi dibatalkan tanpa koreksi stok. Transaksi produk sesi dapat dilanjutkan.');
    }
}
