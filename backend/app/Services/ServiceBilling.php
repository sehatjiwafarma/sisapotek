<?php

namespace App\Services;

use App\Models\Pharmacy;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ServiceBilling
{
    public function __construct(private AccessManager $access) {}

    public function tariff(Pharmacy $pharmacy, int $actor, array $input, ?string $id = null): string
    {
        $data = Validator::make($input, ['name' => ['required', 'string', 'max:150', Rule::unique('service_tariffs')->where('pharmacy_id', $pharmacy->id)->ignore($id)], 'price' => 'required|numeric|min:0|max:10000000|decimal:0,2', 'active' => 'required|boolean'])->validate();

        return $this->access->locked($pharmacy, function () use ($pharmacy, $actor, $data, $id) {
            CashManagement::check($pharmacy, $actor, 'billing.tariffs');
            if ($id) {
                abort_unless(DB::table('service_tariffs')->where('pharmacy_id', $pharmacy->id)->where('id', $id)->exists(), 404);
            }
            $key = $id ?? (string) Str::uuid();
            DB::table('service_tariffs')->updateOrInsert(['id' => $key], $data + ['pharmacy_id' => $pharmacy->id, 'updated_at' => now()] + ($id ? [] : ['created_at' => now()]));
            $this->access->audit($pharmacy, $actor, 'tariff.saved', $key);

            return $key;
        });
    }

    public static function assertSaleUnlocked(Pharmacy $pharmacy, string $sale, ?string $bill = null): void
    {
        $linked = DB::table('service_bills')->where('pharmacy_id', $pharmacy->id)->where('sale_id', $sale)->where('status', 'DRAFT')->value('id');
        if ($linked && $linked !== $bill) {
            CashManagement::fail('Draft obat terhubung ke tagihan layanan. Bayar melalui tagihan tersebut atau batalkan tagihannya terlebih dahulu.');
        }
    }

    public function create(Pharmacy $pharmacy, int $actor, array $input): string
    {
        $data = Validator::make($input, ['request_key' => 'required|uuid', 'visit_id' => 'required|uuid', 'sale_id' => 'prohibited', 'items' => 'required|array|min:1|max:100', 'items.*.tariff_id' => 'required|uuid|distinct', 'items.*.quantity' => 'required|integer|min:0|max:1000'])->validate();

        return $this->access->locked($pharmacy, function () use ($pharmacy, $actor, $data) {
            CashManagement::check($pharmacy, $actor, 'billing.create');
            if ($id = DB::table('service_bills')->where('pharmacy_id', $pharmacy->id)->where('request_key', $data['request_key'])->value('id')) {
                return $id;
            }
            $visit = DB::table('visits')->where('pharmacy_id', $pharmacy->id)->where('id', $data['visit_id'])->lockForUpdate()->first();
            abort_unless($visit, 404);
            if (! in_array($visit->status, ['REGISTERED', 'IN_PROGRESS', 'COMPLETED'], true)) {
                CashManagement::fail('Tagihan hanya tersedia untuk kunjungan aktif atau selesai.');
            }
            if (DB::table('service_bills')->where('pharmacy_id', $pharmacy->id)->where('open_visit_key', $visit->id)->exists()) {
                CashManagement::fail('Kunjungan sudah memiliki tagihan aktif atau lunas.');
            }
            $medicine = 0;
            $sale = null;
            $lines = [];
            $services = 0;
            $registeredServices = collect(VisitRegistration::services($visit))->keyBy('tariff_id');
            foreach ($data['items'] as $row) {
                if ((int) $row['quantity'] === 0) {
                    continue;
                }
                $registered = $registeredServices->get($row['tariff_id']);
                $tariff = $registered ? (object) ['id' => $registered['tariff_id'], 'name' => $registered['name'], 'price' => $registered['unit_price']]
                    : DB::table('service_tariffs')->where('pharmacy_id', $pharmacy->id)->where('id', $row['tariff_id'])->where('active', true)->first();
                if (! $tariff) {
                    CashManagement::fail('Pilih tarif layanan aktif dari apotek ini.');
                }
                $amount = Decimal::scaled($tariff->price, 2) * (int) $row['quantity'];
                $services += $amount;
                $lines[] = ['service_tariff_id' => $tariff->id, 'name' => $tariff->name, 'quantity' => (int) $row['quantity'], 'unit_price' => $tariff->price, 'total' => Decimal::format($amount)];
            }
            if (! $lines) {
                CashManagement::fail('Isi minimal satu jumlah layanan.');
            }
            if ($visit->status === 'REGISTERED' && $registeredServices->isNotEmpty()) {
                $selected = collect($lines)->mapWithKeys(fn ($line) => [$line['service_tariff_id'] => $line['quantity']])->sortKeys()->all();
                $registered = $registeredServices->map(fn ($line) => (int) $line['quantity'])->sortKeys()->all();
                if ($selected !== $registered) {
                    CashManagement::fail('Tagihan harus sesuai layanan yang dipilih saat pendaftaran. Ubah pendaftaran terlebih dahulu.');
                }
            }
            $id = (string) Str::uuid();
            DB::table('service_bills')->insert(['id' => $id, 'pharmacy_id' => $pharmacy->id, 'visit_id' => $visit->id, 'sale_id' => $sale?->id, 'open_visit_key' => $visit->id, 'open_sale_key' => $sale?->id, 'request_key' => $data['request_key'],
                'number' => CashManagement::number('JL', $pharmacy), 'services_total' => Decimal::format($services), 'medicine_total' => Decimal::format($medicine), 'total' => Decimal::format($services + $medicine), 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
            foreach ($lines as $line) {
                DB::table('service_bill_items')->insert($line + ['id' => (string) Str::uuid(), 'service_bill_id' => $id]);
            }
            $this->access->audit($pharmacy, $actor, 'service_bill.created', $id, ['visit_id' => $visit->id]);

            return $id;
        });
    }

    public function pay(Pharmacy $pharmacy, int $actor, string $id, array $input): void
    {
        $data = Validator::make($input, ['revision' => 'required|integer|min:1', 'payment_method' => ['required', Rule::in(array_keys(SupplierPayables::methods()))],
            'payment_reference' => 'nullable|required_unless:payment_method,cash|string|max:100', 'amount_received' => 'required|numeric|min:0|max:100000000000000|decimal:0,2', 'confirmed' => 'required|accepted'])->validate();
        $this->access->locked($pharmacy, function () use ($pharmacy, $actor, $id, $data) {
            CashManagement::check($pharmacy, $actor, 'billing.pay');
            $bill = DB::table('service_bills')->where('pharmacy_id', $pharmacy->id)->where('id', $id)->lockForUpdate()->first();
            abort_unless($bill, 404);
            if ($bill->status === 'PAID') {
                return;
            }
            if ($bill->status !== 'DRAFT' || (int) $bill->revision !== (int) $data['revision']) {
                CashManagement::fail('Tagihan berubah atau dibatalkan. Muat ulang halaman.');
            }
            $visit = DB::table('visits')->where('pharmacy_id', $pharmacy->id)->where('id', $bill->visit_id)->first();
            if (! $visit || ! in_array($visit->status, ['REGISTERED', 'IN_PROGRESS', 'COMPLETED'], true)) {
                CashManagement::fail('Kunjungan tidak dapat ditagihkan.');
            }
            $total = Decimal::scaled($bill->total, 2);
            $received = Decimal::scaled($data['amount_received'], 2);
            if ($received < $total || ($data['payment_method'] !== 'cash' && $received !== $total)) {
                CashManagement::fail('Tunai harus mencukupi total; non-tunai harus tepat sebesar total.');
            }
            if ($bill->sale_id) {
                $sale = DB::table('sales')->where('pharmacy_id', $pharmacy->id)->where('id', $bill->sale_id)->first();
                if (! $sale || $sale->status !== 'DRAFT' || Decimal::scaled($sale->total, 2) !== Decimal::scaled($bill->medicine_total, 2)) {
                    CashManagement::fail('Draft penebusan berubah. Batalkan tagihan dan buat ulang.');
                }
                app(PointOfSale::class)->pay($pharmacy, $actor, $sale->id, ['revision' => $sale->revision, 'payment_method' => $data['payment_method'], 'payment_reference' => $data['payment_reference'] ?? null, 'amount_received' => $sale->total], $bill->id);
            }
            CashManagement::record($pharmacy, $actor, 'SERVICE_BILL', $id, $bill->number, $data['payment_method'], Decimal::scaled($bill->services_total, 2));
            DB::table('service_bills')->where('id', $id)->update(['status' => 'PAID', 'paid_by' => $actor, 'paid_at' => now(), 'paid_on' => now($pharmacy->timezone ?: 'Asia/Jakarta')->toDateString(),
                'payment_method' => $data['payment_method'], 'payment_reference' => $data['payment_reference'] ?? null, 'amount_received' => Decimal::format($received), 'change_amount' => Decimal::format($received - $total), 'revision' => $bill->revision + 1, 'updated_at' => now()]);
            if ($visit->status === 'REGISTERED') {
                if (! ClinicalMaster::doctors($pharmacy)->where('id', $visit->doctor_id)->where('active', true)->exists()
                    || ! ClinicalMaster::patients($pharmacy)->where('id', $visit->patient_id)->where('active', true)->exists()) {
                    CashManagement::fail('Pasien dan dokter harus aktif sebelum layanan dimulai.');
                }
                $updated = DB::table('visits')->where('pharmacy_id', $pharmacy->id)->where('id', $visit->id)->where('status', 'REGISTERED')->where('revision', $visit->revision)
                    ->update(['status' => 'IN_PROGRESS', 'started_at' => now(), 'revision' => $visit->revision + 1, 'updated_at' => now()]);
                if (! $updated) {
                    CashManagement::fail('Kunjungan berubah. Muat ulang halaman.');
                }
                $this->access->audit($pharmacy, $actor, 'visit.started', $visit->id, ['source' => 'service_bill.paid']);
            }
            Pharmacy::whereKey($pharmacy->id)->update(['has_transactions' => true]);
            $this->access->audit($pharmacy, $actor, 'service_bill.paid', $id, ['total' => $bill->total]);
        });
    }

    public function cancel(Pharmacy $pharmacy, int $actor, string $id, array $input): void
    {
        $data = Validator::make($input, ['revision' => 'required|integer|min:1', 'reason' => 'required|string|max:1000'])->validate();
        $this->access->locked($pharmacy, function () use ($pharmacy, $actor, $id, $data) {
            CashManagement::check($pharmacy, $actor, 'billing.cancel');
            $bill = DB::table('service_bills')->where('pharmacy_id', $pharmacy->id)->where('id', $id)->lockForUpdate()->first();
            abort_unless($bill, 404);
            if ($bill->status === 'CANCELLED') {
                return;
            }
            if ($bill->status !== 'DRAFT' || (int) $bill->revision !== (int) $data['revision']) {
                CashManagement::fail('Tagihan lunas atau berubah tidak dapat dibatalkan.');
            }
            DB::table('service_bills')->where('id', $id)->update(['status' => 'CANCELLED', 'open_visit_key' => null, 'open_sale_key' => null, 'cancellation_reason' => $data['reason'], 'revision' => $bill->revision + 1, 'updated_at' => now()]);
            $this->access->audit($pharmacy, $actor, 'service_bill.cancelled', $id);
        });
    }
}
