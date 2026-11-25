<?php

namespace App\Http\Controllers;

use App\Models\Pharmacy;
use App\Services\AccessManager;
use App\Services\ClinicalMaster;
use App\Services\ExaminationRecords;
use App\Services\PrescriptionRecords;
use App\Services\VisitRegistration;
use App\Support\TableSort;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class VisitController extends Controller
{
    public function index(Request $request, Pharmacy $pharmacy, AccessManager $access)
    {
        $data = $request->validate(['q' => 'nullable|string|max:150', 'date' => 'nullable|date_format:Y-m-d', 'status' => ['nullable', Rule::in(array_keys(VisitRegistration::STATUSES))], 'doctor' => 'nullable|uuid', 'patient' => 'nullable|uuid']);
        $date = $data['date'] ?? now($pharmacy->settings?->timezone ?: 'Asia/Jakarta')->toDateString();
        $query = VisitRegistration::query($pharmacy)->where('visit_date', $date);
        if ($term = trim($data['q'] ?? '')) {
            $query->where(function ($query) use ($term) {
                foreach (['number', 'patient_name', 'medical_record_number'] as $field) {
                    $query->orWhereRaw('LOWER('.$field.') LIKE ?', ['%'.mb_strtolower($term).'%']);
                }
            });
        }
        foreach (['status' => 'status', 'doctor' => 'doctor_id', 'patient' => 'patient_id'] as $key => $field) {
            if ($data[$key] ?? null) {
                $query->where($field, $data[$key]);
            }
        }
        $counts = VisitRegistration::query($pharmacy)->where('visit_date', $date)->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status');
        TableSort::apply($query, ['queue' => 'queue_number', 'number' => 'number', 'patient' => 'patient_name', 'doctor' => 'doctor_name', 'time' => 'registered_at', 'status' => 'status'], 'queue');
        $access->audit($pharmacy, $request->user()->id, 'visit.list_viewed', 'visits');

        $query->select('id', 'number', 'queue_number', 'patient_name', 'medical_record_number', 'doctor_name', 'registered_at', 'status')
            ->selectSub(DB::table('examinations')->whereColumn('examinations.visit_id', 'visits.id')->select('status'), 'soap_status')
            ->selectSub(DB::table('prescriptions')->whereColumn('prescriptions.visit_id', 'visits.id')->selectRaw(PrescriptionRecords::STATUS_SQL), 'prescription_status');

        return view('visits.index', ['visits' => $query->orderBy('id')->paginate(15)->withQueryString(), 'date' => $date, 'counts' => $counts,
            'doctors' => ClinicalMaster::doctors($pharmacy)->select('id', 'name', 'active')->orderBy('name')->get()]);
    }

    private function record(Pharmacy $pharmacy, string $id): object
    {
        $record = VisitRegistration::query($pharmacy)->where('id', $id)->first();
        abort_unless($record, 404);
        $record->complaint = Crypt::decryptString($record->complaint_encrypted);
        $record->intake = VisitRegistration::intake($record);
        $record->cancellation = $record->cancellation_encrypted ? Crypt::decryptString($record->cancellation_encrypted) : null;
        $record->completion_note = $record->completion_note_encrypted ? Crypt::decryptString($record->completion_note_encrypted) : null;
        unset($record->complaint_encrypted, $record->cancellation_encrypted, $record->completion_note_encrypted, $record->request_key);

        return $record;
    }

    private function form(Pharmacy $pharmacy, ?object $visit = null)
    {
        $tariffs = DB::table('service_tariffs')->where('pharmacy_id', $pharmacy->id)->where('active', true)->orderBy('name')->get();
        foreach (VisitRegistration::services($visit) as $registered) {
            $tariffs = $tariffs->reject(fn ($tariff) => $tariff->id === $registered['tariff_id']);
            $tariffs->push((object) ['id' => $registered['tariff_id'], 'name' => $registered['name'], 'price' => $registered['unit_price']]);
        }

        return view('visits.form', ['visit' => $visit, 'requestKey' => (string) Str::uuid(),
            'tariffs' => $tariffs->sortBy('name')->values(),
            'patients' => ClinicalMaster::patients($pharmacy)->where('active', true)->select('id', 'name', 'medical_record_number', 'birth_date')->orderBy('name')->get(),
            'doctors' => ClinicalMaster::doctors($pharmacy)->where('active', true)->select('id', 'name', 'code', 'specialty')->orderBy('name')->get()]);
    }

    public function create(Pharmacy $pharmacy)
    {
        return $this->form($pharmacy);
    }

    public function patients(Request $request, Pharmacy $pharmacy, AccessManager $access)
    {
        $access->audit($pharmacy, $request->user()->id, 'visit.patient_choices_viewed', 'patients');

        return response()->json(ClinicalMaster::patients($pharmacy)->where('active', true)->select('id', 'name', 'medical_record_number', 'birth_date')->orderBy('name')->get());
    }

    public function edit(Request $request, Pharmacy $pharmacy, string $visit, AccessManager $access)
    {
        $record = $this->record($pharmacy, $visit);
        abort_unless($record->status === 'REGISTERED', 422, 'Hanya kunjungan menunggu dapat diubah.');
        $access->audit($pharmacy, $request->user()->id, 'visit.edit_viewed', $visit);

        return $this->form($pharmacy, $record);
    }

    public function show(Request $request, Pharmacy $pharmacy, string $visit, AccessManager $access)
    {
        $record = $this->record($pharmacy, $visit);
        $allergies = null;
        if ($request->attributes->get('membership')->allows('patients.view_clinical')) {
            $encrypted = ClinicalMaster::patients($pharmacy)->where('id', $record->patient_id)->value('allergies_encrypted');
            $allergies = $encrypted ? Crypt::decryptString($encrypted) : null;
        }
        $access->audit($pharmacy, $request->user()->id, 'visit.viewed', $visit, ['allergies' => $request->attributes->get('membership')->allows('patients.view_clinical')]);

        $canReadExamination = ExaminationRecords::visibleVisits($pharmacy, $request->attributes->get('membership'))->where('visits.id', $visit)->exists();

        return view('visits.show', ['visit' => $record, 'allergies' => $allergies, 'canReadExamination' => $canReadExamination,
            'serviceBill' => DB::table('service_bills')->where('pharmacy_id', $pharmacy->id)->where('visit_id', $visit)->whereIn('status', ['DRAFT', 'PAID'])->first(['id', 'number', 'status', 'total']),
            'prescriptionSales' => $request->attributes->get('membership')->allows('sales.view') ? DB::table('sales')->where('pharmacy_id', $pharmacy->id)->whereExists(fn ($q) => $q->selectRaw('1')->from('sale_items as si')->join('prescriptions as rx', fn ($join) => $join->whereRaw('rx.id = COALESCE(si.prescription_id, sales.prescription_id)'))->whereColumn('si.sale_id', 'sales.id')->where('rx.pharmacy_id', $pharmacy->id)->where('rx.visit_id', $visit))->whereIn('status', ['DRAFT', 'PAID'])->orderBy('created_at')->get(['id', 'number', 'status', 'total', 'handed_over_at']) : collect(),
            'canReadPrescription' => PrescriptionRecords::visibleVisits($pharmacy, $request->attributes->get('membership'))->where('visits.id', $visit)->exists(),
            'soapStatus' => ExaminationRecords::query($pharmacy)->where('visit_id', $visit)->value('status'),
            'prescriptionStatus' => DB::table('prescriptions')->where('pharmacy_id', $pharmacy->id)->where('visit_id', $visit)->selectRaw(PrescriptionRecords::STATUS_SQL.' as aggregate_status')->value('aggregate_status')]);
    }

    private function data(Request $request, bool $update): array
    {
        $data = $request->validate(['services_present' => 'sometimes|accepted', 'service_ids' => 'required_with:services_present|array|min:1|max:100', 'service_ids.*' => 'required|uuid|distinct',
            'intake_present' => 'sometimes|accepted', 'request_key' => $update ? 'nullable|uuid' : 'required|uuid', 'revision' => $update ? 'required|integer|min:1' : 'nullable',
            'patient_id' => $update ? 'prohibited' : 'required|uuid', 'doctor_id' => 'required|uuid', 'type' => ['required', Rule::in(array_keys(VisitRegistration::TYPES))], 'complaint' => 'required|string|max:2000']);
        if ($request->hasAny(['intake_present', ...VisitRegistration::intakeKeys()])) {
            $data += array_intersect_key(ExaminationRecords::validatePayload($request->only(VisitRegistration::intakeKeys()), false), array_flip(VisitRegistration::intakeKeys()));
        }
        if (isset($data['service_ids'])) {
            $data['services'] = array_map(fn ($id) => ['tariff_id' => $id, 'quantity' => 1], $data['service_ids']);
            unset($data['service_ids']);
        }

        return $data;
    }

    public function store(Request $request, Pharmacy $pharmacy, VisitRegistration $registration)
    {
        $id = $registration->save($pharmacy, $request->user()->id, $this->data($request, false));

        return redirect()->route('visits.show', [$pharmacy, $id])->with('status', 'Kunjungan terdaftar. Nomor antrean dibuat otomatis.');
    }

    public function update(Request $request, Pharmacy $pharmacy, string $visit, VisitRegistration $registration)
    {
        $registration->save($pharmacy, $request->user()->id, $this->data($request, true), $visit);

        return redirect()->route('visits.show', [$pharmacy, $visit])->with('status', 'Data kunjungan diperbarui. Nomor antrean tetap.');
    }

    public function transition(Request $request, Pharmacy $pharmacy, string $visit, VisitRegistration $registration)
    {
        $data = $request->validate(['revision' => 'required|integer|min:1', 'action' => ['required', Rule::in(['start', 'cancel'])], 'reason' => 'required_if:action,cancel|nullable|string|max:1000']);
        $registration->transition($pharmacy, $request->user()->id, $visit, $data);

        return redirect()->route('visits.show', [$pharmacy, $visit])->with('status', $data['action'] === 'cancel' ? 'Kunjungan dibatalkan. Nomor antrean tidak digunakan ulang.' : 'Layanan dimulai. Kunjungan siap untuk pencatatan pemeriksaan pada tahap berikutnya.');
    }

    public function complete(Request $request, Pharmacy $pharmacy, string $visit, VisitRegistration $registration)
    {
        $data = $request->validate(['revision' => 'required|integer|min:1', 'pending_reason' => 'nullable|string|max:2000']);
        $registration->complete($pharmacy, $request->user()->id, $visit, (int) $data['revision'], $data['pending_reason'] ?? null);

        return redirect()->route('visits.show', [$pharmacy, $visit])->with('status', 'Layanan kunjungan selesai. SOAP dan resep yang menyusul tetap dapat dilengkapi.');
    }
}
