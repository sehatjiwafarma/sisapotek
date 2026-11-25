<?php

namespace App\Services;

use App\Models\Pharmacy;
use App\Support\Decimal;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PosPricing
{
    public static function available(Pharmacy $pharmacy, string $kind): Builder
    {
        $today = now($pharmacy->timezone ?: 'Asia/Jakarta')->toDateString();

        return DB::table('pos_pricing_rules')->where('pharmacy_id', $pharmacy->id)->where('kind', $kind)->where('active', true)
            ->where(fn (Builder $query) => $query->whereNull('starts_on')->orWhere('starts_on', '<=', $today))
            ->where(fn (Builder $query) => $query->whereNull('ends_on')->orWhere('ends_on', '>=', $today));
    }

    private static function fraction(int $amount, int $rate, int $denominator): int
    {
        return intdiv($amount, $denominator) * $rate + Decimal::rounded(($amount % $denominator) * $rate, $denominator);
    }

    public function calculate(Pharmacy $pharmacy, int $subtotal, array $data): array
    {
        $selected = [];
        foreach (['discount', 'tax'] as $kind) {
            $id = $data[$kind.'_rule_id'] ?? null;
            $selected[$kind] = $id ? self::available($pharmacy, $kind)->where('id', $id)->first() : null;
            if ($id && ! $selected[$kind]) {
                throw ValidationException::withMessages([$kind.'_rule_id' => 'Master '.($kind === 'tax' ? 'PPN' : 'diskon').' tidak aktif, di luar periode berlaku, atau tidak tersedia di apotek ini. Pilih ulang pada draft.']);
            }
        }
        $discountRule = $selected['discount'];
        $tax = $selected['tax'];
        $discountValue = $discountRule ? Decimal::scaled($discountRule->value, 2) : Decimal::scaled($data['discount'] ?? 0, 2);
        $discount = $discountRule?->type === 'percentage' ? self::fraction($subtotal, $discountValue, 10000) : $discountValue;
        if ($discount > $subtotal) {
            throw ValidationException::withMessages(['discount' => 'Diskon tidak boleh melebihi subtotal.']);
        }
        $net = $subtotal - $discount;
        $rate = $tax ? Decimal::scaled($tax->value, 2) : 0;
        $taxAmount = $tax ? self::fraction($net, $rate, $tax->tax_mode === 'inclusive' ? 10000 + $rate : 10000) : 0;
        $total = $net + ($tax?->tax_mode === 'exclusive' ? $taxAmount : 0);

        return ['discount_rule_id' => $discountRule?->id, 'discount_name' => $discountRule?->name,
            'discount_type' => $discountRule?->type ?? ($discount > 0 ? 'nominal' : null), 'discount_value' => Decimal::format($discountValue),
            'discount' => Decimal::format($discount), 'tax_rule_id' => $tax?->id, 'tax_name' => $tax?->name, 'tax_rate' => Decimal::format($rate),
            'tax_mode' => $tax?->tax_mode, 'taxable_base' => Decimal::format($tax?->tax_mode === 'inclusive' ? $net - $taxAmount : $net),
            'tax_amount' => Decimal::format($taxAmount), 'total' => Decimal::format($total)];
    }

    public function verify(Pharmacy $pharmacy, object $sale): void
    {
        $values = $this->calculate($pharmacy, Decimal::scaled($sale->subtotal, 2), ['discount_rule_id' => $sale->discount_rule_id,
            'tax_rule_id' => $sale->tax_rule_id, 'discount' => $sale->discount_rule_id ? 0 : $sale->discount]);
        foreach ($values as $key => $value) {
            if (! $sale->discount_rule_id && ! $sale->tax_rule_id) {
                continue;
            }
            $numeric = in_array($key, ['discount_value', 'discount', 'tax_rate', 'taxable_base', 'tax_amount', 'total'], true);
            if ($numeric ? Decimal::scaled($sale->$key, 2) !== Decimal::scaled($value, 2) : $value !== $sale->$key) {
                throw ValidationException::withMessages(['items' => 'Master diskon atau PPN berubah. Edit dan simpan ulang draft sebelum pembayaran.']);
            }
        }
    }
}
