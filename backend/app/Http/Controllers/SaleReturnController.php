<?php

namespace App\Http\Controllers;

use App\Models\Pharmacy;
use App\Services\AccessManager;
use App\Services\ExaminationRecords;
use App\Services\PrescriptionRecords;
use App\Services\SaleReturns;
use App\Support\TableSort;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SaleReturnController extends Controller
{
    public function select(Request $request, Pharmacy $pharmacy): View
    {
        $filters = $request->validate(['q' => 'nullable|string|max:150', 'kind' => ['nullable', Rule::in(['GENERAL', 'PRESCRIPTION'])]]);
        $canReadRx = $request->attributes->get('membership')->allows('dispensing.view');
        abort_if(($filters['kind'] ?? null) === 'PRESCRIPTION' && ! $canReadRx, 403);
        $query = DB::table('sales')->leftJoin('prescriptions as rx', fn ($join) => $join->on('rx.id', '=', 'sales.prescription_id')->where('rx.pharmacy_id', $pharmacy->id))
            ->leftJoin('visits', fn ($join) => $join->on('visits.id', '=', 'rx.visit_id')->where('visits.pharmacy_id', $pharmacy->id))
            ->where('sales.pharmacy_id', $pharmacy->id)->where('sales.status', 'PAID')
            ->where(fn ($query) => $query->where('sales.kind', 'GENERAL')->when($canReadRx, fn ($query) => $query->orWhere(fn ($query) => $query->where('sales.kind', 'PRESCRIPTION')->whereNotNull('sales.handed_over_at')->where(fn ($rx) => $rx->where('rx.status', 'FINAL')->orWhereNotNull('rx.ready_at')))))
            ->select('sales.*')->selectRaw("CASE WHEN EXISTS (SELECT 1 FROM sale_items si WHERE si.sale_id = sales.id AND si.prescription_id IS NOT NULL AND si.prescription_id <> sales.prescription_id) THEN 'Beberapa pasien / resep' ELSE COALESCE(visits.patient_name,sales.customer_name) END AS customer_display");
        $query->when($filters['kind'] ?? null, fn ($query, $kind) => $query->where('sales.kind', $kind));
        if ($term = trim($filters['q'] ?? '')) {
            $query->where(fn ($query) => $query->whereLike('sales.number', '%'.$term.'%')->orWhereLike('sales.customer_name', '%'.$term.'%')->orWhereLike('visits.patient_name', '%'.$term.'%')->orWhereExists(fn ($sub) => $sub->selectRaw('1')->from('sale_items as selected_item')->join('prescriptions as selected_rx', 'selected_rx.id', '=', 'selected_item.prescription_id')->join('visits as selected_visit', 'selected_visit.id', '=', 'selected_rx.visit_id')->whereColumn('selected_item.sale_id', 'sales.id')->where('selected_rx.pharmacy_id', $pharmacy->id)->where(fn ($match) => $match->whereLike('selected_visit.patient_name', '%'.$term.'%')->orWhereLike('selected_rx.number', '%'.$term.'%'))));
        }
        TableSort::apply($query, ['number' => 'sales.number', 'date' => 'sales.paid_at', 'customer' => 'customer_display', 'amount' => 'sales.total'], 'date', 'desc');
        if ($canReadRx) {
            app(AccessManager::class)->audit($pharmacy, $request->user()->id, 'prescription_return.selection_viewed', $pharmacy->id);
        }

        return view('returns.select', ['sales' => $query->orderBy('sales.id')->paginate(15)->withQueryString()]);
    }

    public function index(Request $request, Pharmacy $pharmacy): View
    {
        $filters = $request->validate(['q' => 'nullable|string|max:100', 'status' => ['nullable', Rule::in(array_keys(SaleReturns::labels()))], 'kind' => ['nullable', Rule::in(['GENERAL', 'PRESCRIPTION'])]]);
        $query = DB::table('sale_returns as header')->join('sales', 'sales.id', '=', 'header.sale_id')->where('header.pharmacy_id', $pharmacy->id)
            ->select('header.id', 'header.number', 'header.created_at', 'header.status', 'header.refund_amount', 'sales.number as sale_number', 'sales.kind as sale_kind');
        if (! $request->attributes->get('membership')->allows('dispensing.view')) {
            $query->where('sales.kind', 'GENERAL');
        }
        $query->when($filters['kind'] ?? null, fn ($query, $kind) => $query->where('sales.kind', $kind));
        if ($term = trim($filters['q'] ?? '')) {
            $query->where(fn ($query) => $query->whereLike('header.number', '%'.$term.'%')->orWhereLike('sales.number', '%'.$term.'%'));
        }
        $query->when($filters['status'] ?? null, fn ($query, $status) => $query->where('header.status', $status));
        TableSort::apply($query, ['number' => 'header.number', 'sale' => 'sales.number', 'date' => 'header.created_at', 'amount' => 'header.refund_amount', 'status' => 'header.status'], 'date', 'desc');

        return view('returns.index', ['returns' => $query->orderBy('header.id')->paginate(15)->withQueryString(), 'labels' => SaleReturns::labels()]);
    }

    public function create(Request $request, Pharmacy $pharmacy, string $sale): View
    {
        $sale = SaleReturns::sale($pharmacy, $sale, $request->user()->id);

        return view('returns.create', ['sale' => $sale, 'allocations' => SaleReturns::allocations($pharmacy, $sale->id), 'claims' => SaleReturns::claims($pharmacy, $sale->id),
            'requestKey' => $request->old('request_key', (string) Str::uuid())] + $this->clinicalContext($pharmacy, $sale));
    }

    public function store(Request $request, Pharmacy $pharmacy, string $sale, SaleReturns $returns): RedirectResponse
    {
        $id = $returns->create($pharmacy, $request->user()->id, $sale, $request->all());

        return redirect()->route('returns.show', [$pharmacy, $id])->with('status', 'Retur diajukan. Periksa nilai refund dan kondisi barang sebelum persetujuan.');
    }

    private function details(Pharmacy $pharmacy, string $id): array
    {
        $record = DB::table('sale_returns')->where('pharmacy_id', $pharmacy->id)->where('id', $id)->first();
        abort_unless($record, 404);
        $sale = SaleReturns::sale($pharmacy, $record->sale_id, request()->user()->id);
        $context = $this->clinicalContext($pharmacy, $sale);
        $returner = $record->returner_encrypted ? json_decode(Crypt::decryptString($record->returner_encrypted), true, 512, JSON_THROW_ON_ERROR) : null;
        if ($record->return_reason_encrypted) {
            $record->reason = Crypt::decryptString($record->return_reason_encrypted);
        }
        if ($record->cancellation_encrypted) {
            $record->cancellation_reason = Crypt::decryptString($record->cancellation_encrypted);
        }
        $entitlementReason = $record->entitlement_reason_encrypted ? Crypt::decryptString($record->entitlement_reason_encrypted) : null;
        unset($record->returner_encrypted,$record->return_reason_encrypted,$record->cancellation_encrypted,$record->entitlement_reason_encrypted);
        $items = DB::table('sale_return_items as line')->join('sale_items as item', 'item.id', '=', 'line.sale_item_id')
            ->join('stock_movements as movement', 'movement.id', '=', 'line.sale_movement_id')->join('sales', 'sales.id', '=', 'item.sale_id')->leftJoin('prescriptions as item_rx', fn ($join) => $join->whereRaw('item_rx.id = COALESCE(item.prescription_id, sales.prescription_id)'))->leftJoin('visits as item_visit', 'item_visit.id', '=', 'item_rx.visit_id')->where('line.sale_return_id', $id)
            ->select('line.*', 'item.product_name', 'item.sku', 'movement.base_unit', 'movement.batch_number', 'movement.expires_on', 'movement.location', 'item_rx.number as rx_number', 'item_visit.patient_name')->orderBy('item.id')->orderBy('line.id')->get();
        $actors = DB::table('users')->whereIn('id', array_filter([$record->created_by, $record->approved_by, $record->refunded_by, $record->cancelled_by, $record->entitlement_restored_by]))->pluck('name', 'id');

        return compact('record', 'sale', 'items', 'actors', 'returner', 'entitlementReason') + ['labels' => SaleReturns::labels()] + $context;
    }

    private function clinicalContext(Pharmacy $pharmacy, object $sale): array
    {
        if ($sale->kind !== 'PRESCRIPTION') {
            return ['rx' => null, 'originalRecipient' => null];
        }
        $prescription = PrescriptionRecords::query($pharmacy)->where('id', $sale->prescription_id)->first();
        $payload = ExaminationRecords::payload($prescription);
        $rx = ['id' => $prescription->id, 'number' => $prescription->number, 'patient' => $payload['patient'], 'combined' => count(\App\Services\PrescriptionDispensing::salePrescriptionIds($sale)) > 1];
        $originalRecipient = $sale->recipient_encrypted ? json_decode(Crypt::decryptString($sale->recipient_encrypted), true, 512, JSON_THROW_ON_ERROR) : null;
        unset($sale->recipient_encrypted);
        app(AccessManager::class)->audit($pharmacy, request()->user()->id, 'prescription_return.viewed', $sale->id);

        return compact('rx', 'originalRecipient');
    }

    public function show(Pharmacy $pharmacy, string $return): View
    {
        return view('returns.show', $this->details($pharmacy, $return));
    }

    public function approve(Request $request, Pharmacy $pharmacy, string $return, SaleReturns $returns): RedirectResponse
    {
        $data = $request->validate(['revision' => 'required|integer|min:1']);
        $returns->approve($pharmacy, $request->user()->id, $return, (int) $data['revision']);

        return redirect()->route('returns.show', [$pharmacy, $return])->with('status', 'Retur disetujui. Catat refund setelah dana dikembalikan.');
    }

    public function refund(Request $request, Pharmacy $pharmacy, string $return, SaleReturns $returns): RedirectResponse
    {
        $returns->refund($pharmacy, $request->user()->id, $return, $request->all());

        return redirect()->route('returns.show', [$pharmacy, $return])->with('status', 'Refund tercatat. Stok layak jual dikembalikan sekali; barang karantina tetap terpisah.');
    }

    public function cancel(Request $request, Pharmacy $pharmacy, string $return, SaleReturns $returns): RedirectResponse
    {
        $data = $request->validate(['revision' => 'required|integer|min:1', 'reason' => 'required|string|max:1000']);
        $returns->cancel($pharmacy, $request->user()->id, $return, (int) $data['revision'], $data['reason']);

        return redirect()->route('returns.show', [$pharmacy, $return])->with('status', 'Pengajuan retur dibatalkan. Tidak ada refund atau penambahan stok.');
    }

    public function receipt(Pharmacy $pharmacy, string $return): View
    {
        $data = $this->details($pharmacy, $return);
        abort_unless($data['record']->status === 'COMPLETED', 422, 'Bukti refund hanya tersedia setelah refund dicatat.');

        return view('returns.receipt', $data);
    }

    public function restoreEntitlement(Request $request, Pharmacy $pharmacy, string $return, SaleReturns $returns): RedirectResponse
    {
        $data = $request->validate(['revision' => 'required|integer|min:1', 'reason' => 'required|string|max:1000']);
        $returns->restoreEntitlement($pharmacy, $request->user()->id, $return, (int) $data['revision'], $data['reason']);

        return redirect()->route('returns.show', [$pharmacy, $return])->with('status', 'Pemulihan hak tebus disetujui dan dicatat satu kali. Stok serta refund tidak diposting ulang.');
    }
}
