<?php

namespace App\Http\Controllers;

use App\Models\Pharmacy;
use App\Services\AccessManager;
use App\Services\ClinicalMaster;
use App\Services\ExaminationRecords;
use App\Services\PrescriptionPrint;
use App\Services\PrescriptionRecords;
use App\Services\StockLots;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PrescriptionController extends Controller
{
    private function context(Request $request, Pharmacy $pharmacy, string $visit): array
    {
        $member = $request->attributes->get('membership');
        $record = PrescriptionRecords::visibleVisits($pharmacy, $member)->where('visits.id', $visit)->first();
        abort_unless($record, 404);
        abort_unless(in_array($record->status, ['IN_PROGRESS', 'COMPLETED'], true), 422, 'Mulai layanan sebelum membuat resep.');
        $request->validate(['prescription' => 'nullable|string|max:36']);
        $prescription = PrescriptionRecords::selected($pharmacy, $visit, $request->input('prescription'));
        $payload = $prescription ? ExaminationRecords::payload($prescription) : ['items' => [], 'notes' => null];
        if ($prescription) {
            unset($prescription->payload_encrypted);
        }
        $canWrite = $pharmacy->status === 'ACTIVE' && ExaminationRecords::writingDoctor($pharmacy, $member, $record->doctor_id)
            && ClinicalMaster::patients($pharmacy)->where('id', $record->patient_id)->where('active', true)->exists();
        $allergies = null;
        if ($member->allows('patients.view_clinical')) {
            $encrypted = ClinicalMaster::patients($pharmacy)->where('id', $record->patient_id)->value('allergies_encrypted');
            $allergies = $encrypted ? Crypt::decryptString($encrypted) : null;
        } else {
            unset($payload['allergies']);
        }
        unset($record->complaint_encrypted,$record->cancellation_encrypted,$record->completion_note_encrypted,$record->request_key);

        return ['visit' => $record, 'prescription' => $prescription, 'payload' => $payload, 'canWrite' => $canWrite, 'allergies' => $allergies,
            'prescriptions' => PrescriptionRecords::query($pharmacy)->where('visit_id', $visit)->orderBy('created_at')->orderBy('id')->get(['id', 'number', 'status', 'ready_at']),
            'rxRequestKey' => (string) Str::uuid(),
            'canReadExamination' => ExaminationRecords::visibleVisits($pharmacy, $member)->where('visits.id', $visit)->exists(),
            'soapStatus' => ExaminationRecords::query($pharmacy)->where('visit_id', $visit)->value('status')];
    }

    public function show(Request $request, Pharmacy $pharmacy, string $visit, AccessManager $access)
    {
        $context = $this->context($request, $pharmacy, $visit);
        $access->audit($pharmacy, $request->user()->id, 'prescription.viewed', $visit);
        if ($context['canReadExamination']) {
            return app(ExaminationController::class)->show($request, $pharmacy, $visit, $access);
        }

        return view('prescriptions.show', $context);
    }

    public function edit(Request $request, Pharmacy $pharmacy, string $visit, AccessManager $access)
    {
        $context = $this->context($request, $pharmacy, $visit);
        abort_unless($context['canWrite'], 403);
        abort_unless(! $context['prescription'] || $context['prescription']->status === 'DRAFT', 422);
        abort_unless($request->attributes->get('membership')->allows($context['prescription'] ? 'prescriptions.update' : 'prescriptions.create'), 403);
        $access->audit($pharmacy, $request->user()->id, 'prescription.edit_viewed', $visit);
        if ($context['canReadExamination']) {
            return app(ExaminationController::class)->show($request, $pharmacy, $visit, $access);
        }

        $products = $pharmacy->products()->where('active', true)->where('requires_prescription', true)->select('id', 'name', 'sku', 'base_unit')->orderBy('name')->get();
        $available = app(StockLots::class)->available($pharmacy, $products->pluck('id')->all());

        return view('prescriptions.form', $context + ['products' => $products, 'availableStock' => $available]);
    }

    public function save(Request $request, Pharmacy $pharmacy, string $visit, PrescriptionRecords $records)
    {
        $data = $request->validate(['revision' => 'required|integer|min:0']);
        $id = $records->save($pharmacy, $request->user()->id, $visit, (int) $data['revision'], $request->all());

        return redirect()->route('prescriptions.show', [$pharmacy, $visit, 'prescription' => $request->has('prescription') ? $id : null])->with('status', 'Draft resep tersimpan.');
    }

    public function finalize(Request $request, Pharmacy $pharmacy, string $visit, PrescriptionRecords $records)
    {
        $data = $request->validate(['revision' => 'required|integer|min:1', 'prescription' => 'nullable|uuid']);
        $records->finalize($pharmacy, $request->user()->id, $visit, (int) $data['revision'], $data['prescription'] ?? null);

        return redirect()->route('prescriptions.show', [$pharmacy, $visit, 'prescription' => $data['prescription'] ?? null])->with('status', 'Resep difinalkan. SOAP dan status layanan tidak berubah; stok belum dipotong.');
    }

    public function print(Request $request, Pharmacy $pharmacy, string $visit, AccessManager $access)
    {
        $context = $this->context($request, $pharmacy, $visit);
        abort_unless($context['prescription'] && ($context['prescription']->status === 'FINAL' || $context['prescription']->ready_at), 422);
        $access->audit($pharmacy, $request->user()->id, 'prescription.printed', $visit);
        $context['qrCode'] = PrescriptionPrint::qr(PrescriptionPrint::scanText($context['prescription'], $context['payload']));

        return view('prescriptions.print', $context);
    }

    public function signature(Request $request, Pharmacy $pharmacy, string $visit, AccessManager $access)
    {
        $context = $this->context($request, $pharmacy, $visit);
        abort_unless($context['prescription'] && ($context['prescription']->status === 'FINAL' || $context['prescription']->ready_at) && $context['prescription']->signature_id, 404);
        $signature = DB::table('doctor_signatures')->where('id', $context['prescription']->signature_id)->first();
        abort_unless($signature && Storage::disk('local')->exists($signature->path), 404);
        $access->audit($pharmacy, $request->user()->id, 'prescription.signature_viewed', $visit);

        return response()->file(Storage::disk('local')->path($signature->path), ['Content-Type' => $signature->mime]);
    }
}
