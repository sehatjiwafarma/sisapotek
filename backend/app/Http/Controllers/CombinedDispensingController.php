<?php
namespace App\Http\Controllers;

use App\Models\Pharmacy;
use App\Services\AccessManager;
use App\Services\ExaminationRecords;
use App\Services\PosPricing;
use App\Services\PrescriptionDispensing;
use App\Services\PrescriptionRecords;
use App\Support\TableSort;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CombinedDispensingController extends Controller
{
    public function index(Request $request, Pharmacy $pharmacy)
    {
        $data = $request->validate(['q' => 'nullable|string|max:150', 'selected' => 'nullable|array|max:30', 'selected.*' => 'uuid|distinct']);
        $selected = $data['selected'] ?? [];
        $term = trim($data['q'] ?? '');
        $query = DB::table('prescriptions as rx')->join('visits as v', 'v.id', '=', 'rx.visit_id')
                ->where('rx.pharmacy_id', $pharmacy->id)->where('v.pharmacy_id', $pharmacy->id)->where(fn ($q) => $q->where('rx.status', 'FINAL')->orWhereNotNull('rx.ready_at'))
                ->where('rx.dispensing_status', 'UNREDEEMED')
                ->select('rx.id', 'rx.number', 'rx.finalized_at', 'rx.ready_at', 'rx.valid_until', 'rx.dispensing_status', 'v.patient_name', 'v.medical_record_number')
                ->selectSub(DB::table('prescriptions as sibling')->whereColumn('sibling.visit_id', 'rx.visit_id')->whereColumn('sibling.pharmacy_id', 'rx.pharmacy_id')->selectRaw('COUNT(*)'), 'prescription_count');
        if ($term !== '') {
            $query->where(fn ($q) => $q->whereLike('rx.number', '%'.$term.'%')->orWhereLike('v.patient_name', '%'.$term.'%')->orWhereLike('v.medical_record_number', '%'.$term.'%'));
        }
        TableSort::apply($query, ['number' => 'rx.number', 'date' => fn ($q, $d) => $q->orderByRaw('COALESCE(rx.finalized_at, rx.ready_at) '.$d), 'patient' => 'v.patient_name', 'validity' => 'rx.valid_until', 'status' => 'rx.dispensing_status'], 'date', 'desc');
        $results = $query->orderBy('rx.id')->paginate(5)->withQueryString();
        $prescriptions = PrescriptionRecords::query($pharmacy)->whereIn('id', $selected)->where('dispensing_status', 'UNREDEEMED')
            ->where(fn ($q) => $q->where('status', 'FINAL')->orWhereNotNull('ready_at'))->get()
            ->filter(fn ($rx) => ! PrescriptionDispensing::expired($pharmacy, $rx));
        $selected = array_values(array_intersect($selected, $prescriptions->pluck('id')->all()));
        $records = $prescriptions->map(fn ($rx) => ['rx' => $rx, 'payload' => ExaminationRecords::payload($rx), 'items' => PrescriptionDispensing::remaining($pharmacy, $rx)]);
        $oldRows = collect($request->old('items', []))->filter(fn ($row) => is_array($row) && isset($row['prescription_id'], $row['product_id']) && is_string($row['prescription_id']) && is_string($row['product_id']))
            ->map(fn ($row) => array_filter($row, fn ($value) => is_scalar($value) || $value === null))
            ->keyBy(fn ($row) => $row['prescription_id'].'|'.$row['product_id']);
        $productIds = $records->flatMap(fn ($record) => collect($record['items'])->pluck('product_id'))->unique()->values();
        $substitutionProducts = $pharmacy->products()->with('ingredients')->where('active', true)->orderBy('name')->get(['id', 'name', 'sku', 'base_unit', 'dosage_form', 'selling_price']);
        $prescribedProducts = $pharmacy->products()->with('ingredients')->whereIn('id', $productIds)->get()->keyBy('id');
        $substitutionChecks = [];
        foreach ($productIds as $productId) {
            foreach ($substitutionProducts as $candidate) {
                if ($candidate->id !== $productId) $substitutionChecks[$productId][$candidate->id] = \App\Services\SubstitutionCheck::assess($prescribedProducts->get($productId), $candidate);
            }
        }
        app(AccessManager::class)->audit($pharmacy, $request->user()->id, 'dispensing.combined_viewed', $pharmacy->id);
        return view('dispensing.combined', compact('results', 'selected', 'term', 'records', 'substitutionProducts', 'substitutionChecks', 'oldRows') + [
            'requestKey' => $request->old('request_key', (string) Str::uuid()),
            'discountRules' => PosPricing::available($pharmacy, 'discount')->orderBy('name')->get(),
            'taxRules' => PosPricing::available($pharmacy, 'tax')->orderBy('name')->get(),
        ]);
    }

    public function pay(Request $request, Pharmacy $pharmacy, PrescriptionDispensing $dispensing, \App\Services\PointOfSale $pos)
    {
        $payment = $request->validate([
            'payment_method' => ['required', \Illuminate\Validation\Rule::in(['cash', 'transfer', 'qris', 'debit'])],
            'payment_reference' => 'nullable|required_unless:payment_method,cash|string|max:100',
            'amount_received' => 'required|numeric|min:0|max:100000000000000|decimal:0,2',
            'expected_total' => 'required|numeric|min:0|max:100000000000000|decimal:0,2',
            'print_receipt' => 'sometimes|boolean',
        ]);
        $access = app(AccessManager::class);
        $access->actor($pharmacy, $request->user()->id, 'sales.pay');
        if (! empty($payment['print_receipt'])) $access->actor($pharmacy, $request->user()->id, 'sales.print');
        $sale = DB::transaction(function () use ($request, $pharmacy, $dispensing, $pos, $payment) {
            $id = $dispensing->createCombined($pharmacy, $request->user()->id, $request->all());
            $record = DB::table('sales')->where('pharmacy_id', $pharmacy->id)->where('id', $id)->first();
            if (\App\Support\Decimal::scaled($record->total, 2) !== \App\Support\Decimal::scaled($payment['expected_total'], 2)) {
                throw \Illuminate\Validation\ValidationException::withMessages(['amount_received' => 'Harga atau aturan diskon/PPN berubah. Muat ulang dan periksa total sebelum membayar.']);
            }
            $pos->pay($pharmacy, $request->user()->id, $id, $payment + ['revision' => $record->revision]);
            return $id;
        }, 3);
        if (! empty($payment['print_receipt'])) return redirect()->route('sales.receipt', [$pharmacy, $sale])->with('print_receipt', true);
        return redirect()->route('sales.show', [$pharmacy, $sale])->with('status', 'Pembayaran penebusan gabungan berhasil dicatat.');
    }
    public function store(Request $request, Pharmacy $pharmacy, PrescriptionDispensing $dispensing)
    {
        $sale = $dispensing->createCombined($pharmacy, $request->user()->id, $request->all());
        return redirect()->route('sales.show', [$pharmacy, $sale])->with('status', 'Penebusan gabungan disiapkan. Periksa dan bayar sebelum penyerahan.');
    }
}
