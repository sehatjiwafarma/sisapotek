<?php

namespace App\Services;

use App\Models\Pharmacy;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PharmacyMaster
{
    public function __construct(private AccessManager $access) {}

    public function authorize(Pharmacy $pharmacy, User $user, string $permission): void
    {
        if ($user->is_system_admin) {
            return;
        }
        $member = $pharmacy->memberships()->with('roles')->where('user_id', $user->id)->where('active', true)->first();
        abort_unless($member?->allows($permission), 403);
    }

    public function create(User $user, array $data): Pharmacy
    {
        return DB::transaction(function () use ($user, $data) {
            $this->uniqueCode($data['code']);

            return $this->access->provision($user->id, $data);
        });
    }

    public function uniqueCode(string $code, ?Pharmacy $pharmacy = null): void
    {
        abort_if(Pharmacy::where('code', $code)->when($pharmacy, fn ($q) => $q->where('id', '!=', $pharmacy->id))->exists(), 409, 'Kode apotek sudah digunakan.');
    }

    public function save(Pharmacy $pharmacy, User $user, array $data): Pharmacy
    {
        return $this->access->locked($pharmacy, function () use ($pharmacy, $user, $data) {
            $pharmacy->refresh();
            $this->authorize($pharmacy, $user, 'pharmacy.update');
            $this->uniqueCode($data['code'], $pharmacy);
            if ($pharmacy->has_transactions && $data['code'] !== $pharmacy->code) {
                throw ValidationException::withMessages(['code' => 'Kode tidak dapat diubah setelah transaksi pertama.']);
            }
            $before = $pharmacy->only(array_keys($data));
            $pharmacy->fill($data)->save();
            if ($pharmacy->status === 'ACTIVE') {
                $this->ensureComplete($pharmacy);
            }
            $this->access->audit($pharmacy, $user->id, 'pharmacy.updated', "pharmacy:$pharmacy->id", ['before' => $before, 'after' => $pharmacy->only(array_keys($data))]);

            return $pharmacy;
        });
    }

    public function saveLicense(Pharmacy $pharmacy, User $user, array $input): void
    {
        $data = Validator::make($input, [
            'nib' => ['nullable', 'string', 'max:30'], 'license_number' => ['nullable', 'string', 'max:100'],
            'issued_on' => ['nullable', 'date_format:Y-m-d'], 'has_expiry' => ['required', 'boolean'],
            'expires_on' => ['nullable', 'required_if:has_expiry,1', 'date_format:Y-m-d', Rule::when(filled($input['issued_on'] ?? null), 'after_or_equal:issued_on')],
            'npwp' => ['nullable', 'regex:/^(?:[0-9]{15,16}|[0-9]{2}\.[0-9]{3}\.[0-9]{3}\.[0-9]-[0-9]{3}\.[0-9]{3})$/'],
            'issuer' => ['nullable', 'string', 'max:150'], 'document_status' => ['required', Rule::in(['Recorded', 'Verified', 'Expired'])],
        ])->validate();
        $this->access->locked($pharmacy, function () use ($pharmacy, $user, $data) {
            $this->authorize($pharmacy, $user, 'pharmacy.update');
            $before = $pharmacy->license->toArray();
            $pharmacy->license->update($data);
            if ($pharmacy->status === 'ACTIVE') {
                $this->ensureComplete($pharmacy);
            }
            $this->access->audit($pharmacy, $user->id, 'pharmacy.license_updated', 'license:'.$pharmacy->license->id, ['before' => $before, 'after' => $data]);
        });
    }

    public function saveSettings(Pharmacy $pharmacy, User $user, array $input): void
    {
        $data = Validator::make($input, [
            'timezone' => ['required', Rule::in(['Asia/Jakarta', 'Asia/Makassar', 'Asia/Jayapura'])], 'currency' => ['required', Rule::in(['IDR'])],
            'stock_issue_method' => ['required', Rule::in(['FEFO', 'FIFO'])], 'valuation_method' => ['required', Rule::in(['MOVING_AVERAGE', 'FIFO'])],
            'allow_negative_stock' => ['required', 'boolean'], 'track_batch' => ['required', 'boolean'], 'track_expiry' => ['required', 'boolean'],
            'expiry_reminder_days' => ['required', 'integer', 'between:0,3650'], 'minimum_stock_notification' => ['required', 'boolean'],
            'soap_reminder_hours' => ['sometimes', 'required', 'integer', 'between:1,720'],
            'receipt_header' => ['nullable', 'string', 'max:2000'], 'receipt_footer' => ['nullable', 'string', 'max:2000'],
        ])->validate();
        $this->access->locked($pharmacy, function () use ($pharmacy, $user, $data) {
            $pharmacy->refresh();
            $this->authorize($pharmacy, $user, 'pharmacy.update');
            $before = $pharmacy->settings->toArray();
            foreach (['stock_issue_method', 'valuation_method', 'track_batch', 'track_expiry', 'timezone', 'currency'] as $field) {
                if ($pharmacy->has_transactions && $data[$field] != $before[$field]) {
                    throw ValidationException::withMessages([$field => 'Konfigurasi ini terkunci setelah transaksi berjalan.']);
                }
            }
            $pharmacy->settings->update($data);
            $this->access->audit($pharmacy, $user->id, 'pharmacy.settings_updated', 'settings:'.$pharmacy->settings->id, ['before' => $before, 'after' => $data]);
        });
    }

    public function saveHours(Pharmacy $pharmacy, User $user, array $input): void
    {
        $data = Validator::make($input, [
            'hours' => ['required', 'array', 'min:7', 'max:35'], 'hours.*.day' => ['required', 'integer', 'between:0,6'],
            'hours.*.is_open' => ['required', 'boolean'], 'hours.*.opens_at' => ['nullable', 'date_format:H:i'], 'hours.*.closes_at' => ['nullable', 'date_format:H:i'],
            'hours.*.overnight' => ['required', 'boolean'], 'hours.*.note' => ['nullable', 'string', 'max:200'],
        ])->validate();
        $rows = [];
        $intervals = [];
        $days = [];
        foreach ($data['hours'] as $i => $row) {
            $day = (int) $row['day'];
            $row['day'] = $day;
            $row['is_open'] = (bool) $row['is_open'];
            $row['overnight'] = (bool) $row['overnight'];
            $days[$day] = true;
            $row['start_minute'] = null;
            $row['end_minute'] = null;
            if ($row['is_open']) {
                if (empty($row['opens_at']) || empty($row['closes_at'])) {
                    throw ValidationException::withMessages(["hours.$i" => 'Jam buka dan tutup wajib diisi.']);
                }
                $start = $this->minute($row['opens_at']);
                $end = $this->minute($row['closes_at']);
                if ((! $row['overnight'] && $start >= $end) || ($row['overnight'] && $end > $start)) {
                    throw ValidationException::withMessages(["hours.$i" => 'Periksa urutan jam dan pilihan melewati tengah malam.']);
                }
                $row['start_minute'] = $day * 1440 + $start;
                $row['end_minute'] = $day * 1440 + $end + ($row['overnight'] ? 1440 : 0);
                foreach ($intervals as [$a,$b]) {
                    foreach ([-10080, 0, 10080] as $shift) {
                        if ($row['start_minute'] < $b + $shift && $row['end_minute'] > $a + $shift) {
                            throw ValidationException::withMessages(["hours.$i" => 'Sesi jam operasional bertabrakan, termasuk interval lintas hari.']);
                        }
                    }
                }
                $intervals[] = [$row['start_minute'], $row['end_minute']];
            } else {
                $row['opens_at'] = null;
                $row['closes_at'] = null;
                $row['overnight'] = false;
            }
            $rows[] = $row;
        }
        if (count($days) !== 7) {
            throw ValidationException::withMessages(['hours' => 'Semua tujuh hari wajib dicatat, termasuk hari tutup.']);
        }
        foreach ($rows as $row) {
            if (! $row['is_open'] && count(array_filter($rows, fn ($other) => $other['day'] === $row['day'])) > 1) {
                throw ValidationException::withMessages(['hours' => 'Hari tutup tidak boleh digabung dengan sesi lain pada hari yang sama.']);
            }
        }
        $this->access->locked($pharmacy, function () use ($pharmacy, $user, $rows) {
            $this->authorizeEither($pharmacy, $user, ['pharmacy.update', 'pharmacy.update_limited']);
            $before = $pharmacy->hours()->get()->toArray();
            $pharmacy->hours()->delete();
            $pharmacy->hours()->createMany($rows);
            $this->access->audit($pharmacy, $user->id, 'pharmacy.hours_updated', "pharmacy:$pharmacy->id", ['before' => $before, 'after' => $rows]);
        });
    }

    private function minute(string $time): int
    {
        [$h,$m] = array_map('intval', explode(':', $time));

        return $h * 60 + $m;
    }

    public function authorizeEither(Pharmacy $pharmacy, User $user, array $permissions): void
    {
        if ($user->is_system_admin) {
            return;
        }
        $member = $pharmacy->memberships()->with('roles')->where('user_id', $user->id)->where('active', true)->first();
        abort_unless($member && collect($permissions)->contains(fn ($permission) => $member->allows($permission)), 403);
    }

    public function personnel(Pharmacy $pharmacy, User $user, array $input, ?string $id = null): void
    {
        $data = Validator::make($input, ['name' => ['required', 'string', 'max:150'], 'phone' => ['nullable', 'string', 'max:20'],
            'str_number' => ['nullable', 'string', 'max:100'], 'sip_number' => ['nullable', 'string', 'max:100'], 'sip_expires_on' => ['nullable', 'date_format:Y-m-d'], 'active' => ['required', 'boolean']])->validate();
        $this->access->locked($pharmacy, function () use ($pharmacy, $user, $data, $id) {
            $this->authorize($pharmacy, $user, 'pharmacy.personnel');
            $person = $id ? $pharmacy->personnel()->findOrFail($id) : $pharmacy->personnel()->make();
            $before = $person->exists ? $person->toArray() : null;
            $person->fill($data)->save();
            if ($pharmacy->status === 'ACTIVE') {
                $this->ensureComplete($pharmacy);
            }
            $this->access->audit($pharmacy, $user->id, 'pharmacy.personnel_saved', 'personnel:'.$person->id, ['before' => $before, 'after' => $data]);
        });
    }

    public function deletePersonnel(Pharmacy $pharmacy, User $user, string $id): void
    {
        $this->access->locked($pharmacy, function () use ($pharmacy, $user, $id) {
            $this->authorize($pharmacy, $user, 'pharmacy.personnel');
            $person = $pharmacy->personnel()->findOrFail($id);
            if ($pharmacy->assignments()->where('personnel_id', $id)->exists()) {
                throw ValidationException::withMessages(['personnel' => 'Apoteker sudah memiliki riwayat penugasan dan tidak dapat dihapus. Ubah status data menjadi Nonaktif jika tidak digunakan lagi.']);
            }
            $before = $person->toArray();
            $person->delete();
            $this->access->audit($pharmacy, $user->id, 'pharmacy.personnel_deleted', 'personnel:'.$id, ['before' => $before]);
        });
    }

    public function assign(Pharmacy $pharmacy, User $user, array $input): void
    {
        $data = Validator::make($input, ['personnel_id' => ['required', 'uuid'], 'starts_on' => ['required', 'date_format:Y-m-d'], 'ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on']])->validate();
        $this->access->locked($pharmacy, function () use ($pharmacy, $user, $data) {
            $this->authorize($pharmacy, $user, 'pharmacy.personnel');
            $person = $pharmacy->personnel()->where('active', true)->findOrFail($data['personnel_id']);
            if (! $person->str_number || ! $person->sip_number || ! $person->sip_expires_on) {
                throw ValidationException::withMessages(['personnel_id' => 'Lengkapi STR, SIP, dan masa berlaku SIP pada master personel.']);
            }
            $end = $data['ends_on'] ?? '9999-12-31';
            $overlap = $pharmacy->assignments()->where('starts_on', '<=', $end)->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $data['starts_on']))->exists();
            if ($overlap) {
                throw ValidationException::withMessages(['starts_on' => 'Penugasan APJ bertabrakan. Akhiri penugasan sebelumnya terlebih dahulu.']);
            }
            $assignment = $pharmacy->assignments()->create($data + ['role' => 'APJ', 'credential_snapshot' => $person->only('name', 'str_number', 'sip_number', 'sip_expires_on')]);
            $this->access->audit($pharmacy, $user->id, 'pharmacy.apj_assigned', 'assignment:'.$assignment->id, ['after' => $assignment->toArray()]);
        });
    }

    public function endAssignment(Pharmacy $pharmacy, User $user, string $id, array $input): void
    {
        $data = Validator::make($input, ['ends_on' => ['required', 'date_format:Y-m-d']])->validate();
        $this->access->locked($pharmacy, function () use ($pharmacy, $user, $id, $data) {
            $this->authorize($pharmacy, $user, 'pharmacy.personnel');
            $assignment = $pharmacy->assignments()->findOrFail($id);
            abort_if($assignment->ends_on, 409, 'Penugasan telah diakhiri.');
            if ($data['ends_on'] < $assignment->starts_on->toDateString()) {
                throw ValidationException::withMessages(['ends_on' => 'Tanggal akhir tidak boleh sebelum tanggal mulai.']);
            }
            $before = $assignment->toArray();
            $assignment->update($data);
            if ($pharmacy->status === 'ACTIVE') {
                $this->ensureComplete($pharmacy);
            }
            $this->access->audit($pharmacy, $user->id, 'pharmacy.apj_ended', 'assignment:'.$id, ['before' => $before, 'after' => $data]);
        });
    }

    public function missing(Pharmacy $pharmacy): array
    {
        $pharmacy->load(['license', 'settings']);
        $missing = [];
        foreach (['code' => 'Kode apotek', 'name' => 'Nama apotek', 'phone' => 'Telepon', 'address' => 'Alamat', 'province_code' => 'Provinsi', 'city_code' => 'Kota', 'district_code' => 'Kecamatan', 'village_code' => 'Kelurahan', 'owner_name' => 'Nama pemilik', 'owner_phone' => 'Telepon pemilik'] as $field => $label) {
            if (blank($pharmacy->$field)) {
                $missing[] = $label.' belum diisi.';
            }
        }
        if ($pharmacy->ownership_type === 'Legal Entity' && (! $pharmacy->business_name || ! $pharmacy->legal_form)) {
            $missing[] = 'Badan usaha dan bentuk hukum belum lengkap.';
        }
        $today = now($pharmacy->settings?->timezone ?? 'Asia/Jakarta')->toDateString();
        $license = $pharmacy->license;
        foreach (['nib' => 'NIB', 'license_number' => 'Nomor izin apotek', 'npwp' => 'NPWP'] as $field => $label) {
            $policy = ['nib' => 'require_nib', 'license_number' => 'require_license', 'npwp' => 'require_npwp'][$field];
            if (config('pharmacy.activation.'.$policy) && blank($license?->$field)) {
                $missing[] = $label.' belum diisi.';
            }
        }
        if ($license?->has_expiry && (! $license->expires_on || $license->expires_on->toDateString() < $today)) {
            $missing[] = 'Izin apotek belum memiliki masa berlaku yang valid.';
        }
        if ($license?->document_status === 'Expired') {
            $missing[] = 'Dokumen izin ditandai kedaluwarsa.';
        }
        if (config('pharmacy.activation.require_license_document') && ! $pharmacy->documents()->where('type', 'license')->where(fn ($q) => $q->whereNull('expires_on')->orWhere('expires_on', '>=', $today))->get()->contains(fn ($document) => Storage::disk('local')->exists($document->path))) {
            $missing[] = 'Dokumen izin yang masih berlaku belum diunggah atau file tidak tersedia.';
        }
        if (config('pharmacy.activation.require_apj')) {
            $assignment = $pharmacy->assignments()->with('personnel')->where('starts_on', '<=', $today)->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $today))->first();
            if (! $assignment || ! $assignment->personnel?->active || ! $assignment->personnel->str_number || ! $assignment->personnel->sip_number || ! $assignment->personnel->sip_expires_on || $assignment->personnel->sip_expires_on->toDateString() < $today) {
                $missing[] = 'APJ aktif dengan STR dan SIP yang masih berlaku belum tersedia.';
            }
        }
        if (! $pharmacy->settings) {
            $missing[] = 'Konfigurasi dasar belum tersedia.';
        }
        if ($pharmacy->hours()->distinct()->count('day') !== 7) {
            $missing[] = 'Jam operasional tujuh hari belum dicatat, termasuk hari tutup.';
        }

        return $missing;
    }

    private function ensureComplete(Pharmacy $pharmacy): void
    {
        if ($missing = $this->missing($pharmacy)) {
            throw ValidationException::withMessages(['activation' => $missing]);
        }
    }

    public function activate(Pharmacy $pharmacy, User $user): void
    {
        $this->authorize($pharmacy, $user, 'pharmacy.activate');
        $this->access->locked($pharmacy, function () use ($pharmacy, $user) {
            $this->authorize($pharmacy, $user, 'pharmacy.activate');
            $pharmacy->refresh();
            abort_if($pharmacy->status === 'ACTIVE', 409, 'Apotek sudah aktif.');
            $this->ensureComplete($pharmacy);
            $before = $pharmacy->status;
            $pharmacy->forceFill(['status' => 'ACTIVE', 'activated_by' => $user->id, 'activated_at' => now(), 'deactivated_at' => null, 'deactivation_reason' => null])->save();
            $this->access->audit($pharmacy, $user->id, 'pharmacy.activated', "pharmacy:$pharmacy->id", ['before' => $before, 'after' => 'ACTIVE']);
        });
    }

    public function deactivate(Pharmacy $pharmacy, User $user, array $input): void
    {
        $this->authorize($pharmacy, $user, 'pharmacy.deactivate');
        $data = Validator::make($input, ['reason' => ['required', 'string', 'min:5', 'max:2000']])->validate();
        $this->access->locked($pharmacy, function () use ($pharmacy, $user, $data) {
            $this->authorize($pharmacy, $user, 'pharmacy.deactivate');
            $pharmacy->refresh();
            abort_unless($pharmacy->status === 'ACTIVE', 409, 'Hanya apotek aktif yang dapat dinonaktifkan.');
            $pharmacy->forceFill(['status' => 'INACTIVE', 'deactivated_at' => now(), 'deactivation_reason' => $data['reason']])->save();
            $this->access->audit($pharmacy, $user->id, 'pharmacy.deactivated', "pharmacy:$pharmacy->id", ['before' => 'ACTIVE', 'after' => 'INACTIVE', 'reason' => $data['reason']]);
        });
    }

    public function upload(Pharmacy $pharmacy, User $user, UploadedFile $file, array $input): void
    {
        $this->authorize($pharmacy, $user, 'pharmacy.documents_upload');
        $data = Validator::make($input + ['file' => $file], ['type' => ['required', Rule::in(['license', 'nib', 'npwp', 'sip', 'other'])], 'name' => ['required', 'string', 'max:150'],
            'expires_on' => ['nullable', 'date_format:Y-m-d'], 'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:'.config('pharmacy.document_max_kb')]])->validate();
        $path = $file->store('pharmacies/'.$pharmacy->id.'/documents', 'local');
        abort_unless($path, 500, 'Penyimpanan dokumen gagal.');
        try {
            $this->access->locked($pharmacy, function () use ($pharmacy, $user, $file, $data, $path) {
                $this->authorize($pharmacy, $user, 'pharmacy.documents_upload');
                $document = $pharmacy->documents()->create(['type' => $data['type'], 'name' => $data['name'], 'expires_on' => $data['expires_on'] ?? null, 'path' => $path, 'mime' => $file->getMimeType(), 'size' => $file->getSize(), 'uploaded_by' => $user->id]);
                $this->access->audit($pharmacy, $user->id, 'pharmacy.document_uploaded', 'document:'.$document->id, ['name' => $document->name, 'type' => $document->type]);
            });
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }
    }

    public function deleteDocument(Pharmacy $pharmacy, User $user, string $id): void
    {
        $path = $this->access->locked($pharmacy, function () use ($pharmacy, $user, $id) {
            $this->authorize($pharmacy, $user, 'pharmacy.documents_delete');
            $document = $pharmacy->documents()->findOrFail($id);
            $path = $document->path;
            $this->access->audit($pharmacy, $user->id, 'pharmacy.document_deleted', 'document:'.$id, ['name' => $document->name, 'type' => $document->type]);
            $document->delete();
            if ($pharmacy->status === 'ACTIVE') {
                $this->ensureComplete($pharmacy);
            }

            return $path;
        });
        Storage::disk('local')->delete($path);
    }
}
