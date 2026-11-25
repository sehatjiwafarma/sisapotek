<?php

namespace App\Http\Controllers;

use App\Models\Pharmacy;
use App\Services\AccessManager;
use App\Services\ClinicalMaster;
use App\Services\VisitRegistration;
use App\Support\TableSort;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PatientController extends Controller
{
    public const SEXES = ['male' => 'Laki-laki', 'female' => 'Perempuan', 'unspecified' => 'Belum diisi / tidak diketahui'];

    public function index(Request $request, Pharmacy $pharmacy, AccessManager $manager): View
    {
        $data = $request->validate(['q' => 'nullable|string|max:150', 'status' => ['nullable', Rule::in(['active', 'inactive'])]]);
        $query = ClinicalMaster::patients($pharmacy)->select('id', 'medical_record_number', 'name', 'birth_date', 'sex', 'phone', 'active');
        if ($term = trim($data['q'] ?? '')) {
            $query->where(function ($query) use ($term) {
                foreach (['name', 'medical_record_number', 'phone'] as $field) {
                    $query->orWhereRaw('LOWER('.$field.') LIKE ?', ['%'.mb_strtolower($term).'%']);
                }
            });
        }
        if ($data['status'] ?? null) {
            $query->where('active', $data['status'] === 'active');
        }
        TableSort::apply($query, ['number' => 'medical_record_number', 'name' => 'name', 'birth' => 'birth_date', 'sex' => 'sex', 'phone' => 'phone', 'status' => 'active'], 'name');
        $manager->audit($pharmacy, $request->user()->id, 'patient.list_viewed', 'patients');

        return view('patients.index', ['patients' => $query->orderBy('id')->paginate(15)->withQueryString()]);
    }

    private function record(Request $request, Pharmacy $pharmacy, string $id): object
    {
        $record = ClinicalMaster::patients($pharmacy)->where('id', $id)->first();
        abort_unless($record, 404);
        $access = $request->attributes->get('membership');
        foreach (['nik' => 'patients.view_sensitive', 'notes' => 'patients.view_sensitive', 'allergies' => 'patients.view_clinical'] as $field => $permission) {
            $encrypted = $field.'_encrypted';
            if ($access->allows($permission)) {
                $record->$field = $record->$encrypted ? Crypt::decryptString($record->$encrypted) : null;
            }
            unset($record->$encrypted);
        }
        unset($record->nik_hash,$record->request_key);

        return $record;
    }

    public function create(Pharmacy $pharmacy): View
    {
        return view('patients.form', ['patient' => null, 'requestKey' => (string) Str::uuid()]);
    }

    public function edit(Request $request, Pharmacy $pharmacy, string $patient, AccessManager $manager): View
    {
        $record = $this->record($request, $pharmacy, $patient);
        $manager->audit($pharmacy, $request->user()->id, 'patient.edit_viewed', $patient);

        return view('patients.form', ['patient' => $record, 'requestKey' => null]);
    }

    public function show(Request $request, Pharmacy $pharmacy, string $patient, AccessManager $manager): View
    {
        $record = $this->record($request, $pharmacy, $patient);
        $manager->audit($pharmacy, $request->user()->id, 'patient.viewed', $patient, ['sensitive' => $request->attributes->get('membership')->allows('patients.view_sensitive'), 'clinical' => $request->attributes->get('membership')->allows('patients.view_clinical')]);

        $visits = null;
        if ($request->attributes->get('membership')->allows('visits.view')) {
            $query = VisitRegistration::query($pharmacy)->where('patient_id', $patient)->select('id', 'number', 'registered_at', 'doctor_name', 'status');
            TableSort::apply($query, ['number' => 'number', 'date' => 'registered_at', 'doctor' => 'doctor_name', 'status' => 'status'], 'date', 'desc', 'visits_');
            $visits = $query->orderBy('id')->paginate(10, ['*'], 'visits_page')->withQueryString();
        }

        $member = $request->attributes->get('membership');
        $timeline = collect();
        if ($member->allows('visits.view')) {
            $timeline = VisitRegistration::query($pharmacy)->where('patient_id', $patient)->orderByDesc('registered_at')->limit(20)->get(['id', 'number', 'registered_at', 'doctor_name', 'status']);
            $examVisible = \App\Services\ExaminationRecords::visibleVisits($pharmacy, $member)->whereIn('visits.id', $timeline->pluck('id'))->pluck('visits.id')->all();
            $rxVisible = \App\Services\PrescriptionRecords::visibleVisits($pharmacy, $member)->whereIn('visits.id', $timeline->pluck('id'))->pluck('visits.id')->all();
            foreach ($timeline as $entry) {
                $entry->exam_visible = in_array($entry->id, $examVisible, true);
                $entry->rx_visible = in_array($entry->id, $rxVisible, true);
                $entry->soap_status = $entry->exam_visible ? \App\Services\ExaminationRecords::query($pharmacy)->where('visit_id', $entry->id)->value('status') : null;
                $entry->prescriptions = $entry->rx_visible ? DB::table('prescriptions')->where('pharmacy_id', $pharmacy->id)->where('visit_id', $entry->id)->orderBy('created_at')->get(['id', 'number', 'status']) : collect();
                $entry->redemptions = $member->allows('dispensing.view') ? DB::table('sales')->join('sale_items as si', 'si.sale_id', '=', 'sales.id')->join('prescriptions as rx', fn ($join) => $join->whereRaw('rx.id = COALESCE(si.prescription_id, sales.prescription_id)'))->where('sales.pharmacy_id', $pharmacy->id)->where('rx.pharmacy_id', $pharmacy->id)->where('rx.visit_id', $entry->id)->where('sales.status', 'PAID')->orderBy('sales.created_at')->distinct()->get(['sales.id', 'sales.number', 'sales.created_at', 'sales.handed_over_at']) : collect();
            }
        }
        $redemptions = collect();
        if ($request->attributes->get('membership')->allows('dispensing.view')) {
            $redemptions = DB::table('sales')->join('sale_items as si', 'si.sale_id', '=', 'sales.id')->join('prescriptions as rx', fn ($join) => $join->whereRaw('rx.id = COALESCE(si.prescription_id, sales.prescription_id)'))
                ->join('visits', 'visits.id', '=', 'rx.visit_id')->where('sales.pharmacy_id', $pharmacy->id)
                ->where('visits.patient_id', $patient)->where('sales.status', 'PAID')
                ->select('sales.id', 'sales.number', 'sales.created_at', 'sales.total', 'rx.number as rx_number')->distinct()
                ->orderByDesc('sales.created_at')->paginate(10, ['*'], 'redemptions_page')->withQueryString();
        }
        return view('patients.show', ['patient' => $record, 'visits' => $visits, 'redemptions' => $redemptions, 'timeline' => $timeline]);
    }

    public function followUp(Request $request, Pharmacy $pharmacy, AccessManager $manager): View
    {
        $today = now($pharmacy->timezone ?: 'Asia/Jakarta')->toDateString();
        $query = DB::table('prescriptions as rx')->join('visits', 'visits.id', '=', 'rx.visit_id')
            ->leftJoin('prescription_follow_ups as followup', function ($join) use ($pharmacy) {
                $join->on('followup.prescription_id', '=', 'rx.id')->where('followup.pharmacy_id', '=', $pharmacy->id);
            })->where('rx.pharmacy_id', $pharmacy->id)->where(fn ($q) => $q->where('rx.status', 'FINAL')->orWhereNotNull('rx.ready_at'))
            ->where('rx.dispensing_status', '!=', 'COMPLETE')->whereNotNull('rx.valid_until')->where('rx.valid_until', '<', $today);
        $patients = (clone $query)->select('visits.patient_id', 'visits.patient_name', 'visits.medical_record_number')
            ->groupBy('visits.patient_id', 'visits.patient_name', 'visits.medical_record_number')
            ->orderBy('visits.patient_name')->orderBy('visits.patient_id')->paginate(15)->withQueryString();
        $records = (clone $query)->whereIn('visits.patient_id', $patients->pluck('patient_id'))
            ->select('rx.id', 'rx.number', 'rx.valid_until', 'visits.patient_id', 'visits.doctor_name', 'followup.status as follow_up_status', 'followup.note_encrypted', 'followup.updated_at as follow_up_at')
            ->orderBy('rx.valid_until')->orderBy('rx.id')->get()->groupBy('patient_id');
        foreach ($records as $rows) {
            foreach ($rows as $row) {
                $row->follow_up_note = $row->note_encrypted ? Crypt::decryptString($row->note_encrypted) : null;
                unset($row->note_encrypted);
            }
        }
        $manager->audit($pharmacy, $request->user()->id, 'patient.follow_up_viewed', $pharmacy->id);
        return view('patients.follow-up', compact('patients', 'records'));
    }

    public function updateFollowUp(Request $request, Pharmacy $pharmacy, string $prescription, AccessManager $manager): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['PENDING', 'CONTACTED', 'DONE'])], 'note' => 'required|string|min:5|max:1000']);
        $manager->locked($pharmacy, function () use ($request, $pharmacy, $prescription, $manager, $data) {
            $manager->actor($pharmacy, $request->user()->id, 'dispensing.prepare');
            $rx = DB::table('prescriptions')->where('pharmacy_id', $pharmacy->id)->where('id', $prescription)->lockForUpdate()->first();
            abort_unless($rx && ($rx->status === 'FINAL' || $rx->ready_at) && $rx->dispensing_status !== 'COMPLETE' && $rx->valid_until && $rx->valid_until < now($pharmacy->timezone ?: 'Asia/Jakarta')->toDateString(), 422);
            $previous = DB::table('prescription_follow_ups')->where('pharmacy_id', $pharmacy->id)->where('prescription_id', $prescription)->first();
            DB::table('prescription_follow_ups')->updateOrInsert(['prescription_id' => $prescription], [
                'pharmacy_id' => $pharmacy->id, 'status' => $data['status'], 'note_encrypted' => Crypt::encryptString($data['note']),
                'updated_by' => $request->user()->id, 'created_at' => $previous->created_at ?? now(), 'updated_at' => now(),
            ]);
            $manager->audit($pharmacy, $request->user()->id, 'patient.follow_up_updated', $prescription, ['previous_status' => $previous->status ?? 'PENDING', 'status' => $data['status']]);
        });
        return redirect()->route('patients.follow-up', $pharmacy)->with('status', 'Tindak lanjut disimpan. Status selesai adalah pernyataan petugas, bukan konfirmasi otomatis dari sistem.');
    }

    private function validatePatient(Request $request, Pharmacy $pharmacy, bool $update): array
    {
        return $request->validate(['agreement' => $update ? 'prohibited' : 'nullable|file|mimes:pdf,jpg,jpeg,png|extensions:pdf,jpg,jpeg,png|max:5120',
            'request_key' => $update ? 'nullable|uuid' : 'required|uuid', 'revision' => $update ? 'required|integer|min:1' : 'nullable',
            'name' => 'required|string|max:150', 'birth_date' => 'required|date_format:Y-m-d|before_or_equal:'.now($pharmacy->timezone ?: 'Asia/Jakarta')->toDateString(),
            'sex' => ['required', Rule::in(array_keys(self::SEXES))], 'phone' => 'nullable|string|max:30', 'address' => 'nullable|string|max:2000',
            'emergency_name' => 'nullable|string|max:150', 'emergency_phone' => 'nullable|string|max:30', 'emergency_relationship' => 'nullable|string|max:80',
            'nik' => ['sometimes', 'nullable', 'string', 'regex:/^[0-9]{16}$/D'], 'allergies' => 'sometimes|nullable|string|max:2000', 'notes' => 'sometimes|nullable|string|max:2000']);
    }

    public function store(Request $request, Pharmacy $pharmacy, ClinicalMaster $master): RedirectResponse
    {
        $id = $master->savePatient($pharmacy, $request->user()->id, $this->validatePatient($request, $pharmacy, false));

        return redirect()->route('patients.show', [$pharmacy, $id])->with('status', 'Pasien didaftarkan. Nomor rekam medis dibuat otomatis.');
    }

    public function agreement(Request $request, Pharmacy $pharmacy, string $patient, AccessManager $manager)
    {
        $record = ClinicalMaster::patients($pharmacy)->where('id', $patient)->first();
        abort_unless($record && $record->agreement_path && Storage::disk('local')->exists($record->agreement_path), 404);
        $manager->audit($pharmacy, $request->user()->id, 'patient.agreement_viewed', $patient);

        return Storage::disk('local')->response($record->agreement_path, 'surat-perjanjian.'.pathinfo($record->agreement_path, PATHINFO_EXTENSION), [
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    public function update(Request $request, Pharmacy $pharmacy, string $patient, ClinicalMaster $master): RedirectResponse
    {
        $master->savePatient($pharmacy, $request->user()->id, $this->validatePatient($request, $pharmacy, true), $patient);

        return redirect()->route('patients.show', [$pharmacy, $patient])->with('status', 'Data pasien diperbarui.');
    }

    public function status(Request $request, Pharmacy $pharmacy, string $patient, ClinicalMaster $master): RedirectResponse
    {
        $data = $request->validate(['revision' => 'required|integer|min:1', 'active' => 'required|boolean']);
        $master->status($pharmacy, $request->user()->id, 'patients', $patient, (int) $data['revision'], (bool) $data['active']);

        return redirect()->route('patients.show', [$pharmacy, $patient])->with('status', 'Status pasien diperbarui.');
    }
}
