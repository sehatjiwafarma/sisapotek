<?php

namespace App\Http\Controllers;

use App\Models\Pharmacy;
use App\Services\AccessManager;
use App\Services\ClinicalMaster;
use App\Services\ConsultationEvidence;
use App\Services\ExaminationRecords;
use App\Services\PrescriptionRecords;
use App\Services\SoapReminders;
use App\Services\StockLots;
use App\Services\VisitRegistration;
use App\Support\TableSort;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ExaminationController extends Controller
{
    public function index(Request $request, Pharmacy $pharmacy, AccessManager $access)
    {
        $filters = $request->validate(['q' => 'nullable|string|max:150', 'date' => 'nullable|date_format:Y-m-d', 'patient' => 'nullable|uuid', 'status' => ['nullable', Rule::in(['EMPTY', 'DRAFT', 'FINAL'])]]);
        $query = ExaminationRecords::visibleVisits($pharmacy, $request->attributes->get('membership'))->leftJoin('examinations', 'examinations.visit_id', '=', 'visits.id')->whereIn('visits.status', ['IN_PROGRESS', 'COMPLETED'])
            ->leftJoinSub(SoapReminders::firstPrescriptions($pharmacy), 'soap_rx', 'soap_rx.visit_id', '=', 'visits.id');
        if ($filters['date'] ?? null) {
            $query->where('visits.visit_date', $filters['date']);
        }
        if ($filters['patient'] ?? null) {
            $query->where('visits.patient_id', $filters['patient']);
        }
        if ($term = trim($filters['q'] ?? '')) {
            $query->where(function ($query) use ($term) {
                foreach (['number', 'patient_name', 'medical_record_number'] as $field) {
                    $query->orWhereRaw('LOWER(visits.'.$field.') LIKE ?', ['%'.mb_strtolower($term).'%']);
                }
            });
        }
        if (($filters['status'] ?? '') === 'EMPTY') {
            $query->whereNull('examinations.id');
        } elseif ($filters['status'] ?? null) {
            $query->where('examinations.status', $filters['status']);
        }
        TableSort::apply($query, ['number' => 'visits.number', 'date' => 'visits.registered_at', 'patient' => 'visits.patient_name', 'doctor' => 'visits.doctor_name', 'status' => fn ($query, $direction) => $query->orderByRaw("COALESCE(examinations.status, 'EMPTY') ".$direction)], 'date', 'desc');
        $access->audit($pharmacy, $request->user()->id, 'examination.list_viewed', 'examinations');

        return view('examinations.index', ['records' => $query->select('visits.id', 'visits.number', 'visits.patient_name', 'visits.medical_record_number', 'visits.doctor_name', 'visits.registered_at', 'examinations.status', 'examinations.current_version', 'soap_rx.first_prescription_at')->orderBy('visits.id')->paginate(15)->withQueryString()]);
    }

    private function context(Request $request, Pharmacy $pharmacy, string $visit): array
    {
        $member = $request->attributes->get('membership');
        $record = ExaminationRecords::visibleVisits($pharmacy, $member)->where('visits.id', $visit)->first();
        abort_unless($record, 404);
        abort_unless(in_array($record->status, ['IN_PROGRESS', 'COMPLETED'], true), 422, 'Mulai layanan kunjungan sebelum membuka pemeriksaan.');
        $exam = ExaminationRecords::query($pharmacy)->where('visit_id', $visit)->first();
        $doctor = ExaminationRecords::writingDoctor($pharmacy, $member, $record->doctor_id);
        $canWrite = $pharmacy->status === 'ACTIVE' && $doctor
            && ClinicalMaster::patients($pharmacy)->where('id', $record->patient_id)->where('active', true)->exists();
        $version = $exam && $exam->status === 'FINAL' ? DB::table('examination_versions')->where('examination_id', $exam->id)->where('version', $exam->current_version)->first() : null;
        $payload = $version ? ExaminationRecords::payload($version) : ($exam ? ExaminationRecords::payload($exam) : []);
        if (! $version) {
            $payload = array_replace($payload, VisitRegistration::intake($record));
        }
        $allergies = null;
        if ($member->allows('patients.view_clinical')) {
            $encrypted = ClinicalMaster::patients($pharmacy)->where('id', $record->patient_id)->value('allergies_encrypted');
            $allergies = $encrypted ? Crypt::decryptString($encrypted) : null;
        }
        $record->complaint = Crypt::decryptString($record->complaint_encrypted);
        unset($record->complaint_encrypted, $record->cancellation_encrypted, $record->completion_note_encrypted, $record->request_key);
        if ($exam) {
            unset($exam->payload_encrypted);
        }
        if ($version) {
            $version->allergies = $member->allows('patients.view_clinical') && $version->allergies_encrypted ? Crypt::decryptString($version->allergies_encrypted) : null;
            unset($version->payload_encrypted, $version->allergies_encrypted, $version->reason_encrypted);
        }

        return ['visit' => $record, 'exam' => $exam, 'payload' => $payload, 'canWrite' => $canWrite, 'allergies' => $allergies, 'version' => $version,
            'canReadPrescription' => PrescriptionRecords::visibleVisits($pharmacy, $member)->where('visits.id', $visit)->exists()];
    }

    public function show(Request $request, Pharmacy $pharmacy, string $visit, AccessManager $access)
    {
        $context = $this->context($request, $pharmacy, $visit);
        $versions = $context['exam'] ? DB::table('examination_versions')->where('examination_id', $context['exam']->id)
            ->select('id', 'version', 'kind', 'doctor_name', 'created_at')->orderByDesc('version')->paginate(10, ['*'], 'versions_page')->withQueryString() : null;
        $history = ExaminationRecords::visibleVisits($pharmacy, $request->attributes->get('membership'))->join('examinations', 'examinations.visit_id', '=', 'visits.id')
            ->where('visits.patient_id', $context['visit']->patient_id)->where('visits.id', '!=', $visit);
        TableSort::apply($history, ['number' => 'visits.number', 'date' => 'examinations.examined_at', 'doctor' => 'visits.doctor_name', 'status' => 'examinations.status'], 'date', 'desc', 'history_');
        $access->audit($pharmacy, $request->user()->id, 'examination.viewed', $visit);
        $member = $request->attributes->get('membership');
        $request->validate(['prescription' => 'nullable|string|max:36']);
        $prescription = $context['canReadPrescription'] ? PrescriptionRecords::selected($pharmacy, $visit, $request->input('prescription')) : null;
        $rxPayload = $prescription ? ExaminationRecords::payload($prescription) : ['items' => [], 'notes' => null];
        if (! $member->allows('patients.view_clinical')) {
            unset($rxPayload['allergies']);
        }
        $products = $pharmacy->products()->where('active', true)->where('requires_prescription', true)->select('id', 'name', 'sku', 'base_unit')->orderBy('name')->get();
        $context += ['prescription' => $prescription, 'rxPayload' => $rxPayload, 'products' => $products,
            'prescriptions' => $context['canReadPrescription'] ? PrescriptionRecords::query($pharmacy)->where('visit_id', $visit)->orderBy('created_at')->orderBy('id')->get(['id', 'number', 'status', 'ready_at']) : collect(),
            'rxRequestKey' => (string) Str::uuid(),
            'availableStock' => app(StockLots::class)->available($pharmacy, $products->pluck('id')->all()),
            'editSoap' => $context['canWrite'] && $member->allows('examinations.update') && (! $context['exam'] || $context['exam']->status === 'DRAFT'),
            'editRx' => $context['canWrite'] && $context['canReadPrescription'] && (! $prescription || $prescription->status === 'DRAFT') && $member->allows($prescription ? 'prescriptions.update' : 'prescriptions.create')];
        $context['evidenceRecord'] = ConsultationEvidence::latest($pharmacy, $visit);
        $context['evidenceData'] = $context['evidenceRecord'] ? ExaminationRecords::payload($context['evidenceRecord']) : [];
        $context['canEditEvidence'] = $context['canWrite'] && $member->allows($context['exam']?->status === 'FINAL' ? 'examinations.amend' : 'examinations.update');
        $context['evidenceHistory'] = DB::table('consultation_evidence_versions')->join('users', 'users.id', '=', 'consultation_evidence_versions.created_by')
            ->where('pharmacy_id', $pharmacy->id)->where('visit_id', $visit)->orderByDesc('version')
            ->paginate(10, ['consultation_evidence_versions.id', 'version', 'consultation_evidence_versions.created_at', 'users.name as creator_name'], 'evidence_page')->withQueryString();

        return view('examinations.encounter', $context + ['versions' => $versions,
            'history' => $history->select('visits.id', 'visits.number', 'visits.doctor_name', 'examinations.examined_at', 'examinations.status')->orderBy('visits.id')->paginate(10, ['*'], 'history_page')->withQueryString()]);
    }

    public function edit(Request $request, Pharmacy $pharmacy, string $visit, AccessManager $access)
    {
        $context = $this->context($request, $pharmacy, $visit);
        abort_unless($context['canWrite'], 403, 'Penulisan memerlukan akses Owner atau dokter penanggung jawab, serta dokter, pasien dan apotek aktif.');
        $amend = $request->routeIs('examinations.amend_form');
        abort_unless($amend ? $context['exam']?->status === 'FINAL' : in_array($context['visit']->status, ['IN_PROGRESS', 'COMPLETED'], true) && (! $context['exam'] || $context['exam']->status === 'DRAFT'), 422, 'Status pemeriksaan tidak mendukung tindakan ini.');
        $access->audit($pharmacy, $request->user()->id, $amend ? 'examination.amend_form_viewed' : 'examination.edit_viewed', $visit);
        if (! $amend) {
            return $this->show($request, $pharmacy, $visit, $access);
        }

        return view('examinations.form', $context + ['amend' => $amend]);
    }

    public function saveEncounter(Request $request, Pharmacy $pharmacy, string $visit, AccessManager $access, ExaminationRecords $soap, PrescriptionRecords $rx)
    {
        $context = $this->context($request, $pharmacy, $visit);
        abort_unless($context['canWrite'], 403);
        $meta = $request->validate(['soap_revision' => 'sometimes|required|integer|min:0', 'rx_revision' => 'sometimes|required|integer|min:0', 'images' => 'nullable|array|max:6']);
        abort_unless(array_key_exists('soap_revision', $meta) || array_key_exists('rx_revision', $meta), 422);
        $selected = $request->validate(['prescription' => 'nullable|string|max:36'])['prescription'] ?? null;
        $access->locked($pharmacy, function () use ($request, $pharmacy, $visit, $meta, $rx, $soap, &$selected) {
            if (array_key_exists('rx_revision', $meta)) {
                $items = $request->input('items', []);
                $hasItems = is_array($items) && collect($items)->contains(fn ($item) => is_array($item) && ! empty($item['product_id']));
                if ($hasItems || PrescriptionRecords::selected($pharmacy, $visit, $selected)) {
                    $selected = $rx->save($pharmacy, $request->user()->id, $visit, (int) $meta['rx_revision'], $request->all());
                }
            }
            if (array_key_exists('soap_revision', $meta)) {
                $soap->draft($pharmacy, $request->user()->id, $visit, (int) $meta['soap_revision'], $request->all(), $request->file('images', []));
            }
        });

        return redirect()->route('examinations.show', [$pharmacy, $visit, 'prescription' => $request->has('prescription') ? $selected : null])->with('status', 'Pemeriksaan dan resep disimpan.');
    }

    public function save(Request $request, Pharmacy $pharmacy, string $visit, ExaminationRecords $records)
    {
        $meta = $request->validate(['revision' => 'required|integer|min:0', 'images' => 'nullable|array|max:6']);
        $records->draft($pharmacy, $request->user()->id, $visit, (int) $meta['revision'], $request->all(), $request->file('images', []));

        return redirect()->route('examinations.show', [$pharmacy, $visit])->with('status', 'Draft pemeriksaan disimpan. Periksa kembali hasil sebelum finalisasi.');
    }

    public function finalize(Request $request, Pharmacy $pharmacy, string $visit, ExaminationRecords $records)
    {
        $data = $request->validate(['revision' => 'required|integer|min:1']);
        $records->finalize($pharmacy, $request->user()->id, $visit, (int) $data['revision']);

        return redirect()->route('examinations.show', [$pharmacy, $visit])->with('status', 'SOAP difinalkan. Status layanan kunjungan tidak berubah; koreksi catatan melalui amendemen.');
    }

    public function amend(Request $request, Pharmacy $pharmacy, string $visit, ExaminationRecords $records)
    {
        $data = $request->validate(['revision' => 'required|integer|min:1', 'reason' => 'required|string|max:2000', 'images' => 'nullable|array|max:6']);
        $records->amend($pharmacy, $request->user()->id, $visit, (int) $data['revision'], $request->all(), $data['reason'], $request->file('images', []));

        return redirect()->route('examinations.show', [$pharmacy, $visit])->with('status', 'Versi amendemen disimpan. Versi sebelumnya tetap tersedia.');
    }

    public function version(Request $request, Pharmacy $pharmacy, string $visit, string $version, AccessManager $access)
    {
        $context = $this->context($request, $pharmacy, $visit);
        abort_unless($context['exam'] && $context['exam']->status === 'FINAL', 404);
        $snapshot = DB::table('examination_versions')->where('examination_id', $context['exam']->id)->where('id', $version)->first();
        abort_unless($snapshot, 404);
        $context['payload'] = ExaminationRecords::payload($snapshot);
        $snapshot->reason = $snapshot->reason_encrypted ? Crypt::decryptString($snapshot->reason_encrypted) : null;
        $snapshot->allergies = $request->attributes->get('membership')->allows('patients.view_clinical') && $snapshot->allergies_encrypted ? Crypt::decryptString($snapshot->allergies_encrypted) : null;
        unset($snapshot->payload_encrypted, $snapshot->reason_encrypted, $snapshot->allergies_encrypted);
        $access->audit($pharmacy, $request->user()->id, 'examination.version_viewed', $visit, ['version' => $snapshot->version]);

        return view('examinations.version', ['visit' => $context['visit'], 'exam' => $context['exam'], 'payload' => $context['payload'], 'version' => $snapshot]);
    }

    public function image(Request $request, Pharmacy $pharmacy, string $visit, string $image, AccessManager $access)
    {
        $context = $this->context($request, $pharmacy, $visit);
        $file = DB::table('examination_images')->where('pharmacy_id', $pharmacy->id)->where('visit_id', $visit)->where('id', $image)->first();
        abort_unless($file, 404);
        $inDraft = in_array($image, $context['payload']['image_ids'] ?? [], true);
        $inVersion = $context['exam'] && DB::table('examination_version_images')->join('examination_versions', 'examination_versions.id', '=', 'examination_version_images.version_id')
            ->where('examination_versions.examination_id', $context['exam']->id)->where('image_id', $image)->exists();
        abort_unless($inDraft || $inVersion, 404);
        abort_unless(Storage::disk('local')->exists($file->path), 404);
        $bytes = Crypt::decryptString(Storage::disk('local')->get($file->path));
        abort_unless(hash_equals($file->sha256, hash('sha256', $bytes)), 404);
        $access->audit($pharmacy, $request->user()->id, 'examination.image_viewed', $visit, ['image_id' => $image]);

        return response($bytes, 200, ['Content-Type' => $file->mime, 'Content-Disposition' => 'inline; filename="soap-image.'.($file->mime === 'image/png' ? 'png' : 'jpg').'"']);
    }

    public function saveConsultation(Request $request, Pharmacy $pharmacy, string $visit, ConsultationEvidence $evidence)
    {
        $this->context($request, $pharmacy, $visit);
        $request->validate(['screenshots' => 'nullable|array|max:6']);
        $input = $request->all();
        if (array_key_exists('consultation_notes', $input)) {
            $input['notes'] = $input['consultation_notes'];
        }
        $evidence->save($pharmacy, $request->user()->id, $visit, $input, $request->file('screenshots', []));

        return redirect()->route('examinations.show', [$pharmacy, $visit])->with('status', 'Bukti konsultasi disimpan sebagai versi baru.');
    }

    public function consultationVersion(Request $request, Pharmacy $pharmacy, string $visit, string $evidence, AccessManager $access)
    {
        $context = $this->context($request, $pharmacy, $visit);
        $record = DB::table('consultation_evidence_versions')->join('users', 'users.id', '=', 'consultation_evidence_versions.created_by')
            ->where('pharmacy_id', $pharmacy->id)->where('visit_id', $visit)->where('consultation_evidence_versions.id', $evidence)
            ->select('consultation_evidence_versions.*', 'users.name as creator_name')->first();
        abort_unless($record, 404);
        $access->audit($pharmacy, $request->user()->id, 'consultation.version_viewed', $visit, ['version_id' => $evidence]);

        return view('examinations.consultation-version', $context + ['evidenceRecord' => $record, 'evidenceData' => ExaminationRecords::payload($record), 'canEditEvidence' => false]);
    }

    public function consultationImage(Request $request, Pharmacy $pharmacy, string $visit, string $image, AccessManager $access)
    {
        $this->context($request, $pharmacy, $visit);
        $file = DB::table('consultation_evidence_images')->where('pharmacy_id', $pharmacy->id)->where('visit_id', $visit)->where('id', $image)->first();
        abort_unless($file && Storage::disk('local')->exists($file->path), 404);
        $bytes = Crypt::decryptString(Storage::disk('local')->get($file->path));
        abort_unless(hash_equals($file->sha256, hash('sha256', $bytes)), 404);
        $access->audit($pharmacy, $request->user()->id, 'consultation.image_viewed', $visit, ['image_id' => $image]);

        return response($bytes, 200, ['Content-Type' => $file->mime, 'Content-Disposition' => 'inline; filename="bukti-konsultasi.'.($file->mime === 'image/png' ? 'png' : 'jpg').'"', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
