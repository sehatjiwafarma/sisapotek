<?php

namespace App\Services;

use App\Models\Membership;
use App\Models\Pharmacy;
use App\Support\Decimal;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PrescriptionRecords
{
    public function __construct(private AccessManager $access) {}

    public static function query(Pharmacy $pharmacy): Builder
    {
        return DB::table('prescriptions')->where('pharmacy_id', $pharmacy->id);
    }

    public const STATUS_SQL = "CASE WHEN COUNT(*) = 0 THEN NULL WHEN SUM(CASE WHEN status = 'DRAFT' THEN 1 ELSE 0 END) > 0 THEN 'DRAFT' ELSE 'FINAL' END";

    public static function selected(Pharmacy $pharmacy, string $visit, ?string $selector): ?object
    {
        if ($selector === 'new') {
            return null;
        }
        $query = self::query($pharmacy)->where('visit_id', $visit);
        if ($selector !== null && $selector !== '') {
            abort_unless(Str::isUuid($selector), 422, 'Pilih resep yang valid.');
            $record = $query->where('id', $selector)->first();
            abort_unless($record, 404);

            return $record;
        }

        return $query->orderBy('created_at')->orderBy('id')->first();
    }

    public static function visibleVisits(Pharmacy $pharmacy, Membership $member): Builder
    {
        $query = DB::table('visits')->where('visits.pharmacy_id', $pharmacy->id);
        if (! $member->allows('prescriptions.view')) {
            return $query->whereRaw('1 = 0');
        }
        if ($member->allows('prescriptions.view_all')) {
            return $query;
        }
        $doctor = ExaminationRecords::doctor($pharmacy, $member->user_id);

        return $doctor ? $query->where('visits.doctor_id', $doctor->id) : $query->whereRaw('1 = 0');
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['record' => $message]);
    }

    private function writable(Pharmacy $pharmacy, int $actor, string $visit, string $permission): array
    {
        $member = $this->access->actor($pharmacy, $actor, $permission);
        abort_unless($member->allows('prescriptions.view'), 403);
        abort_unless($pharmacy->fresh()->status === 'ACTIVE', 422);
        $record = VisitRegistration::query($pharmacy)->where('id', $visit)->lockForUpdate()->first();
        abort_unless($record, 404);
        $doctor = ExaminationRecords::writingDoctor($pharmacy, $member, $record->doctor_id);
        abort_unless($doctor, 403);
        if (! in_array($record->status, ['IN_PROGRESS', 'COMPLETED'], true)) {
            $this->fail('Mulai layanan kunjungan sebelum membuat resep.');
        }
        if (! ClinicalMaster::patients($pharmacy)->where('id', $record->patient_id)->where('active', true)->exists()) {
            $this->fail('Pasien harus aktif.');
        }

        return [$record, $doctor];
    }

    public static function items(Pharmacy $pharmacy, array $input, bool $final): array
    {
        $rules = ['items' => 'required|array|min:1|max:30', 'items.*.product_id' => 'required|uuid|distinct', 'items.*.quantity' => 'required|numeric|min:0.0001|max:100000', 'notes' => 'nullable|string|max:2000'];
        foreach (['dose' => 150, 'frequency' => 150, 'route' => 100, 'duration' => 100, 'instructions' => 1000] as $key => $max) {
            $rules['items.*.'.$key] = ($final && in_array($key, ['dose', 'frequency', 'instructions'], true) ? 'required' : 'nullable').'|string|max:'.$max;
        }
        $data = Validator::make($input, $rules)->validate();
        $items = [];
        foreach ($data['items'] as $index => $item) {
            $product = $pharmacy->products()->whereKey($item['product_id'])->where('active', true)->where('requires_prescription', true)->first();
            if (! $product) {
                throw ValidationException::withMessages(['items' => 'Pilih obat aktif yang ditandai memerlukan resep dari apotek ini.']);
            }
            $saved = $input['items'][$index];
            if ($final && (($saved['unit'] ?? null) !== $product->base_unit || ($saved['name'] ?? null) !== $product->name || ($saved['sku'] ?? null) !== $product->sku)) {
                throw ValidationException::withMessages(['items' => 'Identitas atau satuan obat berubah sejak draft disimpan. Tinjau dan simpan ulang draft sebelum finalisasi.']);
            }
            $quantity = (float) $item['quantity'];
            if (abs($quantity - round($quantity, 4)) > 0.0000001) {
                throw ValidationException::withMessages(['items' => 'Jumlah obat maksimal 4 angka desimal.']);
            }
            $items[] = array_intersect_key($item, array_flip(['product_id', 'quantity', 'dose', 'frequency', 'route', 'duration', 'instructions'])) + array_fill_keys(['dose', 'frequency', 'route', 'duration', 'instructions'], null) + ['name' => $product->name, 'sku' => $product->sku, 'unit' => $product->base_unit];
        }

        return ['items' => $items, 'notes' => $data['notes'] ?? null];
    }

    public function save(Pharmacy $pharmacy, int $actor, string $visit, int $revision, array $input): string
    {
        return $this->access->locked($pharmacy, function () use ($pharmacy, $actor, $visit, $revision, $input) {
            $selector = $input['prescription'] ?? null;
            Validator::make($input, ['prescription' => 'nullable|string|max:36', 'rx_request_key' => ($selector === 'new' ? 'required' : 'nullable').'|uuid'])->validate();
            $existing = self::selected($pharmacy, $visit, $selector);
            [$record, $doctor] = $this->writable($pharmacy, $actor, $visit, $existing ? 'prescriptions.update' : 'prescriptions.create');
            if (! $existing && ! empty($input['rx_request_key'])) {
                $replayed = self::query($pharmacy)->where('request_key', $input['rx_request_key'])->first();
                if ($replayed) {
                    abort_unless($replayed->visit_id === $visit, 422);

                    return $replayed->id;
                }
            }
            if (($existing && $existing->status !== 'DRAFT') || (int) ($existing?->revision ?? 0) !== $revision) {
                $this->fail('Resep sudah berubah atau final. Muat ulang sebelum menyimpan.');
            }
            if ($existing && DB::table('sales')->where('pharmacy_id', $pharmacy->id)->whereIn('status', ['DRAFT', 'PAID'])->where(function ($q) use ($existing) {
                $q->where('prescription_id', $existing->id)->orWhereExists(fn ($items) => $items->selectRaw('1')->from('sale_items')->whereColumn('sale_items.sale_id', 'sales.id')->where('sale_items.prescription_id', $existing->id));
            })->exists()) {
                $this->fail('Resep sudah digunakan pada penebusan. Buat resep baru untuk perubahan.');
            }
            $data = self::items($pharmacy, $input, false);
            $patient = ClinicalMaster::patients($pharmacy)->where('id', $record->patient_id)->first(['birth_date', 'sex', 'address', 'phone']);
            $data['patient'] = ['name' => $record->patient_name, 'medical_record_number' => $record->medical_record_number, 'birth_date' => $patient?->birth_date, 'sex' => $patient?->sex, 'address' => $patient?->address, 'phone' => $patient?->phone];
            $data['doctor'] = ['name' => $doctor->name, 'code' => $doctor->code, 'sip_number' => $doctor->sip_number, 'str_number' => $doctor->str_number];
            $validity = Validator::make($input, ['valid_until' => 'required|date_format:Y-m-d'])->validate();
            $values = ['signature_id' => ClinicalMaster::currentSignature($pharmacy, $doctor)?->id, 'ready_at' => now(), 'valid_until' => $validity['valid_until'], 'payload_encrypted' => Crypt::encryptString(json_encode($data, JSON_THROW_ON_ERROR)), 'revision' => $revision + 1, 'updated_by' => $actor, 'updated_at' => now()];
            if ($existing) {
                $id = $existing->id;
                self::query($pharmacy)->where('id', $existing->id)->update($values);
            } else {
                $id = (string) Str::uuid();
                DB::table('prescriptions')->insert($values + ['id' => $id, 'request_key' => $input['rx_request_key'] ?? null, 'pharmacy_id' => $pharmacy->id, 'visit_id' => $visit, 'number' => 'RX-'.now($pharmacy->settings?->timezone ?: 'Asia/Jakarta')->format('Ymd').'-'.strtoupper(Str::random(10)), 'status' => 'DRAFT', 'created_by' => $actor, 'created_at' => now()]);
            }
            $this->access->audit($pharmacy, $actor, 'prescription.draft_saved', $visit, ['revision' => $revision + 1, 'prescription_id' => $id]);

            return $id;
        });
    }

    public function finalize(Pharmacy $pharmacy, int $actor, string $visit, int $revision, ?string $selector = null): void
    {
        $this->access->locked($pharmacy, function () use ($pharmacy, $actor, $visit, $revision, $selector) {
            [$record,$doctor] = $this->writable($pharmacy, $actor, $visit, 'prescriptions.finalize');
            $prescription = self::selected($pharmacy, $visit, $selector);
            if (! $prescription || $prescription->status !== 'DRAFT' || (int) $prescription->revision !== $revision) {
                $this->fail('Draft resep sudah berubah atau final.');
            }
            if (! $prescription->valid_until || $prescription->valid_until < now($pharmacy->timezone ?: 'Asia/Jakarta')->toDateString()) {
                $this->fail('Isi masa berlaku resep yang belum terlewati sebelum finalisasi.');
            }
            $data = self::items($pharmacy, ExaminationRecords::payload($prescription), true);
            $available = app(StockLots::class)->available($pharmacy, array_column($data['items'], 'product_id'));
            foreach ($data['items'] as $index => $item) {
                if (Decimal::scaled($item['quantity'], 4) > ($available[$item['product_id']] ?? 0)) {
                    throw ValidationException::withMessages(['items.'.$index.'.quantity' => 'Stok layak jual '.$item['name'].' tidak mencukupi. Tersedia '.Decimal::format($available[$item['product_id']] ?? 0, 4).' '.$item['unit'].'. Tinjau jumlah resep atau tunggu penerimaan stok sebelum finalisasi.']);
                }
            }
            $data['doctor'] = ['name' => $doctor->name, 'code' => $doctor->code, 'sip_number' => $doctor->sip_number, 'str_number' => $doctor->str_number];
            $patient = ClinicalMaster::patients($pharmacy)->where('id', $record->patient_id)->first(['birth_date', 'sex', 'address', 'phone']);
            $data['patient'] = ['name' => $record->patient_name, 'medical_record_number' => $record->medical_record_number,
                'birth_date' => $patient?->birth_date, 'sex' => $patient?->sex, 'address' => $patient?->address, 'phone' => $patient?->phone];
            $allergies = ClinicalMaster::patients($pharmacy)->where('id', $record->patient_id)->value('allergies_encrypted');
            $data['allergies'] = $allergies ? Crypt::decryptString($allergies) : null;
            $data['soap_status_at_issue'] = ExaminationRecords::query($pharmacy)->where('visit_id', $visit)->value('status') ?? 'EMPTY';
            $signature = ClinicalMaster::currentSignature($pharmacy, $doctor);
            self::query($pharmacy)->where('id', $prescription->id)->update(['status' => 'FINAL', 'revision' => $revision + 1, 'payload_encrypted' => Crypt::encryptString(json_encode($data, JSON_THROW_ON_ERROR)),
                'signature_id' => $signature?->id, 'finalized_at' => now(), 'finalized_by' => $actor, 'updated_by' => $actor, 'updated_at' => now()]);
            $this->access->audit($pharmacy, $actor, 'prescription.finalized', $visit, ['soap_pending' => $data['soap_status_at_issue'] !== 'FINAL', 'signature_id' => $signature?->id, 'prescription_id' => $prescription->id]);
        });
    }
}
