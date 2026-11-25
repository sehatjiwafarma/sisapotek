<?php

namespace App\Services;

use App\Models\Pharmacy;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ClinicalMaster
{
    public function __construct(private AccessManager $access) {}

    public static function patients(Pharmacy $pharmacy): Builder
    {
        return DB::table('patients')->where('pharmacy_id', $pharmacy->id);
    }

    public static function doctors(Pharmacy $pharmacy): Builder
    {
        return DB::table('doctors')->where('pharmacy_id', $pharmacy->id);
    }

    private function fail(string $message, string $field = 'record'): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    private function revision(object $record, int $revision): void
    {
        if ($record->revision !== $revision) {
            $this->fail('Data sudah diperbarui pengguna lain. Muat ulang sebelum menyimpan.');
        }
    }

    public function savePatient(Pharmacy $pharmacy, int $actor, array $data, ?string $id = null): string
    {
        $agreementPath = null;
        try {
            return $this->access->locked($pharmacy, function () use ($pharmacy, $actor, $data, $id, &$agreementPath) {
                $membership = $this->access->actor($pharmacy, $actor, $id ? 'patients.update' : 'patients.create');
                if (isset($data['agreement'])) {
                    abort_unless($membership->allows('patients.view_sensitive') && ! $id, 403);
                }
                foreach (['nik' => 'patients.view_sensitive', 'notes' => 'patients.view_sensitive', 'allergies' => 'patients.view_clinical'] as $field => $permission) {
                    if (array_key_exists($field, $data)) {
                        abort_unless($membership->allows($permission), 403);
                    }
                }
                $record = $id ? self::patients($pharmacy)->where('id', $id)->lockForUpdate()->first() : null;
                abort_if($id && ! $record, 404);
                if ($record) {
                    $this->revision($record, (int) $data['revision']);
                } else {
                    $existing = self::patients($pharmacy)->where('request_key', $data['request_key'])->value('id');
                    if ($existing) {
                        return $existing;
                    }
                }
                $values = array_intersect_key($data, array_flip(['name', 'birth_date', 'sex', 'phone', 'address', 'emergency_name', 'emergency_phone', 'emergency_relationship']));
                foreach (['nik', 'notes', 'allergies'] as $field) {
                    if (array_key_exists($field, $data)) {
                        $values[$field.'_encrypted'] = $data[$field] !== null && $data[$field] !== '' ? Crypt::encryptString($data[$field]) : null;
                    }
                }
                if (array_key_exists('nik', $data)) {
                    $values['nik_hash'] = $data['nik'] ? hash_hmac('sha256', $data['nik'], config('app.key')) : null;
                }
                $id ??= (string) Str::uuid();
                if (isset($data['agreement'])) {
                    $agreementPath = $data['agreement']->store('patient-agreements/'.$pharmacy->id.'/'.$id, 'local');
                    if (! $agreementPath) {
                        throw new \RuntimeException('Surat perjanjian gagal disimpan.');
                    }
                    $values['agreement_path'] = $agreementPath;
                }
                if ($record) {
                    self::patients($pharmacy)->where('id', $id)->update($values + ['revision' => $record->revision + 1, 'updated_at' => now()]);
                } else {
                    DB::table('patients')->insert($values + ['id' => $id, 'pharmacy_id' => $pharmacy->id, 'request_key' => $data['request_key'],
                        'medical_record_number' => 'MR-'.now($pharmacy->timezone ?: 'Asia/Jakarta')->format('Ymd').'-'.strtoupper(Str::random(8)),
                        'active' => true, 'revision' => 1, 'created_at' => now(), 'updated_at' => now()]);
                }
                $this->access->audit($pharmacy, $actor, $record ? 'patient.updated' : 'patient.created', $id, ['fields' => array_keys(array_diff_key($data, array_flip(['revision', 'request_key'])))]);

                return $id;
            });
        } catch (UniqueConstraintViolationException) {
            if ($agreementPath) {
                Storage::disk('local')->delete($agreementPath);
            }
            $this->fail('NIK sudah digunakan pasien lain di apotek ini. Periksa data sebelum membuat pasien baru.', 'nik');
        } catch (\Throwable $exception) {
            if ($agreementPath) {
                Storage::disk('local')->delete($agreementPath);
            }
            throw $exception;
        }
    }

    public function saveDoctor(Pharmacy $pharmacy, int $actor, array $data, ?string $id = null): string
    {
        return $this->access->locked($pharmacy, function () use ($pharmacy, $actor, $data, $id) {
            $this->access->actor($pharmacy, $actor, $id ? 'doctors.update' : 'doctors.create');
            $record = $id ? self::doctors($pharmacy)->where('id', $id)->lockForUpdate()->first() : null;
            abort_if($id && ! $record, 404);
            if ($record) {
                $this->revision($record, (int) $data['revision']);
            } else {
                $existing = self::doctors($pharmacy)->where('request_key', $data['request_key'])->value('id');
                if ($existing) {
                    return $existing;
                }
            }
            $values = array_intersect_key($data, array_flip(['name', 'sip_number', 'str_number', 'specialty', 'phone']));
            $id ??= (string) Str::uuid();
            if ($record) {
                $identityChanged = collect(['name', 'sip_number', 'str_number'])->contains(fn ($field) => array_key_exists($field, $values) && $values[$field] !== $record->$field);
                self::doctors($pharmacy)->where('id', $id)->update($values + ['revision' => $record->revision + 1, 'signing_revision' => $record->signing_revision + ($identityChanged ? 1 : 0), 'updated_at' => now()]);
            } else {
                DB::table('doctors')->insert($values + ['id' => $id, 'pharmacy_id' => $pharmacy->id, 'request_key' => $data['request_key'],
                    'code' => 'DR-'.strtoupper(Str::random(10)), 'active' => true, 'revision' => 1, 'signing_revision' => 1, 'created_at' => now(), 'updated_at' => now()]);
            }
            $this->access->audit($pharmacy, $actor, $record ? 'doctor.updated' : 'doctor.created', $id, ['fields' => array_keys($values)]);

            return $id;
        });
    }

    public function status(Pharmacy $pharmacy, int $actor, string $kind, string $id, int $revision, bool $active): void
    {
        $this->access->locked($pharmacy, function () use ($pharmacy, $actor, $kind, $id, $revision, $active) {
            abort_unless(in_array($kind, ['patients', 'doctors'], true), 404);
            $this->access->actor($pharmacy, $actor, $kind.'.deactivate');
            $query = DB::table($kind)->where('pharmacy_id', $pharmacy->id)->where('id', $id);
            $record = (clone $query)->lockForUpdate()->first();
            abort_unless($record, 404);
            $this->revision($record, $revision);
            $query->update(['active' => $active, 'revision' => $revision + 1, 'updated_at' => now()]);
            $this->access->audit($pharmacy, $actor, $kind === 'patients' ? 'patient.status_changed' : 'doctor.status_changed', $id, ['active' => $active]);
        });
    }

    public function linkDoctor(Pharmacy $pharmacy, int $actor, string $id, int $revision, ?int $userId): void
    {
        try {
            $this->access->locked($pharmacy, function () use ($pharmacy, $actor, $id, $revision, $userId) {
                $this->access->actor($pharmacy, $actor, 'doctors.link_account');
                $record = self::doctors($pharmacy)->where('id', $id)->lockForUpdate()->first();
                abort_unless($record, 404);
                $this->revision($record, $revision);
                if ($userId && ! $pharmacy->memberships()->where('user_id', $userId)->where('active', true)->exists()) {
                    $this->fail('Pilih akun dengan keanggotaan aktif di apotek ini.', 'user_id');
                }
                $changed = $record->user_id !== $userId;
                self::doctors($pharmacy)->where('id', $id)->update(['user_id' => $userId, 'revision' => $revision + 1, 'signing_revision' => $record->signing_revision + ($changed ? 1 : 0), 'updated_at' => now()]);
                $this->access->audit($pharmacy, $actor, 'doctor.account_linked', $id, ['user_id' => $userId]);
            });
        } catch (UniqueConstraintViolationException) {
            $this->fail('Akun sudah terhubung ke dokter lain di apotek ini.', 'user_id');
        }
    }

    public function signature(Pharmacy $pharmacy, int $actor, string $id, int $revision, UploadedFile $file, string $validFrom): void
    {
        $paths = [];
        try {
            $this->access->locked($pharmacy, function () use ($pharmacy, $actor, $id, $revision, $file, $validFrom, &$paths) {
                $member = $this->access->actor($pharmacy, $actor, 'doctors.signature_update');
                $doctor = self::doctors($pharmacy)->where('id', $id)->lockForUpdate()->first();
                abort_unless($doctor, 404);
                abort_unless($member->isOwner() || $doctor->user_id === $actor, 403, 'Unggah tanda tangan memerlukan Owner atau pemilik akun dokter yang ditautkan.');
                $this->revision($doctor, $revision);
                if (! $doctor->active) {
                    $this->fail('Aktifkan dokter sebelum mengunggah tanda tangan.');
                }
                $signatureId = (string) Str::uuid();
                $mime = $file->getMimeType();
                $path = $file->storeAs('doctors/'.$pharmacy->id.'/'.$id, $signatureId.($mime === 'image/png' ? '.png' : '.jpg'), 'local');
                if (! $path) {
                    $this->fail('Gambar gagal disimpan. Coba kembali.');
                }
                $paths[] = $path;
                $version = (int) DB::table('doctor_signatures')->where('doctor_id', $id)->max('version') + 1;
                DB::table('doctor_signatures')->insert(['id' => $signatureId, 'doctor_id' => $id, 'version' => $version, 'signing_revision' => $doctor->signing_revision,
                    'uploaded_by' => $actor, 'uploaded_as_owner' => $member->isOwner(), 'path' => $path, 'mime' => $mime, 'sha256' => hash_file('sha256', $file->getRealPath()), 'valid_from' => $validFrom, 'created_at' => now()]);
                self::doctors($pharmacy)->where('id', $id)->update(['revision' => $revision + 1, 'updated_at' => now()]);
                $this->access->audit($pharmacy, $actor, 'doctor.signature_uploaded', $id, ['signature_id' => $signatureId, 'version' => $version, 'valid_from' => $validFrom, 'uploaded_as_owner' => $member->isOwner()]);
            });
            foreach (array_slice($paths, 0, -1) as $unusedPath) {
                Storage::disk('local')->delete($unusedPath);
            }
        } catch (\Throwable $exception) {
            foreach ($paths as $path) {
                Storage::disk('local')->delete($path);
            }
            throw $exception;
        }
    }

    public static function currentSignature(Pharmacy $pharmacy, object $doctor): ?object
    {
        if (! $doctor->active) {
            return null;
        }

        $linkedActive = $doctor->user_id && $pharmacy->memberships()->where('user_id', $doctor->user_id)->where('active', true)->exists();

        return DB::table('doctor_signatures')->where('doctor_id', $doctor->id)->where(function ($query) use ($doctor, $linkedActive) {
            $query->where('uploaded_as_owner', true);
            if ($linkedActive) {
                $query->orWhere('uploaded_by', $doctor->user_id);
            }
        })->where('signing_revision', $doctor->signing_revision)
            ->where('valid_from', '<=', now($pharmacy->timezone ?: 'Asia/Jakarta')->toDateString())->orderByDesc('valid_from')->orderByDesc('version')->first();
    }
}
