<?php

namespace App\Services;

use App\Models\Pharmacy;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CashManagement
{
    public function __construct(private AccessManager $access) {}

    public static function check(Pharmacy $pharmacy, int $actor, string $permission): void
    {
        app(AccessManager::class)->actor($pharmacy, $actor, $permission);
        abort_unless($pharmacy->fresh()->status === 'ACTIVE', 422, 'Apotek harus aktif.');
    }

    public static function fail(string $message): never
    {
        throw ValidationException::withMessages(['operation' => $message]);
    }

    public static function number(string $prefix, Pharmacy $pharmacy): string
    {
        return $prefix.'-'.now($pharmacy->timezone ?: 'Asia/Jakarta')->format('Ymd').'-'.strtoupper(Str::random(8));
    }

    public static function amount(int $value): string
    {
        return ($value < 0 ? '-' : '').Decimal::format(abs($value));
    }

    public static function expected(object $shift): int
    {
        $sum = DB::table('cash_entries')->where('cash_shift_id', $shift->id)->where('pharmacy_id', $shift->pharmacy_id)->where('method', 'cash')->sum('amount');
        $signed = str_starts_with((string) $sum, '-') ? -1 : 1;

        return Decimal::scaled($shift->opening_cash, 2) + $signed * Decimal::scaled(ltrim((string) $sum, '-'), 2);
    }

    /** Called within the source transaction holding the pharmacy lock. Amount is net of change. */
    public static function record(Pharmacy $pharmacy, int $actor, string $type, string $id, string $number, string $method, int $amount, ?string $date = null): void
    {
        if (DB::table('cash_entries')->where('pharmacy_id', $pharmacy->id)->where('source_type', $type)->where('source_id', $id)->exists()) {
            return;
        }
        $shift = DB::table('cash_shifts')->where('pharmacy_id', $pharmacy->id)->where('user_id', $actor)->where('status', 'OPEN')->lockForUpdate()->first();
        if ($method === 'cash' && DB::table('cash_controls')->where('pharmacy_id', $pharmacy->id)->exists()) {
            if (! $shift) {
                self::fail('Buka shift kasir Anda sebelum mencatat transaksi tunai.');
            }
            if (self::expected($shift) + $amount < 0) {
                self::fail('Saldo tunai shift tidak mencukupi. Gunakan sumber dana yang sesuai atau catat kas masuk tambahan.');
            }
        }
        DB::table('cash_entries')->insert(['id' => (string) Str::uuid(), 'pharmacy_id' => $pharmacy->id, 'cash_shift_id' => $shift?->id,
            'source_type' => $type, 'source_id' => $id, 'number' => $number, 'method' => $method, 'amount' => self::amount($amount),
            'occurred_on' => $date ?? now($pharmacy->timezone ?: 'Asia/Jakarta')->toDateString(), 'created_by' => $actor, 'created_at' => now()]);
    }

    public function open(Pharmacy $pharmacy, int $actor, array $input): string
    {
        $data = Validator::make($input, ['request_key' => 'required|uuid', 'opening_cash' => 'required|numeric|min:0|max:1000000000|decimal:0,2'])->validate();

        return $this->access->locked($pharmacy, function () use ($pharmacy, $actor, $data) {
            self::check($pharmacy, $actor, 'shifts.create');
            $existing = DB::table('cash_shifts')->where('pharmacy_id', $pharmacy->id)->where('request_key', $data['request_key'])->first();
            if ($existing) {
                abort_unless((int) $existing->user_id === $actor, 403);

                return $existing->id;
            }
            if (DB::table('cash_shifts')->where('pharmacy_id', $pharmacy->id)->where('user_id', $actor)->where('status', 'OPEN')->exists()) {
                self::fail('Anda masih memiliki shift terbuka. Tutup shift tersebut terlebih dahulu.');
            }
            DB::table('cash_controls')->insertOrIgnore(['pharmacy_id' => $pharmacy->id, 'enabled_at' => now()]);
            $id = (string) Str::uuid();
            DB::table('cash_shifts')->insert(['id' => $id, 'pharmacy_id' => $pharmacy->id, 'user_id' => $actor, 'request_key' => $data['request_key'],
                'number' => self::number('SH', $pharmacy), 'opening_cash' => Decimal::format(Decimal::scaled($data['opening_cash'], 2)), 'opened_at' => now()]);
            $this->access->audit($pharmacy, $actor, 'shift.opened', $id);

            return $id;
        });
    }

    public function close(Pharmacy $pharmacy, int $actor, string $id, array $input): void
    {
        $data = Validator::make($input, ['revision' => 'required|integer|min:1', 'counted_cash' => 'required|numeric|min:0|max:1000000000000|decimal:0,2', 'reason' => 'nullable|string|max:1000'])->validate();
        $this->access->locked($pharmacy, function () use ($pharmacy, $actor, $id, $data) {
            self::check($pharmacy, $actor, 'shifts.close');
            $shift = DB::table('cash_shifts')->where('pharmacy_id', $pharmacy->id)->where('id', $id)->lockForUpdate()->first();
            abort_unless($shift, 404);
            if ((int) $shift->user_id !== $actor) {
                $this->access->actor($pharmacy, $actor, 'shifts.manage');
            }
            if ($shift->status === 'CLOSED') {
                return;
            }
            if ((int) $shift->revision !== (int) $data['revision']) {
                self::fail('Shift berubah. Muat ulang halaman.');
            }
            $expected = self::expected($shift);
            $counted = Decimal::scaled($data['counted_cash'], 2);
            $difference = $counted - $expected;
            if ($difference !== 0 && trim($data['reason'] ?? '') === '') {
                self::fail('Alasan wajib diisi jika kas fisik berbeda dari kas sistem.');
            }
            DB::table('cash_shifts')->where('id', $id)->update(['status' => 'CLOSED', 'expected_cash' => self::amount($expected), 'counted_cash' => Decimal::format($counted),
                'difference' => self::amount($difference), 'closing_reason' => $data['reason'] ?? null, 'closed_by' => $actor, 'closed_at' => now(), 'revision' => $shift->revision + 1]);
            $this->access->audit($pharmacy, $actor, 'shift.closed', $id, ['difference' => self::amount($difference)]);
        });
    }

    public function deposit(Pharmacy $pharmacy, int $actor, string $id, array $input): void
    {
        $data = Validator::make($input, ['request_key' => 'required|uuid', 'amount' => 'required|numeric|gt:0|max:1000000000|decimal:0,2', 'reason' => 'required|string|max:500'])->validate();
        $this->access->locked($pharmacy, function () use ($pharmacy, $actor, $id, $data) {
            self::check($pharmacy, $actor, 'shifts.manage');
            $shift = DB::table('cash_shifts')->where('pharmacy_id', $pharmacy->id)->where('id', $id)->first();
            abort_unless($shift && $shift->status === 'OPEN' && (int) $shift->user_id === $actor, 422);
            if (DB::table('cash_entries')->where('pharmacy_id', $pharmacy->id)->where('source_type', 'DEPOSIT')->where('source_id', $data['request_key'])->exists()) {
                return;
            }
            self::record($pharmacy, $actor, 'DEPOSIT', $data['request_key'], 'Kas masuk tambahan', 'cash', Decimal::scaled($data['amount'], 2));
            $this->access->audit($pharmacy, $actor, 'shift.deposit', $data['request_key'], ['reason' => $data['reason']]);
        });
    }
}
