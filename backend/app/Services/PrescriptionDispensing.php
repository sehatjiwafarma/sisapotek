<?php

namespace App\Services;

use App\Models\Pharmacy;
use App\Support\Decimal;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PrescriptionDispensing
{
    public function __construct(private AccessManager $access) {}

    public static function requiresMultiplePermission(Pharmacy $pharmacy, object $prescription): bool
    {
        return PrescriptionRecords::query($pharmacy)->where('visit_id', $prescription->visit_id)->count() > 1;
    }

    public function assertMultiplePermission(Pharmacy $pharmacy, int $actor, object $prescription): void
    {
        if (self::requiresMultiplePermission($pharmacy, $prescription)) {
            $member = $pharmacy->memberships()->with('roles')->where('user_id', $actor)->first();
            abort_unless($member?->allows('dispensing.multiple_prescriptions'), 403, 'Pemeriksaan ini memiliki lebih dari satu resep. Penebusan memerlukan izin khusus Farmasi: Tebus resep dari pemeriksaan dengan lebih dari satu resep.');
        }
    }

    public static function expired(Pharmacy $pharmacy, object $prescription): bool
    {
        return $prescription->valid_until !== null && $prescription->valid_until < now($pharmacy->timezone ?: 'Asia/Jakarta')->toDateString();
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['dispensing' => $message]);
    }

    public static function labels(): array
    {
        return ['UNREDEEMED' => 'Belum dibayar', 'PARTIAL' => 'Dibayar sebagian', 'COMPLETE' => 'Dibayar seluruhnya'];
    }

    public static function quantities(Pharmacy $pharmacy, string $prescription): array
    {
        $redeemed = DB::table('sale_items')->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.pharmacy_id', $pharmacy->id)->whereRaw('COALESCE(sale_items.prescription_id, sales.prescription_id) = ?', [$prescription])->where('sales.status', 'PAID')
            ->groupByRaw('COALESCE(sale_items.prescribed_product_id, sale_items.product_id)')->selectRaw('COALESCE(sale_items.prescribed_product_id, sale_items.product_id) AS product_id')->selectRaw('SUM(COALESCE(sale_items.prescribed_quantity, sale_items.base_quantity)) AS redeemed')
            ->get()->mapWithKeys(fn ($row) => [$row->product_id => Decimal::scaled($row->redeemed, 4)])->all();
        foreach (self::restoredQuantities($pharmacy, $prescription) as $product => $quantity) {
            $redeemed[$product] = max(0, ($redeemed[$product] ?? 0) - $quantity);
        }

        return $redeemed;
    }

    public static function restoredQuantities(Pharmacy $pharmacy, string $prescription): array
    {
        return DB::table('sale_return_items as line')->join('sale_returns as header', 'header.id', '=', 'line.sale_return_id')
            ->join('sales', 'sales.id', '=', 'header.sale_id')->join('sale_items as item', 'item.id', '=', 'line.sale_item_id')
            ->where('header.pharmacy_id', $pharmacy->id)->where('sales.pharmacy_id', $pharmacy->id)->whereRaw('COALESCE(item.prescription_id, sales.prescription_id) = ?', [$prescription])
            ->where('sales.kind', 'PRESCRIPTION')->where('sales.status', 'PAID')->where('header.status', 'COMPLETED')->whereNotNull('header.entitlement_restored_at')
            ->groupBy('item.id', 'item.product_id', 'item.prescribed_product_id', 'item.prescribed_quantity', 'item.base_quantity')
            ->select('item.product_id', 'item.prescribed_product_id', 'item.prescribed_quantity', 'item.base_quantity')->selectRaw('SUM(line.quantity) AS restored')
            ->get()->reduce(function ($totals, $row) {
                $product = $row->prescribed_product_id ?? $row->product_id;
                $returned = Decimal::scaled($row->restored, 4);
                $sold = Decimal::scaled($row->base_quantity, 4);
                $entitlement = Decimal::scaled($row->prescribed_quantity ?? $row->base_quantity, 4);
                $totals[$product] = ($totals[$product] ?? 0) + Decimal::rounded($returned * $entitlement, $sold);
                return $totals;
            }, []);
    }

    public static function remaining(Pharmacy $pharmacy, object $prescription): array
    {
        $redeemed = self::quantities($pharmacy, $prescription->id);
        $restored = self::restoredQuantities($pharmacy, $prescription->id);

        return array_map(function (array $item) use ($redeemed, $restored) {
            $item['redeemed'] = $redeemed[$item['product_id']] ?? 0;
            $item['remaining'] = max(0, Decimal::scaled($item['quantity'], 4) - $item['redeemed']);
            $item['entitlement_restored'] = $restored[$item['product_id']] ?? 0;

            return $item;
        }, ExaminationRecords::payload($prescription)['items']);
    }

    public function create(Pharmacy $pharmacy, int $actor, string $id, array $input): string
    {
        $data = Validator::make($input, [
            'request_key' => 'required|uuid', 'verified' => 'sometimes|accepted',
            'recipient_type' => ['nullable', Rule::in(['self', 'representative'])],
            'recipient_name' => 'nullable|required_if:recipient_type,representative|string|max:150',
            'recipient_relationship' => 'nullable|required_if:recipient_type,representative|string|max:100',
            'recipient_phone' => 'nullable|required_if:recipient_type,representative|string|max:30',
            'items' => 'required|array|min:1|max:30', 'items.*' => 'array:product_id,quantity,selected_product_id,prescribed_quantity,adjusted_dose,substitution_confirmed',
            'items.*.substitution_confirmed' => 'sometimes|accepted', 'items.*.selected_product_id' => 'nullable|uuid', 'items.*.prescribed_quantity' => 'nullable|numeric|gt:0|max:100000|decimal:0,4', 'items.*.adjusted_dose' => 'nullable|string|max:150',
            'items.*.product_id' => 'required|uuid|distinct', 'items.*.quantity' => 'required|numeric|min:0|max:100000|decimal:0,4',
            'discount_rule_id' => 'nullable|uuid', 'tax_rule_id' => 'nullable|uuid',
        ])->validate();

        return $this->access->locked($pharmacy, function () use ($pharmacy, $actor, $id, $data) {
            foreach (['dispensing.view', 'dispensing.prepare', 'sales.create', 'sales.view'] as $permission) {
                $this->access->actor($pharmacy, $actor, $permission);
            }
            if (! empty($data['verified'])) $this->access->actor($pharmacy, $actor, 'dispensing.verify');
            abort_unless($pharmacy->fresh()->status === 'ACTIVE', 422);
            $prescription = PrescriptionRecords::query($pharmacy)->where('id', $id)->lockForUpdate()->first();
            abort_unless($prescription, 404);
            $this->assertMultiplePermission($pharmacy, $actor, $prescription);
            if (self::expired($pharmacy, $prescription)) {
                $this->fail('Masa berlaku resep telah berakhir. Pasien perlu berkonsultasi kembali.');
            }
            if ($prescription->status !== 'FINAL' && ! $prescription->ready_at) {
                $this->fail('Simpan resep sebelum penebusan.');
            }
            $existing = DB::table('sales')->where('pharmacy_id', $pharmacy->id)->where('request_key', $data['request_key'])->first();
            if ($existing) {
                abort_unless($existing->prescription_id === $id, 422);

                return $existing->id;
            }
            $remaining = collect(self::remaining($pharmacy, $prescription))->keyBy('product_id');
            $saleId = (string) Str::uuid();
            $subtotal = 0;
            $lines = [];
            foreach ($data['items'] as $row) {
                $item = $remaining->get($row['product_id']);
                if (! $item) {
                    $this->fail('Obat harus sesuai resep final.');
                }
                if (Decimal::scaled($row['quantity'], 4) > 0 && (empty(trim($item['dose'] ?? '')) || empty(trim($item['frequency'] ?? '')) || empty(trim($item['instructions'] ?? '')))) {
                    $this->fail('Dosis, frekuensi, dan aturan pakai harus dilengkapi pada resep sebelum penebusan.');
                }
                $quantity = Decimal::scaled($row['quantity'], 4);
                $selectedId = $row['selected_product_id'] ?? $row['product_id'];
                $prescribedQuantity = isset($row['prescribed_quantity']) ? Decimal::scaled($row['prescribed_quantity'], 4) : $quantity;
                if ($quantity > 0 && $selectedId !== $row['product_id'] && (empty($row['prescribed_quantity']) || empty(trim($row['adjusted_dose'] ?? '')))) {
                    $this->fail('Isi jumlah hak resep dan dosis penyesuaian untuk produk pengganti.');
                }
                if ($selectedId === $row['product_id']) { $prescribedQuantity = $quantity; }
                if ($prescribedQuantity > $item['remaining']) {
                    $this->fail('Jumlah '.$item['name'].' melebihi sisa hak tebus. Muat ulang halaman.');
                }
                if ($quantity === 0) {
                    continue;
                }
                $product = $pharmacy->products()->where('active', true)->find($selectedId);
                if (! $product || ($selectedId === $row['product_id'] && ($product->base_unit !== $item['unit'] || $product->sku !== $item['sku'] || $product->name !== $item['name']))) {
                    $this->fail('Obat tidak aktif atau identitas/satuannya berubah dari resep. Tinjau resep sebelum penebusan.');
                }
                if ($selectedId !== $row['product_id']) {
                    $prescribed = $pharmacy->products()->with('ingredients')->find($row['product_id']);
                    $check = SubstitutionCheck::assess($prescribed, $product->load('ingredients'));
                    if (! $check['ok']) $this->fail('Produk pengganti tidak dapat diperiksa: '.$check['message']);
                    if (empty($row['substitution_confirmed'])) $this->fail('Apoteker harus memverifikasi produk pengganti dan penyesuaian dosis.');
                }
                $price = Decimal::scaled($product->selling_price, 2);
                if ($price > 1000000000) {
                    $this->fail('Harga obat melebihi batas POS Rp10.000.000 per satuan dasar.');
                }
                $total = Decimal::rounded($quantity * $price, 10000);
                $subtotal += $total;
                $lines[] = ['id' => (string) Str::uuid(), 'sale_id' => $saleId, 'product_id' => $product->id,
                    'prescribed_product_id' => $row['product_id'], 'prescribed_quantity' => Decimal::format($prescribedQuantity, 4),
                    'adjusted_dose_encrypted' => ! empty($row['adjusted_dose']) ? Crypt::encryptString(trim($row['adjusted_dose'])) : null,
                    'product_name' => $product->name, 'brand' => $product->brand, 'sku' => $product->sku,
                    'unit_type' => 'base', 'unit' => $product->base_unit, 'base_unit' => $product->base_unit,
                    'conversion' => '1.0000', 'quantity' => Decimal::format($quantity, 4), 'base_quantity' => Decimal::format($quantity, 4),
                    'unit_price' => Decimal::format($price), 'total' => Decimal::format($total)];
            }
            if (! $lines) {
                $this->fail('Isi jumlah tebus lebih dari nol untuk minimal satu obat.');
            }
            if (count(array_unique(array_column($lines, 'product_id'))) !== count($lines)) {
                $this->fail('Produk yang sama tidak dapat digunakan untuk dua obat resep dalam satu transaksi. Siapkan transaksi terpisah.');
            }
            Stocktaking::assertAvailable($pharmacy, array_column($lines, 'product_id'));
            $pricing = app(PosPricing::class)->calculate($pharmacy, $subtotal, $data);
            if ($pricing['discount_rule_id']) {
                $this->access->actor($pharmacy, $actor, 'sales.discount');
            }
            $payload = ExaminationRecords::payload($prescription);
            $recipient = ! isset($data['recipient_type']) ? null : ($data['recipient_type'] === 'self'
                ? ['type' => 'self', 'name' => $payload['patient']['name'], 'relationship' => null, 'phone' => null]
                : ['type' => 'representative', 'name' => $data['recipient_name'], 'relationship' => $data['recipient_relationship'], 'phone' => $data['recipient_phone']]);
            DB::table('sales')->insert(['id' => $saleId, 'pharmacy_id' => $pharmacy->id, 'prescription_id' => $id,
                'request_key' => $data['request_key'], 'number' => 'RDM-'.now()->format('Ymd').'-'.strtoupper(Str::random(10)),
                'kind' => 'PRESCRIPTION', 'status' => 'DRAFT', 'revision' => 1, 'subtotal' => Decimal::format($subtotal),
                'recipient_encrypted' => $recipient ? Crypt::encryptString(json_encode($recipient, JSON_THROW_ON_ERROR)) : null,
                'verified_by' => ! empty($data['verified']) ? $actor : null, 'verified_at' => ! empty($data['verified']) ? now() : null, 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()] + $pricing);
            DB::table('sale_items')->insert($lines);
            $this->access->audit($pharmacy, $actor, 'dispensing.prepared', $saleId, ['prescription_id' => $id, 'item_count' => count($lines)]);

            return $saleId;
        });
    }

    /** Called inside the locked payment transaction; changes roll back if payment fails. */
    public function applyCashierDetails(Pharmacy $pharmacy, int $actor, object $sale, array $input): object
    {
        if (! isset($input['recipient_type']) && $sale->recipient_encrypted) {
            $previous = json_decode(Crypt::decryptString($sale->recipient_encrypted), true, 512, JSON_THROW_ON_ERROR);
            $input += ['recipient_type' => $previous['type'], 'recipient_name' => $previous['name'], 'recipient_relationship' => $previous['relationship'], 'recipient_phone' => $previous['phone']];
        }
        if (! array_key_exists('tax_rule_id', $input)) $input['tax_rule_id'] = $sale->tax_rule_id;
        $data = Validator::make($input, [
            'recipient_type' => ['required', Rule::in(['self', 'representative'])],
            'recipient_name' => 'nullable|required_if:recipient_type,representative|string|max:150',
            'recipient_relationship' => 'nullable|required_if:recipient_type,representative|string|max:100',
            'recipient_phone' => 'nullable|required_if:recipient_type,representative|string|max:30',
            'discount_rule_id' => 'nullable|uuid', 'tax_rule_id' => 'nullable|uuid',
        ])->validate();
        if (! array_key_exists('discount_rule_id', $data)) $data['discount_rule_id'] = $sale->discount_rule_id;
        if (($sale->discount_rule_id ?? null) !== ($data['discount_rule_id'] ?? null) || ! empty($data['discount_rule_id'])) $this->access->actor($pharmacy, $actor, 'sales.discount');
        $prescription = PrescriptionRecords::query($pharmacy)->where('id', $sale->prescription_id)->first();
        abort_unless($prescription, 404);
        $payload = ExaminationRecords::payload($prescription);
        $recipient = $data['recipient_type'] === 'self'
            ? ['type' => 'self', 'name' => $payload['patient']['name'], 'relationship' => null, 'phone' => null]
            : ['type' => 'representative', 'name' => $data['recipient_name'], 'relationship' => $data['recipient_relationship'], 'phone' => $data['recipient_phone']];
        $pricing = app(PosPricing::class)->calculate($pharmacy, Decimal::scaled($sale->subtotal, 2), $data);
        $values = $pricing + ['recipient_encrypted' => Crypt::encryptString(json_encode($recipient, JSON_THROW_ON_ERROR)), 'updated_at' => now()];
        DB::table('sales')->where('id', $sale->id)->update($values);
        foreach ($values as $key => $value) $sale->$key = $value;
        return $sale;
    }
    public function createCombined(Pharmacy $pharmacy, int $actor, array $input): string
    {
        $data = Validator::make($input, [
            'request_key' => 'required|uuid', 'verified' => 'sometimes|accepted',
            'items' => 'required|array|min:1|max:300', 'items.*' => 'array:prescription_id,product_id,quantity,selected_product_id,prescribed_quantity,adjusted_dose,substitution_confirmed',
            'items.*.selected_product_id' => 'nullable|uuid', 'items.*.prescribed_quantity' => 'nullable|numeric|gt:0|max:100000|decimal:0,4',
            'items.*.adjusted_dose' => 'nullable|string|max:150', 'items.*.substitution_confirmed' => 'sometimes|accepted',
            'items.*.prescription_id' => 'required|uuid', 'items.*.product_id' => 'required|uuid',
            'items.*.quantity' => 'required|numeric|min:0|max:100000|decimal:0,4',
            'discount_rule_id' => 'nullable|uuid', 'tax_rule_id' => 'nullable|uuid',
        ])->validate();

        return $this->access->locked($pharmacy, function () use ($pharmacy, $actor, $data) {
            foreach (['dispensing.view', 'dispensing.prepare', 'sales.create', 'sales.view'] as $permission) {
                $this->access->actor($pharmacy, $actor, $permission);
            }
            abort_unless($pharmacy->fresh()->status === 'ACTIVE', 422);
            $existing = DB::table('sales')->where('pharmacy_id', $pharmacy->id)->where('request_key', $data['request_key'])->first();
            if ($existing) {
                abort_unless($existing->kind === 'PRESCRIPTION' && DB::table('sale_items')->where('sale_id', $existing->id)->whereNotNull('prescription_id')->exists(), 422);
                $submitted = collect($data['items'])->filter(fn ($row) => Decimal::scaled($row['quantity'], 4) > 0)
                    ->map(function ($row) {
                        $selected = $row['selected_product_id'] ?? $row['product_id'];
                        $substituted = $selected !== $row['product_id'];
                        if ($substituted && (empty($row['substitution_confirmed']) || empty($row['prescribed_quantity']) || empty(trim($row['adjusted_dose'] ?? '')))) $this->fail('Lengkapi dan verifikasi produk pengganti.');
                        return json_encode([$row['prescription_id'], $row['product_id'], $selected,
                            Decimal::format(Decimal::scaled($row['quantity'], 4), 4),
                            Decimal::format(Decimal::scaled($substituted ? $row['prescribed_quantity'] : $row['quantity'], 4), 4),
                            $substituted ? trim($row['adjusted_dose']) : null], JSON_THROW_ON_ERROR);
                    })->sort()->values()->all();
                $saved = DB::table('sale_items')->where('sale_id', $existing->id)->get()
                    ->map(fn ($row) => json_encode([$row->prescription_id, $row->prescribed_product_id ?? $row->product_id, $row->product_id,
                        Decimal::format(Decimal::scaled($row->base_quantity, 4), 4),
                        Decimal::format(Decimal::scaled($row->prescribed_quantity ?? $row->base_quantity, 4), 4),
                        $row->adjusted_dose_encrypted ? Crypt::decryptString($row->adjusted_dose_encrypted) : null], JSON_THROW_ON_ERROR))->sort()->values()->all();
                abort_unless($submitted === $saved && $existing->discount_rule_id === ($data['discount_rule_id'] ?? null) && $existing->tax_rule_id === ($data['tax_rule_id'] ?? null), 422, 'Kunci permintaan sudah dipakai untuk transaksi lain.');
                return $existing->id;
            }
            $groups = collect($data['items'])->groupBy('prescription_id')->sortKeys();
            $id = (string) Str::uuid();
            $lines = []; $subtotal = 0; $firstPrescription = null;
            foreach ($groups as $rxId => $rows) {
                $rx = PrescriptionRecords::query($pharmacy)->where('id', $rxId)->lockForUpdate()->first();
                abort_unless($rx, 404);
                $this->assertMultiplePermission($pharmacy, $actor, $rx);
                if (($rx->status !== 'FINAL' && ! $rx->ready_at) || self::expired($pharmacy, $rx)) {
                    $this->fail('Semua resep harus tersimpan dan belum kedaluwarsa.');
                }
                if ($rx->dispensing_status !== 'UNREDEEMED' || array_sum(self::quantities($pharmacy, $rx->id)) > 0) {
                    $this->fail('Penebusan gabungan hanya tersedia untuk resep yang belum ditebus. Muat ulang daftar resep.');
                }
                $remaining = collect(self::remaining($pharmacy, $rx))->keyBy('product_id');
                $seen = [];
                foreach ($rows as $row) {
                    if (isset($seen[$row['product_id']])) $this->fail('Obat yang sama tidak boleh diulang dalam satu resep.');
                    $seen[$row['product_id']] = true;
                    $item = $remaining->get($row['product_id']);
                    if (! $item) $this->fail('Obat harus sesuai resep final.');
                    if (Decimal::scaled($row['quantity'], 4) > 0 && (empty(trim($item['dose'] ?? '')) || empty(trim($item['frequency'] ?? '')) || empty(trim($item['instructions'] ?? '')))) {
                        $this->fail('Dosis, frekuensi, dan aturan pakai harus dilengkapi pada resep sebelum penebusan.');
                    }
                    $quantity = Decimal::scaled($row['quantity'], 4);
                    if ($quantity === 0) continue;
                    $selectedId = $row['selected_product_id'] ?? $row['product_id'];
                    $substituted = $selectedId !== $row['product_id'];
                    if ($substituted && (empty($row['prescribed_quantity']) || empty(trim($row['adjusted_dose'] ?? '')))) $this->fail('Isi jumlah hak resep dan dosis penyesuaian untuk produk pengganti.');
                    $prescribedQuantity = $substituted ? Decimal::scaled($row['prescribed_quantity'], 4) : $quantity;
                    if ($prescribedQuantity > $item['remaining']) $this->fail('Jumlah '.$item['name'].' melebihi sisa hak tebus.');
                    $product = $pharmacy->products()->where('active', true)->find($selectedId);
                    if (! $product || (! $substituted && ($product->base_unit !== $item['unit'] || $product->sku !== $item['sku'] || $product->name !== $item['name']))) $this->fail('Obat tidak aktif atau identitas/satuannya berubah dari resep. Tinjau resep sebelum penebusan.');
                    if ($substituted) {
                        $prescribed = $pharmacy->products()->with('ingredients')->find($row['product_id']);
                        $check = SubstitutionCheck::assess($prescribed, $product->load('ingredients'));
                        if (! $check['ok']) $this->fail('Produk pengganti tidak dapat diperiksa: '.$check['message']);
                        if (empty($row['substitution_confirmed'])) $this->fail('Apoteker harus memverifikasi produk pengganti dan penyesuaian dosis.');
                    }
                    $price = Decimal::scaled($product->selling_price, 2);
                    if ($price > 1000000000) $this->fail('Harga obat melebihi batas POS.');
                    $total = Decimal::rounded($quantity * $price, 10000); $subtotal += $total;
                    $firstPrescription ??= $rx->id;
                    $lines[] = ['id' => (string) Str::uuid(), 'sale_id' => $id, 'prescription_id' => $rx->id, 'product_id' => $product->id,
                        'prescribed_product_id' => $row['product_id'], 'prescribed_quantity' => Decimal::format($prescribedQuantity, 4),
                        'adjusted_dose_encrypted' => $substituted ? Crypt::encryptString(trim($row['adjusted_dose'])) : null,
                        'product_name' => $product->name, 'brand' => $product->brand, 'sku' => $product->sku, 'unit_type' => 'base',
                        'unit' => $product->base_unit, 'base_unit' => $product->base_unit, 'conversion' => '1.0000',
                        'quantity' => Decimal::format($quantity, 4), 'base_quantity' => Decimal::format($quantity, 4),
                        'unit_price' => Decimal::format($price), 'total' => Decimal::format($total)];
                }
            }
            if (! $lines) $this->fail('Isi jumlah tebus lebih dari nol untuk minimal satu obat.');
            Stocktaking::assertAvailable($pharmacy, array_column($lines, 'product_id'));
            $pricing = app(PosPricing::class)->calculate($pharmacy, $subtotal, $data);
            if ($pricing['discount_rule_id']) $this->access->actor($pharmacy, $actor, 'sales.discount');
            DB::table('sales')->insert(['id' => $id, 'pharmacy_id' => $pharmacy->id, 'prescription_id' => $firstPrescription,
                'request_key' => $data['request_key'], 'number' => 'RDM-'.now()->format('Ymd').'-'.strtoupper(Str::random(10)),
                'kind' => 'PRESCRIPTION', 'status' => 'DRAFT', 'revision' => 1, 'subtotal' => Decimal::format($subtotal),
                'verified_by' => ! empty($data['verified']) ? $actor : null, 'verified_at' => ! empty($data['verified']) ? now() : null, 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()] + $pricing);
            DB::table('sale_items')->insert($lines);
            $this->access->audit($pharmacy, $actor, 'dispensing.prepared', $id, ['item_count' => count($lines), 'prescription_count' => count(array_unique(array_column($lines, 'prescription_id')))]);
            return $id;
        });
    }

    public static function salePrescriptionIds(object $sale): array
    {
        $ids = DB::table('sale_items')->where('sale_id', $sale->id)->whereNotNull('prescription_id')->distinct()->orderBy('prescription_id')->pluck('prescription_id')->all();
        return $ids ?: [$sale->prescription_id];
    }

    /** Called under the pharmacy lock used for payment and FEFO stock allocation. */
    public function assertPayable(Pharmacy $pharmacy, int $actor, object $sale): void
    {
        $this->access->actor($pharmacy, $actor, 'dispensing.view');
        if (DB::table('sale_items')->where('sale_id', $sale->id)->whereNull('prescription_id')->exists() && ! $sale->recipient_encrypted) $this->fail('Lengkapi pengambil obat pada halaman pembayaran.');
        foreach (self::salePrescriptionIds($sale) as $id) {
            $prescription = PrescriptionRecords::query($pharmacy)->where('id', $id)->lockForUpdate()->first();
            if (! $prescription || ($prescription->status !== 'FINAL' && ! $prescription->ready_at) || self::expired($pharmacy, $prescription)) $this->fail('Resep harus tersimpan dan belum kedaluwarsa.');
            $this->assertMultiplePermission($pharmacy, $actor, $prescription);
            $remaining = collect(self::remaining($pharmacy, $prescription))->keyBy('product_id');
            $lines = DB::table('sale_items')->where('sale_id', $sale->id)->where(function ($q) use ($id, $sale) {
                $q->where('prescription_id', $id);
                if ($id === $sale->prescription_id) $q->orWhereNull('prescription_id');
            })->get();
            foreach ($lines as $line) {
                if ($line->prescribed_product_id && $line->prescribed_product_id !== $line->product_id) {
                    $source = $pharmacy->products()->with('ingredients')->find($line->prescribed_product_id);
                    $substitute = $pharmacy->products()->with('ingredients')->where('active', true)->find($line->product_id);
                    $check = SubstitutionCheck::assess($source, $substitute);
                    if (! $check['ok']) $this->fail('Data produk pengganti berubah. Batalkan transaksi dan periksa kembali: '.$check['message']);
                }
                $item = $remaining->get($line->prescribed_product_id ?? $line->product_id);
                if (! $item || $line->unit_type !== 'base' || Decimal::scaled($line->prescribed_quantity ?? $line->base_quantity, 4) > $item['remaining'] || (($line->prescribed_product_id ?? $line->product_id) === $line->product_id && ($line->unit !== $item['unit'] || $line->sku !== $item['sku'] || $line->product_name !== $item['name']))) $this->fail('Jumlah atau identitas obat tidak sesuai sisa resep. Batalkan draft dan siapkan ulang.');
            }
        }
    }

    public function updateStatus(Pharmacy $pharmacy, string $id): void
    {
        $prescription = PrescriptionRecords::query($pharmacy)->where('id', $id)->first();
        $items = self::remaining($pharmacy, $prescription);
        $status = collect($items)->every(fn ($item) => $item['remaining'] === 0) ? 'COMPLETE'
            : (collect($items)->every(fn ($item) => $item['redeemed'] === 0) ? 'UNREDEEMED' : 'PARTIAL');
        PrescriptionRecords::query($pharmacy)->where('id', $id)->update(['dispensing_status' => $status, 'updated_at' => now()]);
    }

    public function handover(Pharmacy $pharmacy, int $actor, string $saleId): void
    {
        $this->access->locked($pharmacy, function () use ($pharmacy, $actor, $saleId) {
            $this->access->actor($pharmacy, $actor, 'dispensing.view');
            $this->access->actor($pharmacy, $actor, 'dispensing.handover');
            abort_unless($pharmacy->fresh()->status === 'ACTIVE', 422);
            $sale = DB::table('sales')->where('pharmacy_id', $pharmacy->id)->where('id', $saleId)->where('kind', 'PRESCRIPTION')->lockForUpdate()->first();
            abort_unless($sale, 404);
            foreach (self::salePrescriptionIds($sale) as $rxId) {
                $prescription = PrescriptionRecords::query($pharmacy)->where('id', $rxId)->first();
                abort_unless($prescription, 404);
                $this->assertMultiplePermission($pharmacy, $actor, $prescription);
            }
            if ($sale->status !== 'PAID') {
                $this->fail('Pembayaran harus lunas sebelum obat diserahkan.');
            }
            if ($sale->handed_over_at) {
                return;
            }
            DB::table('sales')->where('id', $saleId)->update(['handed_over_at' => now(), 'handed_over_by' => $actor, 'updated_at' => now()]);
            $this->access->audit($pharmacy, $actor, 'dispensing.handed_over', $saleId, ['prescription_id' => $sale->prescription_id]);
        });
    }
}
