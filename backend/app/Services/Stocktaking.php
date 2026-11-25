<?php

namespace App\Services;

use App\Models\Pharmacy;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class Stocktaking
{
    public function __construct(private AccessManager $access, private StockLots $lots) {}

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['stocktake' => $message]);
    }

    public static function assertAvailable(Pharmacy $pharmacy, array $products): void
    {
        $blocked = DB::table('stocktake_products as scope')->join('stocktakes as session', 'session.id', '=', 'scope.stocktake_id')
            ->where('session.pharmacy_id', $pharmacy->id)->whereIn('session.status', ['COUNTING', 'APPROVED'])->whereIn('scope.product_id', $products)->value('session.number');
        if ($blocked) {
            throw ValidationException::withMessages(['stocktake' => 'Produk sedang dihitung pada '.$blocked.'. Selesaikan atau batalkan sesi sebelum memposting perubahan stok.']);
        }
    }

    private function check(Pharmacy $pharmacy, int $actor, string $permission): void
    {
        $this->access->actor($pharmacy, $actor, $permission);
        if (Pharmacy::whereKey($pharmacy->id)->value('status') !== 'ACTIVE') {
            $this->fail('Apotek harus aktif untuk memproses opname.');
        }
    }

    private function session(Pharmacy $pharmacy, string $id): object
    {
        $session = DB::table('stocktakes')->where('pharmacy_id', $pharmacy->id)->where('id', $id)->lockForUpdate()->first();
        abort_unless($session, 404);

        return $session;
    }

    private function revision(object $session, int $revision, string $status = 'COUNTING'): void
    {
        if ($session->status !== $status || $session->revision !== $revision) {
            $this->fail('Sesi sudah berubah atau terkunci. Muat ulang halaman sebelum melanjutkan.');
        }
    }

    public function start(Pharmacy $pharmacy, int $actor, array $data): string
    {
        return $this->access->locked($pharmacy, function () use ($pharmacy, $actor, $data) {
            $this->check($pharmacy, $actor, 'stocktakes.create');
            $existing = DB::table('stocktakes')->where('pharmacy_id', $pharmacy->id)->where('request_key', $data['request_key'])->first();
            if ($existing) {
                return $existing->id;
            }
            $products = $pharmacy->products()->when($data['category'] ?? null, fn ($query, $category) => $query->where('category', $category))
                ->when($data['product_id'] ?? null, fn ($query, $id) => $query->whereKey($id))->orderBy('id')->get();
            if ($products->isEmpty()) {
                $this->fail('Tidak ada produk pada ruang lingkup yang dipilih.');
            }
            self::assertAvailable($pharmacy, $products->modelKeys());
            $id = (string) Str::uuid();
            $scope = ! empty($data['product_id']) ? 'Produk '.$products->first()->sku : ($data['category'] ?? 'Seluruh produk');
            DB::table('stocktakes')->insert(['id' => $id, 'pharmacy_id' => $pharmacy->id, 'request_key' => $data['request_key'],
                'number' => 'SO-'.now($pharmacy->timezone ?: 'Asia/Jakarta')->format('Ymd').'-'.strtoupper(Str::random(8)),
                'scope' => $scope, 'status' => 'COUNTING', 'revision' => 1, 'notes' => $data['notes'] ?? null, 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
            foreach ($products as $product) {
                $movements = DB::table('stock_movements')->where('pharmacy_id', $pharmacy->id)->where('product_id', $product->id);
                DB::table('stocktake_products')->insert(['stocktake_id' => $id, 'product_id' => $product->id, 'movement_count' => (clone $movements)->count()]);
                $groups = $movements->select('batch_number', 'expires_on', 'location', 'base_unit')->selectRaw('SUM(quantity) AS quantity')->groupBy('batch_number', 'expires_on', 'location', 'base_unit')->get();
                if ($groups->isEmpty()) {
                    $groups->push((object) ['batch_number' => null, 'expires_on' => null, 'location' => null, 'base_unit' => $product->base_unit, 'quantity' => 0]);
                }
                foreach ($groups as $group) {
                    DB::table('stocktake_lines')->insert(['id' => (string) Str::uuid(), 'stocktake_id' => $id, 'product_id' => $product->id,
                        'product_name' => $product->name, 'brand' => $product->brand, 'sku' => $product->sku, 'base_unit' => $group->base_unit,
                        'batch_number' => $group->batch_number, 'expires_on' => $group->expires_on, 'location' => $group->location, 'system_quantity' => $group->quantity]);
                }
            }
            $this->access->audit($pharmacy, $actor, 'stocktake.started', $id, ['scope' => $scope, 'products' => $products->count()]);

            return $id;
        });
    }

    public function count(Pharmacy $pharmacy, int $actor, string $id, array $data): void
    {
        $this->access->locked($pharmacy, function () use ($pharmacy, $actor, $id, $data) {
            $this->check($pharmacy, $actor, 'stocktakes.update');
            $session = $this->session($pharmacy, $id);
            $this->revision($session, (int) $data['revision']);
            foreach ($data['lines'] as $row) {
                $line = DB::table('stocktake_lines')->where('stocktake_id', $id)->where('id', $row['id'])->first();
                abort_unless($line, 404);
                $physical = Decimal::scaled($row['physical_quantity'], 4);
                $delta = $physical - StockLots::signed($line->system_quantity);
                if ($delta !== 0 && empty(trim($row['reason'] ?? ''))) {
                    $this->fail('Alasan selisih wajib diisi untuk '.$line->sku.'.');
                }
                if ($physical > 0 && StockLots::signed($line->system_quantity) === 0 && (($pharmacy->settings?->track_batch && ! $line->batch_number) || ($pharmacy->settings?->track_expiry && ! $line->expires_on))) {
                    $this->fail('Produk '.$line->sku.' belum memiliki batch. Isi 0 pada baris kosong lalu gunakan Tambah batch ditemukan.');
                }
                $values = ['physical_quantity' => Decimal::format($physical, 4), 'reason' => $row['reason'] ?? null, 'counted_by' => $actor, 'counted_at' => now()];
                if (array_key_exists('physical_card_quantity', $row)) {
                    $values['physical_card_quantity'] = $row['physical_card_quantity'] === null ? null : Decimal::format(Decimal::scaled($row['physical_card_quantity'], 4), 4);
                }
                DB::table('stocktake_lines')->where('id', $line->id)->update($values);
            }
            DB::table('stocktakes')->where('id', $id)->update(['revision' => $session->revision + 1, 'updated_at' => now()]);
            $this->access->audit($pharmacy, $actor, 'stocktake.counted', $id, ['lines' => $data['lines']]);
        });
    }

    public function addLine(Pharmacy $pharmacy, int $actor, string $id, array $data): void
    {
        $this->access->locked($pharmacy, function () use ($pharmacy, $actor, $id, $data) {
            $this->check($pharmacy, $actor, 'stocktakes.update');
            $session = $this->session($pharmacy, $id);
            $this->revision($session, (int) $data['revision']);
            abort_unless(DB::table('stocktake_products')->where('stocktake_id', $id)->where('product_id', $data['product_id'])->exists(), 404);
            $product = $pharmacy->products()->findOrFail($data['product_id']);
            foreach (['batch_number', 'expires_on', 'location'] as $field) {
                $data[$field] = $data[$field] ?? null;
            }
            if (($pharmacy->settings?->track_batch && ! $data['batch_number']) || ($pharmacy->settings?->track_expiry && ! $data['expires_on'])) {
                $this->fail('Lengkapi batch dan kedaluwarsa sesuai pengaturan apotek.');
            }
            $duplicate = DB::table('stocktake_lines')->where('stocktake_id', $id)->where('product_id', $product->id)
                ->where('base_unit', $product->base_unit)->where('batch_number', $data['batch_number'])->where('expires_on', $data['expires_on'])->where('location', $data['location'])->exists();
            if ($duplicate) {
                $this->fail('Batch/lokasi tersebut sudah ada. Isi jumlah fisik pada baris yang tersedia.');
            }
            DB::table('stocktake_lines')->insert(['id' => (string) Str::uuid(), 'stocktake_id' => $id, 'product_id' => $product->id,
                'product_name' => $product->name, 'sku' => $product->sku, 'brand' => $product->brand, 'base_unit' => $product->base_unit,
                'batch_number' => $data['batch_number'], 'expires_on' => $data['expires_on'], 'location' => $data['location'], 'system_quantity' => 0]);
            DB::table('stocktakes')->where('id', $id)->update(['revision' => $session->revision + 1, 'updated_at' => now()]);
            $this->access->audit($pharmacy, $actor, 'stocktake.batch_added', $id, ['product' => $product->id, 'batch' => $data['batch_number'], 'location' => $data['location']]);
        });
    }

    private function unchanged(Pharmacy $pharmacy, string $id): void
    {
        foreach (DB::table('stocktake_products')->where('stocktake_id', $id)->get() as $scope) {
            $count = DB::table('stock_movements')->where('pharmacy_id', $pharmacy->id)->where('product_id', $scope->product_id)->count();
            if ($count !== (int) $scope->movement_count) {
                $this->fail('Mutasi stok berubah setelah snapshot. Batalkan sesi dan mulai ulang penghitungan.');
            }
        }
    }

    public function approve(Pharmacy $pharmacy, int $actor, string $id, int $revision): void
    {
        $this->access->locked($pharmacy, function () use ($pharmacy, $actor, $id, $revision) {
            $this->check($pharmacy, $actor, 'stocktakes.approve');
            $session = $this->session($pharmacy, $id);
            $this->revision($session, $revision);
            if (DB::table('stocktake_lines')->where('stocktake_id', $id)->whereNull('physical_quantity')->exists()) {
                $this->fail('Semua baris harus dihitung sebelum persetujuan, termasuk baris dengan fisik 0.');
            }
            $this->unchanged($pharmacy, $id);
            DB::table('stocktakes')->where('id', $id)->update(['status' => 'APPROVED', 'approved_by' => $actor, 'approved_at' => now(), 'revision' => $revision + 1, 'updated_at' => now()]);
            $this->access->audit($pharmacy, $actor, 'stocktake.approved', $id);
        });
    }

    public function post(Pharmacy $pharmacy, int $actor, string $id, int $revision): void
    {
        $this->access->locked($pharmacy, function () use ($pharmacy, $actor, $id, $revision) {
            $this->check($pharmacy, $actor, 'stocktakes.post');
            $session = $this->session($pharmacy, $id);
            if ($session->status === 'POSTED') {
                return;
            }
            $this->revision($session, $revision, 'APPROVED');
            $this->unchanged($pharmacy, $id);
            $lines = DB::table('stocktake_lines')->where('stocktake_id', $id)->orderBy('product_id')->orderBy('id')->get();
            foreach ($lines as $line) {
                if ($line->physical_quantity === null) {
                    $this->fail('Jumlah fisik belum lengkap.');
                }
                $delta = StockLots::signed($line->physical_quantity) - StockLots::signed($line->system_quantity);
                if ($delta === 0) {
                    continue;
                }
                if (empty(trim($line->reason ?? ''))) {
                    $this->fail('Alasan selisih belum lengkap.');
                }
                $base = ['pharmacy_id' => $pharmacy->id, 'product_id' => $line->product_id, 'stocktake_line_id' => $line->id,
                    'occurred_on' => now($pharmacy->timezone ?: 'Asia/Jakarta')->toDateString(), 'base_unit' => $line->base_unit,
                    'batch_number' => $line->batch_number, 'expires_on' => $line->expires_on, 'location' => $line->location, 'created_at' => now()];
                if ($delta > 0) {
                    DB::table('stock_movements')->insert($base + ['id' => (string) Str::uuid(), 'type' => 'STOCKTAKE_IN', 'quantity' => StockLots::format($delta), 'adjustment_key' => $line->id.':in']);

                    continue;
                }
                $needed = abs($delta);
                foreach ($this->lots->sources($pharmacy, $line->product_id) as $source) {
                    if ($source->batch_number !== $line->batch_number || $source->expires_on !== $line->expires_on || $source->location !== $line->location || $source->base_unit !== $line->base_unit) {
                        continue;
                    }
                    $take = min($needed, max(0, $this->lots->remaining($pharmacy, $source)));
                    if ($take === 0) {
                        continue;
                    }
                    DB::table('stock_movements')->insert($base + ['id' => (string) Str::uuid(), 'source_movement_id' => $source->id, 'type' => 'STOCKTAKE_OUT',
                        'quantity' => StockLots::format(-$take), 'adjustment_key' => $line->id.':'.$source->id]);
                    $needed -= $take;
                    if ($needed === 0) {
                        break;
                    }
                }
                if ($needed > 0) {
                    $this->fail('Sumber stok tidak mencukupi untuk koreksi '.$line->sku.'. Seluruh posting dibatalkan.');
                }
            }
            DB::table('stocktakes')->where('id', $id)->update(['status' => 'POSTED', 'posted_by' => $actor, 'posted_at' => now(), 'revision' => $revision + 1, 'updated_at' => now()]);
            Pharmacy::whereKey($pharmacy->id)->update(['has_transactions' => true]);
            $this->access->audit($pharmacy, $actor, 'stocktake.posted', $id, ['number' => $session->number]);
        });
    }

    public function cancel(Pharmacy $pharmacy, int $actor, string $id, int $revision, string $reason): void
    {
        $this->access->locked($pharmacy, function () use ($pharmacy, $actor, $id, $revision, $reason) {
            $this->access->actor($pharmacy, $actor, 'stocktakes.cancel');
            $session = $this->session($pharmacy, $id);
            if (! in_array($session->status, ['COUNTING', 'APPROVED'], true) || $session->revision !== $revision) {
                $this->fail('Sesi sudah berubah atau tidak dapat dibatalkan.');
            }
            DB::table('stocktakes')->where('id', $id)->update(['status' => 'CANCELLED', 'cancellation_reason' => $reason, 'revision' => $revision + 1, 'updated_at' => now()]);
            $this->access->audit($pharmacy, $actor, 'stocktake.cancelled', $id, ['reason' => $reason]);
        });
    }
}
