<?php

namespace App\Services;

use App\Models\Pharmacy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ConsultationEvidence
{
    public const METHODS = ['in_person' => 'Tatap muka', 'whatsapp' => 'WhatsApp', 'video' => 'Video call', 'telephone' => 'Telepon', 'other' => 'Online lainnya'];

    public static function latest(Pharmacy $pharmacy, string $visit): ?object
    {
        return DB::table('consultation_evidence_versions')->join('users', 'users.id', '=', 'consultation_evidence_versions.created_by')
            ->where('pharmacy_id', $pharmacy->id)->where('visit_id', $visit)->select('consultation_evidence_versions.*', 'users.name as creator_name')->orderByDesc('version')->first();
    }

    public static function validateComplete(array $data): void
    {
        if (($data['method'] ?? 'in_person') === 'in_person') {
            return;
        }
        $errors = [];
        if (empty($data['consulted_at'])) {
            $errors['consulted_at'] = 'Isi waktu konsultasi online sebelum finalisasi.';
        }
        if (empty($data['image_ids'])) {
            if ($data['method'] !== 'telephone') {
                $errors['screenshots'] = 'Unggah minimal satu screenshot bukti konsultasi online sebelum finalisasi.';
            } else {
                foreach (['exception_reason' => 'alasan tanpa screenshot', 'notes' => 'catatan konsultasi telepon'] as $field => $label) {
                    if (mb_strlen(trim($data[$field] ?? '')) < 5) {
                        $errors[$field] = 'Isi '.$label.' minimal 5 karakter.';
                    }
                }
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    public static function assertFinalizable(Pharmacy $pharmacy, string $visit): ?string
    {
        $version = self::latest($pharmacy, $visit);
        if (! $version) {
            return null; // Existing encounters without a recorded online method remain compatible.
        }
        $data = ExaminationRecords::payload($version);
        self::validateComplete($data);
        $files = DB::table('consultation_evidence_images')->where('pharmacy_id', $pharmacy->id)->where('visit_id', $visit)->whereIn('id', $data['image_ids'])->get();
        if ($files->count() !== count($data['image_ids'])) {
            throw ValidationException::withMessages(['screenshots' => 'Bukti konsultasi tidak sesuai dengan kunjungan.']);
        }
        foreach ($files as $file) {
            if (! Storage::disk('local')->exists($file->path)) {
                throw ValidationException::withMessages(['screenshots' => 'Berkas bukti konsultasi tidak tersedia. Unggah ulang sebelum finalisasi.']);
            }
            $bytes = Crypt::decryptString(Storage::disk('local')->get($file->path));
            if (! hash_equals($file->sha256, hash('sha256', $bytes))) {
                throw ValidationException::withMessages(['screenshots' => 'Berkas bukti konsultasi tidak valid. Unggah ulang.']);
            }
        }

        return $version->id;
    }

    public function save(Pharmacy $pharmacy, int $actor, string $visit, array $input, array $files): void
    {
        $data = Validator::make($input + ['screenshots' => $files], [
            'evidence_revision' => 'required|integer|min:0', 'method' => ['required', Rule::in(array_keys(self::METHODS))],
            'consulted_at' => 'nullable|date_format:Y-m-d\TH:i', 'notes' => 'nullable|string|max:3000',
            'exception_reason' => 'nullable|string|max:1000', 'change_reason' => 'nullable|string|max:1000',
            'retained_images' => 'nullable|array|max:10', 'retained_images.*' => 'required|uuid|distinct',
            'captions' => 'nullable|array|max:100', 'captions.*' => 'nullable|string|max:300',
            'new_captions' => 'nullable|array|max:6', 'new_captions.*' => 'nullable|string|max:300',
            'screenshots' => 'array|max:6', 'screenshots.*' => 'required|file|image|mimes:jpg,jpeg,png|max:5120|dimensions:max_width=6000,max_height=6000',
        ])->validate();
        if (! empty($data['consulted_at']) && Carbon::parse($data['consulted_at'], $pharmacy->timezone)->isFuture()) {
            throw ValidationException::withMessages(['consulted_at' => 'Waktu konsultasi tidak boleh di masa mendatang.']);
        }
        $paths = [];
        try {
            app(AccessManager::class)->locked($pharmacy, function () use ($pharmacy, $actor, $visit, $data, $files, &$paths) {
                $member = app(AccessManager::class)->actor($pharmacy, $actor, 'examinations.view');
                $record = ExaminationRecords::visibleVisits($pharmacy, $member)->where('visits.id', $visit)->lockForUpdate()->first();
                abort_unless($record, 404);
                $exam = ExaminationRecords::query($pharmacy)->where('visit_id', $visit)->first();
                abort_unless($member->allows($exam?->status === 'FINAL' ? 'examinations.amend' : 'examinations.update')
                    && ExaminationRecords::writingDoctor($pharmacy, $member, $record->doctor_id), 403);
                abort_unless($pharmacy->fresh()->status === 'ACTIVE' && in_array($record->status, ['IN_PROGRESS', 'COMPLETED'])
                    && ClinicalMaster::patients($pharmacy)->where('id', $record->patient_id)->where('active', true)->exists(), 422);
                $latest = self::latest($pharmacy, $visit);
                if ((int) ($latest?->version ?? 0) !== (int) $data['evidence_revision']) {
                    throw ValidationException::withMessages(['evidence_revision' => 'Bukti konsultasi sudah berubah. Muat ulang sebelum menyimpan.']);
                }
                if ($exam?->status === 'FINAL' && mb_strlen(trim($data['change_reason'] ?? '')) < 5) {
                    throw ValidationException::withMessages(['change_reason' => 'Isi alasan penambahan atau koreksi setelah SOAP final minimal 5 karakter.']);
                }
                $ids = $data['retained_images'] ?? [];
                $query = DB::table('consultation_evidence_images')->where('pharmacy_id', $pharmacy->id)->where('visit_id', $visit);
                if (count($ids) + count($files) > 10 || (clone $query)->whereIn('id', $ids)->count() !== count($ids)) {
                    throw ValidationException::withMessages(['screenshots' => 'Pilih maksimal 10 screenshot dari kunjungan ini.']);
                }
                if ((clone $query)->count() + count($files) > 100) {
                    throw ValidationException::withMessages(['screenshots' => 'Batas arsip 100 screenshot per kunjungan telah tercapai.']);
                }
                $captions = [];
                foreach ($ids as $id) {
                    $captions[$id] = $data['captions'][$id] ?? '';
                }
                foreach ($files as $index => $file) {
                    $id = (string) Str::uuid();
                    $path = 'consultation-evidence/'.$pharmacy->id.'/'.$id.'.enc';
                    $paths[] = $path;
                    $bytes = $file->getContent();
                    if (! Storage::disk('local')->put($path, Crypt::encryptString($bytes))) {
                        throw new \RuntimeException('Screenshot belum dapat disimpan. Coba kembali.');
                    }
                    DB::table('consultation_evidence_images')->insert(['id' => $id, 'pharmacy_id' => $pharmacy->id, 'visit_id' => $visit,
                        'path' => $path, 'mime' => $file->getMimeType(), 'sha256' => hash('sha256', $bytes), 'uploaded_by' => $actor, 'created_at' => now()]);
                    $ids[] = $id;
                    $captions[$id] = $data['new_captions'][$index] ?? '';
                }
                $payload = array_intersect_key($data, array_flip(['method', 'consulted_at', 'notes', 'exception_reason', 'change_reason']));
                $payload += ['timezone' => $pharmacy->timezone, 'image_ids' => $ids, 'captions' => $captions,
                    'doctor_name' => $record->doctor_name, 'patient_name' => $record->patient_name];
                if ($exam?->status === 'FINAL') {
                    self::validateComplete($payload);
                }
                $id = (string) Str::uuid();
                DB::table('consultation_evidence_versions')->insert(['id' => $id, 'pharmacy_id' => $pharmacy->id, 'visit_id' => $visit,
                    'version' => ($latest?->version ?? 0) + 1, 'payload_encrypted' => Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR)), 'created_by' => $actor, 'created_at' => now()]);
                app(AccessManager::class)->audit($pharmacy, $actor, 'consultation.evidence_saved', $visit, ['version_id' => $id, 'image_count' => count($ids)]);
            });
        } catch (\Throwable $exception) {
            foreach ($paths as $path) {
                Storage::disk('local')->delete($path);
            }
            throw $exception;
        }
    }
}
