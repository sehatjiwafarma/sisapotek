<?php

namespace App\Http\Controllers;

use App\Models\Pharmacy;
use App\Services\AccessManager;
use App\Services\ClinicalMaster;
use App\Services\ExaminationRecords;
use App\Services\PosPricing;
use App\Services\PrescriptionDispensing;
use App\Services\PrescriptionRecords;
use App\Support\TableSort;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class DispensingController extends Controller
{
    public function index(Request $request, Pharmacy $pharmacy): View
    {
        $filters = $request->validate(['q' => 'nullable|string|max:150', 'status' => ['nullable', Rule::in(array_keys(PrescriptionDispensing::labels()))]]);
        $query = DB::table('prescriptions as rx')->join('visits', 'visits.id', '=', 'rx.visit_id')->where('rx.pharmacy_id', $pharmacy->id)->where(fn ($q) => $q->where('rx.status', 'FINAL')->orWhereNotNull('rx.ready_at'))
            ->select('rx.id', 'rx.number', 'rx.finalized_at', 'rx.ready_at', 'rx.valid_until', 'rx.dispensing_status', 'visits.patient_name', 'visits.medical_record_number', 'visits.doctor_name')
            ->selectSub(DB::table('prescriptions as sibling')->whereColumn('sibling.visit_id', 'rx.visit_id')->whereColumn('sibling.pharmacy_id', 'rx.pharmacy_id')->selectRaw('COUNT(*)'), 'prescription_count');
        if ($term = trim($filters['q'] ?? '')) {
            $query->where(fn ($query) => $query->whereLike('rx.number', '%'.$term.'%')->orWhereLike('visits.patient_name', '%'.$term.'%')->orWhereLike('visits.medical_record_number', '%'.$term.'%'));
        }
        $query->when($filters['status'] ?? null, fn ($query, $status) => $query->where('rx.dispensing_status', $status));
        TableSort::apply($query, ['number' => 'rx.number', 'date' => fn ($q, $d) => $q->orderByRaw('COALESCE(rx.finalized_at, rx.ready_at) '.$d), 'patient' => 'visits.patient_name', 'doctor' => 'visits.doctor_name', 'status' => 'rx.dispensing_status'], 'date', 'desc');
        app(AccessManager::class)->audit($pharmacy, $request->user()->id, 'dispensing.queue_viewed', $pharmacy->id);

        return view('dispensing.index', ['prescriptions' => $query->orderBy('rx.id')->paginate(15)->withQueryString(), 'labels' => PrescriptionDispensing::labels()]);
    }

    private function context(Request $request, Pharmacy $pharmacy, string $id): array
    {
        $prescription = PrescriptionRecords::query($pharmacy)->where('id', $id)->first();
        abort_unless($prescription, 404);
        abort_unless($prescription->status === 'FINAL' || $prescription->ready_at, 422, 'Simpan resep sebelum penebusan.');
        $payload = ExaminationRecords::payload($prescription);
        $items = PrescriptionDispensing::remaining($pharmacy, $prescription);
        $history = DB::table('sales')->where('pharmacy_id', $pharmacy->id)->where(fn ($query) => $query->where('prescription_id', $id)->orWhereExists(fn ($items) => $items->selectRaw('1')->from('sale_items')->whereColumn('sale_items.sale_id', 'sales.id')->where('sale_items.prescription_id', $id)))->orderByDesc('created_at')->orderBy('id')->get();
        foreach ($history as $sale) {
            $sale->recipient = $sale->recipient_encrypted ? json_decode(Crypt::decryptString($sale->recipient_encrypted), true, 512, JSON_THROW_ON_ERROR) : null;
            unset($sale->recipient_encrypted);
        }
        $member = $request->attributes->get('membership');
        $multipleRestricted = PrescriptionDispensing::requiresMultiplePermission($pharmacy, $prescription) && ! $member->allows('dispensing.multiple_prescriptions');
        $canPrepare = $pharmacy->status === 'ACTIVE' && ! $multipleRestricted && ! PrescriptionDispensing::expired($pharmacy, $prescription);
        foreach (['dispensing.prepare', 'sales.create', 'sales.view'] as $permission) {
            $canPrepare = $canPrepare && $member->allows($permission);
        }
        $allergies = null;
        if ($member->allows('patients.view_clinical')) {
            $patientId = DB::table('visits')->where('pharmacy_id', $pharmacy->id)->where('id', $prescription->visit_id)->value('patient_id');
            $encrypted = ClinicalMaster::patients($pharmacy)->where('id', $patientId)->value('allergies_encrypted');
            $allergies = $encrypted ? Crypt::decryptString($encrypted) : null;
        }
        unset($prescription->payload_encrypted, $payload['allergies']);

        return compact('prescription', 'payload', 'items', 'history', 'canPrepare', 'allergies', 'multipleRestricted') + ['labels' => PrescriptionDispensing::labels()];
    }

    public function show(Request $request, Pharmacy $pharmacy, string $prescription): View
    {
        $context = $this->context($request, $pharmacy, $prescription);
        app(AccessManager::class)->audit($pharmacy, $request->user()->id, 'dispensing.viewed', $prescription);

        $products = $pharmacy->products()->with('ingredients')->where('active', true)->orderBy('name')->get(['id','name','sku','base_unit','dosage_form']);
        $prescribed = $pharmacy->products()->with('ingredients')->whereIn('id', collect($context['items'])->pluck('product_id'))->get()->keyBy('id');
        $checks = [];
        foreach ($context['items'] as $item) {
            foreach ($products as $candidate) {
                if ($candidate->id !== $item['product_id']) {
                    $checks[$item['product_id']][$candidate->id] = \App\Services\SubstitutionCheck::assess($prescribed->get($item['product_id']), $candidate);
                }
            }
        }
        return view('dispensing.show', $context + [
            'substitutionProducts' => $products,
            'substitutionChecks' => $checks,
            'requestKey' => $request->old('request_key', (string) Str::uuid()),
            'discountRules' => PosPricing::available($pharmacy, 'discount')->orderBy('name')->get(),
            'taxRules' => PosPricing::available($pharmacy, 'tax')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request, Pharmacy $pharmacy, string $prescription, PrescriptionDispensing $dispensing): RedirectResponse
    {
        $sale = $dispensing->create($pharmacy, $request->user()->id, $prescription, $request->all());

        return redirect()->route('sales.show', [$pharmacy, $sale])->with('status', 'Penebusan disiapkan. Periksa dan bayar sebelum menyerahkan obat.');
    }

    public function handover(Request $request, Pharmacy $pharmacy, string $sale, PrescriptionDispensing $dispensing): RedirectResponse
    {
        $dispensing->handover($pharmacy, $request->user()->id, $sale);
        $id = DB::table('sales')->where('pharmacy_id', $pharmacy->id)->where('id', $sale)->value('prescription_id');

        return redirect()->route('dispensing.show', [$pharmacy, $id])->with('status', 'Penyerahan obat tercatat. Stok tidak dipotong ulang.');
    }

    public function labels(Request $request, Pharmacy $pharmacy, string $sale): View
    {
        $transaction = DB::table('sales')->where('pharmacy_id', $pharmacy->id)->where('id', $sale)->where('kind', 'PRESCRIPTION')->first();
        abort_unless($transaction, 404);
        abort_unless($transaction->status === 'PAID', 422, 'Etiket tersedia setelah pembayaran lunas.');
        $labelRows = [];
        foreach (PrescriptionDispensing::salePrescriptionIds($transaction) as $rxId) {
            $context = $this->context($request, $pharmacy, $rxId);
            $this->authorizeCombinedLabels($pharmacy, $request, $context['prescription']);
            $query = DB::table('sale_items')->where('sale_id', $sale)->where(function ($q) use ($rxId, $transaction) {
                $q->where('prescription_id', $rxId);
                if ($rxId === $transaction->prescription_id) $q->orWhereNull('prescription_id');
            });
                        foreach ($query->get() as $line) {
                $item = collect($context['items'])->firstWhere('product_id', $line->prescribed_product_id ?? $line->product_id);
                if (! $item) continue;
                $item['name'] = $line->product_name;
                $item['product_id'] = $line->product_id;
                $item['unit'] = $line->unit;
                if ($line->adjusted_dose_encrypted) $item['dose'] = Crypt::decryptString($line->adjusted_dose_encrypted);
                $labelRows[] = ['item' => $item, 'quantity' => $line->base_quantity, 'payload' => $context['payload'], 'prescription' => $context['prescription']];
            }
        }
        app(AccessManager::class)->audit($pharmacy, $request->user()->id, 'dispensing.labels_printed', $sale);
        return view('dispensing.combined-labels', compact('labelRows', 'transaction'));
    }

    private function authorizeCombinedLabels(Pharmacy $pharmacy, Request $request, object $prescription): void
    {
        app(PrescriptionDispensing::class)->assertMultiplePermission($pharmacy, $request->user()->id, $prescription);
    }
}
