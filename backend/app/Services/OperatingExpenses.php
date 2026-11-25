<?php

namespace App\Services;

use App\Models\Pharmacy;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class OperatingExpenses
{
    public function __construct(private AccessManager $access) {}

    public function category(Pharmacy $pharmacy, int $actor, array $input, ?string $id = null): string
    {
        $data = Validator::make($input, ['name' => ['required', 'string', 'max:150', Rule::unique('expense_categories')->where('pharmacy_id', $pharmacy->id)->ignore($id)], 'active' => 'required|boolean'])->validate();

        return $this->access->locked($pharmacy, function () use ($pharmacy, $actor, $data, $id) {
            CashManagement::check($pharmacy, $actor, 'expenses.categories');
            if ($id) {
                abort_unless(DB::table('expense_categories')->where('pharmacy_id', $pharmacy->id)->where('id', $id)->exists(), 404);
            }
            $key = $id ?? (string) Str::uuid();
            DB::table('expense_categories')->updateOrInsert(['id' => $key], $data + ['pharmacy_id' => $pharmacy->id, 'updated_at' => now()] + ($id ? [] : ['created_at' => now()]));
            $this->access->audit($pharmacy, $actor, 'expense_category.saved', $key);

            return $key;
        });
    }

    public function create(Pharmacy $pharmacy, int $actor, array $input): string
    {
        $data = Validator::make($input, ['request_key' => 'required|uuid', 'category_id' => 'required|uuid', 'description' => 'required|string|max:2000', 'spent_on' => 'required|date_format:Y-m-d',
            'amount' => 'required|numeric|gt:0|max:1000000000|decimal:0,2', 'method' => ['required', Rule::in(array_keys(SupplierPayables::methods()))], 'reference' => 'nullable|required_unless:method,cash|string|max:100'])->validate();

        return $this->access->locked($pharmacy, function () use ($pharmacy, $actor, $data) {
            CashManagement::check($pharmacy, $actor, 'expenses.create');
            if ($id = DB::table('operating_expenses')->where('pharmacy_id', $pharmacy->id)->where('request_key', $data['request_key'])->value('id')) {
                return $id;
            }
            $category = DB::table('expense_categories')->where('pharmacy_id', $pharmacy->id)->where('id', $data['category_id'])->where('active', true)->first();
            if (! $category) {
                CashManagement::fail('Pilih kategori pengeluaran aktif dari apotek ini.');
            }
            if ($data['spent_on'] > now($pharmacy->timezone ?: 'Asia/Jakarta')->toDateString()) {
                CashManagement::fail('Tanggal pengeluaran tidak boleh melewati hari ini.');
            }
            $id = (string) Str::uuid();
            DB::table('operating_expenses')->insert(['id' => $id, 'pharmacy_id' => $pharmacy->id, 'expense_category_id' => $category->id, 'category_name' => $category->name, 'request_key' => $data['request_key'],
                'number' => CashManagement::number('OP', $pharmacy), 'description' => $data['description'], 'spent_on' => $data['spent_on'], 'amount' => Decimal::format(Decimal::scaled($data['amount'], 2)),
                'method' => $data['method'], 'reference' => $data['reference'] ?? null, 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
            $this->access->audit($pharmacy, $actor, 'expense.created', $id);

            return $id;
        });
    }

    public function transition(Pharmacy $pharmacy, int $actor, string $id, string $action, array $input): void
    {
        abort_unless(in_array($action, ['approve', 'post', 'cancel'], true), 404);
        $data = Validator::make($input, ['revision' => 'required|integer|min:1', 'reason' => ($action === 'cancel' ? 'required' : 'nullable').'|string|max:1000'] + ($action === 'post' ? ['confirmed' => 'required|accepted'] : []))->validate();
        $this->access->locked($pharmacy, function () use ($pharmacy, $actor, $id, $action, $data) {
            CashManagement::check($pharmacy, $actor, 'expenses.'.$action);
            $record = DB::table('operating_expenses')->where('pharmacy_id', $pharmacy->id)->where('id', $id)->lockForUpdate()->first();
            abort_unless($record, 404);
            $target = ['approve' => 'APPROVED', 'post' => 'POSTED', 'cancel' => 'CANCELLED'][$action];
            if ($record->status === $target) {
                return;
            }
            if ((int) $record->revision !== (int) $data['revision'] || ($action === 'approve' && $record->status !== 'PENDING') || ($action === 'post' && $record->status !== 'APPROVED') || ($action === 'cancel' && ! in_array($record->status, ['PENDING', 'APPROVED'], true))) {
                CashManagement::fail('Pengeluaran berubah atau terkunci. Muat ulang halaman.');
            }
            $values = ['status' => $target, 'revision' => $record->revision + 1, 'updated_at' => now()];
            if ($action === 'approve') {
                $values += ['approved_by' => $actor, 'approved_at' => now()];
            }
            if ($action === 'cancel') {
                $values += ['cancellation_reason' => $data['reason']];
            }
            if ($action === 'post') {
                CashManagement::record($pharmacy, $actor, 'EXPENSE', $id, $record->number, $record->method, -Decimal::scaled($record->amount, 2), $record->spent_on);
                $values += ['posted_by' => $actor, 'posted_at' => now()];
                Pharmacy::whereKey($pharmacy->id)->update(['has_transactions' => true]);
            }
            DB::table('operating_expenses')->where('id',$id)->update($values);
            $this->access->audit($pharmacy,$actor,'expense.'.$action,$id);
        });
    }
}
