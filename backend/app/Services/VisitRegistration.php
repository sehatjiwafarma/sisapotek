<?php

namespace App\Services;

use App\Models\Pharmacy;
use App\Support\Decimal;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class VisitRegistration
{
    public const STATUSES = ['REGISTERED' => 'Menunggu', 'IN_PROGRESS' => 'Dalam layanan', 'COMPLETED' => 'Selesai', 'CANCELLED' => 'Dibatalkan'];

    public const TYPES = ['new' => 'Kunjungan baru', 'followup' => 'Kontrol / lanjutan'];

    public function __construct(private AccessManager $access) {}

    public static function query(Pharmacy $pharmacy): Builder
    {
        return DB::table('visits')->where('pharmacy_id', $pharmacy->id);
    }

    public static function intakeKeys(): array
    {
        return [...array_keys(ExaminationRecords::VITALS), 'urine_test'];
    }

    public static function services(?object $visit): array
    {
        return ($visit->services ?? null) ? json_decode($visit->services, true, 512, JSON_THROW_ON_ERROR) : [];
    }

    public static function intake(object $visit): array
    {
        return ($visit->intake_encrypted ?? null) ? json_decode(Crypt::decryptString($visit->intake_encrypted), true, 512, JSON_THROW_ON_ERROR) : [];
    }

    private function fail(string $message, string $field = 'record'): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    private function active(Pharmacy $pharmacy): void
    {
        abort_unless($pharmacy->fresh()->status === 'ACTIVE', 422, 'Apotek harus aktif untuk layanan kunjungan.');
    }

    public function save(Pharmacy $pharmacy, int $actor, array $data, ?string $id = null): string
    {
        $intake = isset($data['intake_present']) || array_intersect(self::intakeKeys(), array_keys($data))
            ? array_intersect_key(ExaminationRecords::validatePayload($data, false), array_flip(self::intakeKeys())) : null;

        return $this->access->locked($pharmacy, function () use ($pharmacy, $actor, $data, $id, $intake) {
            $this->access->actor($pharmacy, $actor, $id ? 'visits.update' : 'visits.create');
            $this->active($pharmacy);
            $record = $id ? self::query($pharmacy)->where('id', $id)->lockForUpdate()->first() : null;
            abort_if($id && ! $record, 404);
            if ($record) {
                $this->editable($record, (int) $data['revision']);
                if ((array_key_exists('services', $data) || isset($data['services_present'])) && DB::table('service_bills')->where('pharmacy_id', $pharmacy->id)->where('visit_id', $id)->whereIn('status', ['DRAFT', 'PAID'])->exists()) {
                    $before = collect(self::services($record))->mapWithKeys(fn ($row) => [$row['tariff_id'] => (int) $row['quantity']])->sortKeys()->all();
                    $after = collect($data['services'] ?? [])->filter(fn ($row) => (int) $row['quantity'] > 0)->mapWithKeys(fn ($row) => [$row['tariff_id'] => (int) $row['quantity']])->sortKeys()->all();
                    if ($before !== $after) {
                        $this->fail('Layanan tidak dapat diubah selama ada tagihan aktif atau lunas.');
                    }
                }
            } elseif ($existing = self::query($pharmacy)->where('request_key', $data['request_key'])->value('id')) {
                return $existing;
            }
            $patientId = $record?->patient_id ?? $data['patient_id'];
            $patient = ClinicalMaster::patients($pharmacy)->where('id', $patientId)->where('active', true)->first();
            $doctor = ClinicalMaster::doctors($pharmacy)->where('id', $data['doctor_id'])->where('active', true)->first();
            if (! $patient) {
                $this->fail('Pilih pasien aktif dari apotek ini.', 'patient_id');
            }
            if (! $doctor) {
                $this->fail('Pilih dokter aktif dari apotek ini.', 'doctor_id');
            }
            $today = now($pharmacy->fresh()->settings?->timezone ?: 'Asia/Jakarta')->toDateString();
            if (! $record && self::query($pharmacy)->where('visit_date', $today)->where('open_patient_key', $patientId)->exists()) {
                $this->fail('Pasien masih memiliki kunjungan aktif hari ini. Buka kunjungan tersebut sebelum mendaftar lagi.', 'patient_id');
            }
            $values = ['doctor_id' => $doctor->id, 'doctor_name' => $doctor->name, 'doctor_code' => $doctor->code,
                'type' => $data['type'], 'complaint_encrypted' => Crypt::encryptString($data['complaint']), 'updated_at' => now()];
            if ($intake !== null) {
                $values['intake_encrypted'] = Crypt::encryptString(json_encode($intake, JSON_THROW_ON_ERROR));
            }
            if (array_key_exists('services', $data) || isset($data['services_present'])) {
                $selected = Validator::make($data, ['services' => 'required|array|min:1|max:100', 'services.*' => 'array:tariff_id,quantity', 'services.*.tariff_id' => 'required|uuid|distinct', 'services.*.quantity' => 'required|integer|min:0|max:1000'])->validate();
                $services = [];
                foreach ($selected['services'] as $row) {
                    if ((int) $row['quantity'] === 0) {
                        continue;
                    }
                    $previous = collect(self::services($record))->firstWhere('tariff_id', $row['tariff_id']);
                    $tariff = $previous ? (object) ['id' => $previous['tariff_id'], 'name' => $previous['name'], 'price' => $previous['unit_price']]
                        : DB::table('service_tariffs')->where('pharmacy_id', $pharmacy->id)->where('id', $row['tariff_id'])->where('active', true)->first();
                    if (! $tariff) {
                        $this->fail('Pilih layanan aktif dari master layanan apotek ini.', 'services');
                    }
                    $services[] = ['tariff_id' => $tariff->id, 'name' => $tariff->name, 'quantity' => (int) $row['quantity'], 'unit_price' => $tariff->price,
                        'total' => Decimal::format(Decimal::scaled($tariff->price, 2) * (int) $row['quantity'])];
                }
                if (! $services) {
                    $this->fail('Pilih minimal satu layanan dengan jumlah lebih dari nol.', 'services');
                }
                $values['services'] = json_encode($services, JSON_THROW_ON_ERROR);
            }
            if ($record) {
                self::query($pharmacy)->where('id', $id)->update($values + ['revision' => $record->revision + 1]);
            } else {
                // Serialize daily allocation using the pharmacy row lock; never recycle cancelled queues.
                $queue = (int) self::query($pharmacy)->where('visit_date', $today)->max('queue_number') + 1;
                $id = (string) Str::uuid();
                DB::table('visits')->insert($values + ['id' => $id, 'pharmacy_id' => $pharmacy->id, 'patient_id' => $patientId,
                    'request_key' => $data['request_key'], 'number' => 'KJ-'.str_replace('-', '', $today).'-'.str_pad((string) $queue, 4, '0', STR_PAD_LEFT),
                    'visit_date' => $today, 'registered_at' => now(), 'queue_number' => $queue, 'open_patient_key' => $patientId,
                    'patient_name' => $patient->name, 'medical_record_number' => $patient->medical_record_number,
                    'status' => 'REGISTERED', 'created_by' => $actor, 'revision' => 1, 'created_at' => now()]);
            }
            if (! $record && ! empty($services)) {
                $billId = (string) Str::uuid();
                $total = array_sum(array_map(fn ($line) => Decimal::scaled($line['total'], 2), $services));
                DB::table('service_bills')->insert([
                    'id' => $billId, 'pharmacy_id' => $pharmacy->id, 'visit_id' => $id,
                    'open_visit_key' => $id, 'request_key' => (string) Str::uuid(),
                    'number' => CashManagement::number('JL', $pharmacy),
                    'services_total' => Decimal::format($total), 'medicine_total' => Decimal::format(0),
                    'total' => Decimal::format($total), 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now(),
                ]);
                foreach ($services as $line) {
                    DB::table('service_bill_items')->insert([
                        'id' => (string) Str::uuid(), 'service_bill_id' => $billId,
                        'service_tariff_id' => $line['tariff_id'], 'name' => $line['name'],
                        'quantity' => $line['quantity'], 'unit_price' => $line['unit_price'], 'total' => $line['total'],
                    ]);
                }
                $this->access->audit($pharmacy, $actor, 'service_bill.created', $billId, ['visit_id' => $id]);
            }
            $this->access->audit($pharmacy, $actor, $record ? 'visit.updated' : 'visit.registered', $id, ['patient_id' => $patientId, 'doctor_id' => $doctor->id]);

            return $id;
        });
    }

    private function editable(object $record, int $revision): void
    {
        if ((int) $record->revision !== $revision) {
            $this->fail('Kunjungan sudah berubah. Muat ulang halaman sebelum melanjutkan.');
        }
        if ($record->status !== 'REGISTERED') {
            $this->fail('Tindakan hanya tersedia untuk kunjungan yang masih menunggu.');
        }
    }

    public function transition(Pharmacy $pharmacy, int $actor, string $id, array $data): void
    {
        $this->access->locked($pharmacy, function () use ($pharmacy, $actor, $id, $data) {
            $cancel = $data['action'] === 'cancel';
            $this->access->actor($pharmacy, $actor, $cancel ? 'visits.cancel' : 'visits.update');
            $record = self::query($pharmacy)->where('id', $id)->lockForUpdate()->first();
            abort_unless($record, 404);
            $this->editable($record, (int) $data['revision']);
            $bill = DB::table('service_bills')->where('pharmacy_id', $pharmacy->id)->where('visit_id', $id)->whereIn('status', ['DRAFT', 'PAID'])->first();
            if ($cancel && $bill) {
                $this->fail($bill->status === 'PAID' ? 'Kunjungan dengan tagihan lunas tidak dapat dibatalkan melalui alur ini.' : 'Batalkan draft tagihan layanan sebelum membatalkan kunjungan.');
            }
            if (! $cancel) {
                $this->active($pharmacy);
                if (! $bill || $bill->status !== 'PAID') {
                    $this->fail('Tagihan layanan harus lunas sebelum pemeriksaan dimulai.');
                }
                if (! ClinicalMaster::doctors($pharmacy)->where('id', $record->doctor_id)->where('active', true)->exists()
                    || ! ClinicalMaster::patients($pharmacy)->where('id', $record->patient_id)->where('active', true)->exists()) {
                    $this->fail('Pasien dan dokter harus aktif sebelum layanan dimulai.');
                }
            }
            $values = $cancel ? ['status' => 'CANCELLED', 'open_patient_key' => null, 'cancelled_at' => now(), 'cancellation_encrypted' => Crypt::encryptString($data['reason'])]
                : ['status' => 'IN_PROGRESS', 'started_at' => now()];
            self::query($pharmacy)->where('id', $id)->update($values + ['revision' => $record->revision + 1, 'updated_at' => now()]);
            $this->access->audit($pharmacy, $actor, $cancel ? 'visit.cancelled' : 'visit.started', $id);
        });
    }

    public function complete(Pharmacy $pharmacy, int $actor, string $id, int $revision, ?string $reason): void
    {
        $this->access->locked($pharmacy, function () use ($pharmacy, $actor, $id, $revision, $reason) {
            $this->access->actor($pharmacy, $actor, 'visits.complete');
            $this->active($pharmacy);
            $visit = self::query($pharmacy)->where('id', $id)->lockForUpdate()->first();
            abort_unless($visit, 404);
            if ($visit->status !== 'IN_PROGRESS' || (int) $visit->revision !== $revision) {
                $this->fail('Hanya kunjungan dalam layanan dengan data terkini yang dapat diselesaikan.');
            }
            $pendingSoap = ! ExaminationRecords::query($pharmacy)->where('visit_id', $id)->exists();
            $pendingPrescription = DB::table('prescriptions')->where('pharmacy_id', $pharmacy->id)->where('visit_id', $id)->where('status', 'DRAFT')->whereNull('ready_at')->exists();
            $reason = trim($reason ?? '');
            if (($pendingSoap || $pendingPrescription) && $reason === '') {
                $this->fail('Isi alasan penyelesaian layanan saat SOAP atau resep masih menyusul.', 'pending_reason');
            }
            if (mb_strlen($reason) > 2000) {
                $this->fail('Alasan maksimal 2.000 karakter.', 'pending_reason');
            }
            self::query($pharmacy)->where('id', $id)->update(['status' => 'COMPLETED', 'completed_at' => now(), 'open_patient_key' => null,
                'completion_note_encrypted' => $reason !== '' ? Crypt::encryptString($reason) : null, 'revision' => $revision + 1, 'updated_at' => now()]);
            $this->access->audit($pharmacy, $actor, 'visit.completed', $id, ['soap_pending' => $pendingSoap, 'prescription_pending' => $pendingPrescription]);
        });
    }
}
