<?php

namespace App\Services;

use App\Models\Membership;
use App\Models\Pharmacy;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ExaminationRecords
{
    public const TEXTS = ['subjective' => 'Anamnesis / subjektif', 'objective' => 'Temuan objektif', 'assessment' => 'Diagnosis / assessment', 'plan' => 'Rencana / tindakan', 'followup' => 'Catatan tindak lanjut'];

    public const VITALS = ['systolic' => ['Sistolik', 'mmHg', 1, 400], 'diastolic' => ['Diastolik', 'mmHg', 1, 300], 'pulse' => ['Nadi', 'kali/menit', 1, 400], 'temperature' => ['Suhu', '°C', 20, 50], 'weight' => ['Berat badan', 'kg', 0.1, 700], 'height' => ['Tinggi badan', 'cm', 10, 300]];

    public function __construct(private AccessManager $access) {}

    public static function query(Pharmacy $pharmacy): Builder
    {
        return DB::table('examinations')->where('pharmacy_id', $pharmacy->id);
    }

    public static function doctor(Pharmacy $pharmacy, int $user): ?object
    {
        return ClinicalMaster::doctors($pharmacy)->where('user_id', $user)->where('active', true)->first();
    }

    public static function writingDoctor(Pharmacy $pharmacy, Membership $member, string $doctorId): ?object
    {
        $query = ClinicalMaster::doctors($pharmacy)->where('id', $doctorId)->where('active', true);
        if (! $member->isOwner()) {
            $query->where('user_id', $member->user_id);
        }

        return $query->first();
    }

    /** Read own encounters, plus finalized history for patients currently under this doctor's care. */
    public static function visibleVisits(Pharmacy $pharmacy, Membership $member): Builder
    {
        $query = DB::table('visits')->where('visits.pharmacy_id', $pharmacy->id);
        if (! $member->allows('examinations.view')) {
            return $query->whereRaw('1 = 0');
        }
        if ($member->allows('examinations.view_all')) {
            return $query;
        }
        $doctor = self::doctor($pharmacy, $member->user_id);
        if (! $doctor) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function ($scope) use ($pharmacy, $doctor) {
            $scope->where('visits.doctor_id', $doctor->id)->orWhere(function ($history) use ($pharmacy, $doctor) {
                $history->whereIn('visits.patient_id', DB::table('visits as treating')->where('treating.pharmacy_id', $pharmacy->id)->where('treating.doctor_id', $doctor->id)->where('treating.status', 'IN_PROGRESS')->select('treating.patient_id'))
                    ->whereExists(fn ($final) => $final->selectRaw('1')->from('examinations as historical')->whereColumn('historical.visit_id', 'visits.id')->where('historical.pharmacy_id', $pharmacy->id));
            });
        });
    }

    public static function payload(object $exam): array
    {
        return json_decode(Crypt::decryptString($exam->payload_encrypted), true, 512, JSON_THROW_ON_ERROR);
    }

    public static function validatePayload(array $input, bool $final): array
    {
        $mode = $input['documentation_mode'] ?? 'FORM';
        $rules = ['urine_test' => 'nullable|string|max:2000', 'documentation_mode' => ['sometimes', Rule::in(['FORM', 'IMAGE'])], 'image_notes' => 'nullable|string|max:5000', 'image_ids' => 'sometimes|array|max:10', 'image_ids.*' => 'required|uuid'];
        foreach (self::TEXTS as $key => $label) {
            $required = $final && $mode === 'FORM' && in_array($key, ['subjective', 'assessment', 'plan'], true);
            $rules[$key] = ($required ? 'required' : 'nullable').'|string|max:10000';
        }
        foreach (self::VITALS as $key => [$label, $unit, $min, $max]) {
            $rules[$key] = ['nullable', in_array($key, ['systolic', 'diastolic', 'pulse'], true) ? 'integer' : 'numeric', 'min:'.$min, 'max:'.$max];
        }
        $validator = Validator::make($input, $rules, [], self::TEXTS + array_map(fn ($vital) => $vital[0], self::VITALS));
        $validator->after(function ($validator) use ($input) {
            $systolic = $input['systolic'] ?? null;
            $diastolic = $input['diastolic'] ?? null;
            if (($systolic !== null && $systolic !== '') xor ($diastolic !== null && $diastolic !== '')) {
                $validator->errors()->add('systolic', 'Isi sistolik dan diastolik bersama, atau kosongkan keduanya.');
            }
        });
        $data = $validator->validate();
        if ($final && $mode === 'IMAGE' && empty($data['image_ids'])) {
            throw ValidationException::withMessages(['images' => 'Unggah minimal satu gambar SOAP sebelum finalisasi.']);
        }

        return array_replace(array_fill_keys(array_merge(array_keys(self::TEXTS), array_keys(self::VITALS), ['urine_test']), null), ['documentation_mode' => $mode, 'image_ids' => [], 'image_notes' => null], $data);
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['record' => $message]);
    }

    private function writable(Pharmacy $pharmacy, int $actor, string $visit, string $permission): array
    {
        $member = $this->access->actor($pharmacy, $actor, $permission);
        abort_unless($member->allows('examinations.view'), 403);
        abort_unless($pharmacy->fresh()->status === 'ACTIVE', 422, 'Apotek harus aktif untuk mencatat pemeriksaan.');
        $record = VisitRegistration::query($pharmacy)->where('id', $visit)->lockForUpdate()->first();
        abort_unless($record, 404);
        $doctor = self::writingDoctor($pharmacy, $member, $record->doctor_id);
        abort_unless($doctor, 403, 'Penulisan memerlukan dokter penanggung jawab aktif dan akses Owner atau akun dokter yang ditautkan.');
        if (! ClinicalMaster::patients($pharmacy)->where('id', $record->patient_id)->where('active', true)->exists()) {
            $this->fail('Pasien harus aktif untuk mencatat pemeriksaan.');
        }

        return [$record, $doctor];
    }

    public function draft(Pharmacy $pharmacy, int $actor, string $visit, int $revision, array $input, array $files = []): void
    {
        $data = self::validatePayload($input, false);
        $images = new ExaminationImages;
        try {
            $this->access->locked($pharmacy, function () use ($pharmacy, $actor, $visit, $revision, $data, $files, $images) {
                [$record] = $this->writable($pharmacy, $actor, $visit, 'examinations.update');
                $data = array_replace($data, VisitRegistration::intake($record));
                $exam = self::query($pharmacy)->where('visit_id', $visit)->lockForUpdate()->first();
                if (! in_array($record->status, ['IN_PROGRESS', 'COMPLETED'], true) || ($exam && $exam->status !== 'DRAFT')) {
                    $this->fail('SOAP hanya dapat dilengkapi setelah layanan dimulai dan sebelum catatan final.');
                }
                if ($revision !== (int) ($exam?->revision ?? 0)) {
                    $this->fail('Draft telah berubah. Muat ulang agar perubahan pengguna lain tidak tertimpa.');
                }
                $data = $images->store($pharmacy, $actor, $visit, $data, $files);
                $values = ['payload_encrypted' => Crypt::encryptString(json_encode($data, JSON_THROW_ON_ERROR)), 'updated_by' => $actor, 'updated_at' => now(), 'revision' => $revision + 1];
                if ($exam) {
                    self::query($pharmacy)->where('id', $exam->id)->update($values);
                } else {
                    DB::table('examinations')->insert($values + ['id' => (string) Str::uuid(), 'pharmacy_id' => $pharmacy->id, 'visit_id' => $visit, 'status' => 'DRAFT', 'current_version' => 0, 'examined_at' => now(), 'created_by' => $actor, 'created_at' => now()]);
                }
                $this->access->audit($pharmacy, $actor, 'examination.draft_saved', $visit, ['revision' => $revision + 1, 'doctor_id' => $record->doctor_id]);
            });
        } finally {
            $images->cleanup();
        }
    }

    public function finalize(Pharmacy $pharmacy, int $actor, string $visit, int $revision): void
    {
        $this->access->locked($pharmacy, function () use ($pharmacy, $actor, $visit, $revision) {
            [$record, $doctor] = $this->writable($pharmacy, $actor, $visit, 'examinations.finalize');
            $exam = self::query($pharmacy)->where('visit_id', $visit)->lockForUpdate()->first();
            if (! $exam || $exam->status !== 'DRAFT' || ! in_array($record->status, ['IN_PROGRESS', 'COMPLETED'], true) || (int) $exam->revision !== $revision) {
                $this->fail('Draft tidak tersedia, telah berubah, atau sudah final. Muat ulang sebelum melanjutkan.');
            }
            $data = self::validatePayload(array_replace(self::payload($exam), VisitRegistration::intake($record)), true);
            $this->version($pharmacy, $actor, $record, $doctor, $exam, $data, null);
            self::query($pharmacy)->where('id', $exam->id)->update(['status' => 'FINAL', 'current_version' => 1, 'revision' => $revision + 1, 'finalized_at' => now(), 'updated_by' => $actor, 'updated_at' => now()]);
            $this->access->audit($pharmacy, $actor, 'examination.finalized', $visit, ['version' => 1]);
        });
    }

    private function version(Pharmacy $pharmacy, int $actor, object $visit, object $doctor, object $exam, array $data, ?string $reason): void
    {
        $data['consultation_version_id'] = ConsultationEvidence::assertFinalizable($pharmacy, $visit->id);
        ExaminationImages::assertAvailable($pharmacy, $visit->id, $data);
        $allergies = ClinicalMaster::patients($pharmacy)->where('id', $visit->patient_id)->value('allergies_encrypted');
        $versionId = (string) Str::uuid();
        DB::table('examination_versions')->insert(['id' => $versionId, 'examination_id' => $exam->id, 'version' => $exam->current_version + 1,
            'kind' => $reason === null ? 'FINAL' : 'AMEND', 'payload_encrypted' => Crypt::encryptString(json_encode($data, JSON_THROW_ON_ERROR)),
            'allergies_encrypted' => $allergies, 'reason_encrypted' => $reason !== null ? Crypt::encryptString($reason) : null,
            'doctor_name' => $doctor->name, 'doctor_code' => $doctor->code, 'sip_number' => $doctor->sip_number, 'str_number' => $doctor->str_number, 'recorded_by' => $actor, 'created_at' => now()]);
        foreach ($data['image_ids'] ?? [] as $imageId) {
            DB::table('examination_version_images')->insert(['version_id' => $versionId, 'image_id' => $imageId]);
        }
    }

    public function amend(Pharmacy $pharmacy, int $actor, string $visit, int $revision, array $input, string $reason, array $files = []): void
    {
        $data = self::validatePayload($input, false);
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 2000) {
            throw ValidationException::withMessages(['reason' => 'Isi alasan amendemen, maksimal 2.000 karakter.']);
        }
        $images = new ExaminationImages;
        try {
            $this->access->locked($pharmacy, function () use ($pharmacy, $actor, $visit, $revision, $data, $reason, $files, $images) {
                [$record, $doctor] = $this->writable($pharmacy, $actor, $visit, 'examinations.amend');
                $data = array_replace($data, VisitRegistration::intake($record));
                $exam = self::query($pharmacy)->where('visit_id', $visit)->lockForUpdate()->first();
                if (! $exam || $exam->status !== 'FINAL' || ! in_array($record->status, ['IN_PROGRESS', 'COMPLETED'], true) || (int) $exam->revision !== $revision) {
                    $this->fail('Amendemen hanya berlaku untuk hasil final terkini. Muat ulang sebelum melanjutkan.');
                }
                $data = self::validatePayload($images->store($pharmacy, $actor, $visit, $data, $files), true);
                $this->version($pharmacy, $actor, $record, $doctor, $exam, $data, $reason);
                self::query($pharmacy)->where('id', $exam->id)->update(['current_version' => $exam->current_version + 1, 'revision' => $revision + 1, 'updated_by' => $actor, 'updated_at' => now()]);
                $this->access->audit($pharmacy, $actor, 'examination.amended', $visit, ['version' => $exam->current_version + 1]);
            });
        } finally {
            $images->cleanup();
        }
    }
}
