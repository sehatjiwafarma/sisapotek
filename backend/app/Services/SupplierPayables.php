<?php

namespace App\Services;

use App\Models\Pharmacy;
use App\Support\Decimal;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SupplierPayables
{
    public function __construct(private AccessManager $access) {}

    public static function paymentLabels(): array
    {
        return ['PENDING' => 'Menunggu persetujuan', 'APPROVED' => 'Disetujui', 'POSTED' => 'Pembayaran tercatat', 'CANCELLED' => 'Dibatalkan'];
    }

    public static function invoiceLabels(): array
    {
        return ['UNRECONCILED' => 'Perlu konfirmasi saldo', 'UNPAID' => 'Belum dibayar', 'PARTIAL' => 'Dibayar sebagian', 'PAID' => 'Lunas'];
    }

    public static function methods(): array
    {
        return ['cash' => 'Tunai', 'transfer' => 'Transfer manual', 'qris' => 'QRIS manual', 'debit' => 'Debit manual'];
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['payable' => $message]);
    }

    private function check(Pharmacy $pharmacy, int $actor, string $permission): void
    {
        $this->access->actor($pharmacy, $actor, 'payables.view');
        $this->access->actor($pharmacy, $actor, $permission);
        abort_unless($pharmacy->fresh()->status === 'ACTIVE', 422, 'Apotek harus aktif untuk mencatat pembayaran supplier.');
    }

    /** Called in the receipt posting transaction; never from draft orders or receipts. */
    public static function issue(Pharmacy $pharmacy, int $actor, object $receipt, object $order): void
    {
        if ($order->payment_type !== 'credit') {
            return;
        }
        abort_unless($receipt->pharmacy_id === $pharmacy->id && $order->pharmacy_id === $pharmacy->id && $receipt->status === 'POSTED', 422);
        if (DB::table('supplier_invoices')->where('purchase_receipt_id', $receipt->id)->exists()) {
            return;
        }
        $id = (string) Str::uuid();
        $received = Carbon::parse($receipt->received_on);
        DB::table('supplier_invoices')->insert(['id' => $id, 'pharmacy_id' => $pharmacy->id, 'supplier_id' => $order->supplier_id, 'purchase_receipt_id' => $receipt->id,
            'number' => 'BILL-'.$receipt->number, 'supplier_name' => $order->supplier_name, 'supplier_invoice_number' => $receipt->invoice_number,
            'received_on' => $received->toDateString(), 'payment_days' => $order->payment_days, 'due_on' => $received->copy()->addDays($order->payment_days)->toDateString(),
            'amount' => $receipt->total, 'opening_paid_amount' => 0, 'reconciled_by' => $actor, 'reconciled_at' => now(), 'revision' => 1, 'created_at' => now(), 'updated_at' => now()]);
        app(AccessManager::class)->audit($pharmacy, $actor, 'supplier_invoice.issued', $id, ['receipt_id' => $receipt->id, 'amount' => (string) $receipt->total]);
    }

    public static function query(Pharmacy $pharmacy): Builder
    {
        $payments = DB::table('supplier_payments')->where('pharmacy_id', $pharmacy->id)->select('supplier_invoice_id')
            ->selectRaw("SUM(CASE WHEN status = 'POSTED' THEN amount ELSE 0 END) AS paid_amount")
            ->selectRaw("SUM(CASE WHEN status = 'APPROVED' THEN amount ELSE 0 END) AS reserved_amount")->groupBy('supplier_invoice_id');
        $query = DB::table('supplier_invoices as invoice')->where('invoice.pharmacy_id', $pharmacy->id)
            ->leftJoinSub($payments, 'payments', 'payments.supplier_invoice_id', '=', 'invoice.id')->select('invoice.*')
            ->selectRaw('COALESCE(payments.paid_amount,0) AS paid_amount, COALESCE(payments.reserved_amount,0) AS reserved_amount')
            ->selectRaw('invoice.amount - invoice.opening_paid_amount - invoice.credit_amount - COALESCE(payments.paid_amount,0) AS outstanding')
            ->selectRaw("CASE WHEN invoice.reconciled_at IS NULL THEN 'UNRECONCILED' WHEN invoice.amount - invoice.opening_paid_amount - invoice.credit_amount - COALESCE(payments.paid_amount,0) <= 0 THEN 'PAID' WHEN invoice.opening_paid_amount + COALESCE(payments.paid_amount,0) > 0 THEN 'PARTIAL' ELSE 'UNPAID' END AS balance_status");

        return DB::query()->fromSub($query, 'bills');
    }

    private function invoice(Pharmacy $pharmacy, string $id): object
    {
        $invoice = DB::table('supplier_invoices')->where('pharmacy_id', $pharmacy->id)->where('id', $id)->lockForUpdate()->first();
        abort_unless($invoice, 404);

        return $invoice;
    }

    public static function balances(Pharmacy $pharmacy, object $invoice): array
    {
        $paid = Decimal::scaled(DB::table('supplier_payments')->where('pharmacy_id', $pharmacy->id)->where('supplier_invoice_id', $invoice->id)->where('status', 'POSTED')->sum('amount'), 2);
        $reserved = Decimal::scaled(DB::table('supplier_payments')->where('pharmacy_id', $pharmacy->id)->where('supplier_invoice_id', $invoice->id)->where('status', 'APPROVED')->sum('amount'), 2);
        $outstanding = Decimal::scaled($invoice->amount, 2) - Decimal::scaled($invoice->opening_paid_amount, 2) - Decimal::scaled($invoice->credit_amount, 2) - $paid;

        return ['paid' => $paid, 'reserved' => $reserved, 'outstanding' => $outstanding, 'available' => max(0, $outstanding - $reserved)];
    }

    public function reconcile(Pharmacy $pharmacy, int $actor, string $id, int $revision, array $input): void
    {
        $data = Validator::make($input, ['outstanding' => 'required|numeric|min:0|max:9999999999999999.99|decimal:0,2', 'reason' => 'required|string|max:1000'])->validate();
        $this->access->locked($pharmacy, function () use ($pharmacy, $actor, $id, $revision, $data) {
            $this->check($pharmacy, $actor, 'payables.reconcile');
            $invoice = $this->invoice($pharmacy, $id);
            if ($invoice->reconciled_at || (int) $invoice->revision !== $revision || DB::table('supplier_payments')->where('supplier_invoice_id', $id)->exists()) {
                $this->fail('Saldo awal sudah dikonfirmasi atau tagihan berubah. Muat ulang halaman.');
            }
            $total = Decimal::scaled($invoice->amount, 2);
            $remaining = Decimal::scaled($data['outstanding'], 2);
            if ($remaining > $total) {
                $this->fail('Sisa saldo awal tidak boleh melebihi nilai penerimaan.');
            }
            DB::table('supplier_invoices')->where('id', $id)->update(['opening_paid_amount' => Decimal::format($total - $remaining), 'reconciled_at' => now(),
                'reconciled_by' => $actor, 'reconciliation_reason' => $data['reason'], 'revision' => $revision + 1, 'updated_at' => now()]);
            $this->access->audit($pharmacy, $actor, 'supplier_invoice.reconciled', $id, ['opening_paid_amount' => Decimal::format($total - $remaining)]);
        });
    }

    public function create(Pharmacy $pharmacy, int $actor, string $id, array $input, ?UploadedFile $proof = null): string
    {
        $data = Validator::make(array_replace($input, ['proof' => $proof]), [
            'request_key' => 'required|uuid', 'amount' => 'required|numeric|gt:0|max:9999999999999999.99|decimal:0,2',
            'paid_on' => 'required|date_format:Y-m-d', 'method' => ['required', Rule::in(array_keys(self::methods()))],
            'reference' => 'nullable|required_unless:method,cash|string|max:100', 'notes' => 'nullable|string|max:2000',
            'proof' => 'nullable|file|mimes:png,jpg,jpeg,pdf|max:5120',
        ])->validate();
        $paths = [];
        try {
            return $this->access->locked($pharmacy, function () use ($pharmacy, $actor, $id, $data, $proof, &$paths) {
                $this->check($pharmacy, $actor, 'payables.create');
                $invoice = $this->invoice($pharmacy, $id);
                $existing = DB::table('supplier_payments')->where('pharmacy_id', $pharmacy->id)->where('request_key', $data['request_key'])->first();
                if ($existing) {
                    abort_unless($existing->supplier_invoice_id === $id, 422);

                    return $existing->id;
                }
                if (! $invoice->reconciled_at) {
                    $this->fail('Konfirmasikan saldo awal penerimaan lama sebelum mengajukan pembayaran.');
                }
                if ($data['paid_on'] < $invoice->received_on || $data['paid_on'] > now($pharmacy->timezone ?: 'Asia/Jakarta')->toDateString()) {
                    $this->fail('Tanggal pembayaran harus sejak tanggal penerimaan sampai hari ini.');
                }
                $balance = self::balances($pharmacy, $invoice);
                $amount = Decimal::scaled($data['amount'], 2);
                if ($amount > $balance['available']) {
                    $this->fail('Nominal melebihi sisa utang yang belum dialokasikan ke pembayaran disetujui.');
                }
                $paymentId = (string) Str::uuid();
                $file = [];
                if ($proof) {
                    $bytes = $proof->getContent();
                    $path = 'supplier-payment-proofs/'.$pharmacy->id.'/'.$paymentId.'.enc';
                    $paths[] = $path;
                    if (! Storage::disk('local')->put($path, Crypt::encryptString($bytes))) {
                        throw new \RuntimeException('Bukti pembayaran belum dapat disimpan.');
                    }
                    $file = ['proof_path' => $path, 'proof_mime' => $proof->getMimeType(), 'proof_sha256' => hash('sha256', $bytes)];
                }
                DB::table('supplier_payments')->insert(['id' => $paymentId, 'pharmacy_id' => $pharmacy->id, 'supplier_invoice_id' => $id,
                    'request_key' => $data['request_key'], 'number' => 'PAY-'.now($pharmacy->timezone ?: 'Asia/Jakarta')->format('Ymd').'-'.strtoupper(Str::random(8)),
                    'status' => 'PENDING', 'revision' => 1, 'amount' => Decimal::format($amount), 'paid_on' => $data['paid_on'], 'method' => $data['method'],
                    'reference' => $data['reference'] ?? null, 'notes' => $data['notes'] ?? null, 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()] + $file);
                $this->access->audit($pharmacy, $actor, 'supplier_payment.created', $paymentId, ['invoice_id' => $id, 'amount' => Decimal::format($amount)]);

                return $paymentId;
            });
        } finally {
            foreach ($paths as $path) {
                if (! DB::table('supplier_payments')->where('proof_path', $path)->exists()) {
                    Storage::disk('local')->delete($path);
                }
            }
        }
    }

    private function payment(Pharmacy $pharmacy, string $id): object
    {
        $payment = DB::table('supplier_payments')->where('pharmacy_id', $pharmacy->id)->where('id', $id)->lockForUpdate()->first();
        abort_unless($payment, 404);

        return $payment;
    }

    public function approve(Pharmacy $pharmacy, int $actor, string $id, int $revision): void
    {
        $this->access->locked($pharmacy, function () use ($pharmacy, $actor, $id, $revision) {
            $this->check($pharmacy, $actor, 'payables.approve');
            $payment = $this->payment($pharmacy, $id);
            $invoice = $this->invoice($pharmacy, $payment->supplier_invoice_id);
            if (in_array($payment->status, ['APPROVED', 'POSTED'], true)) {
                return;
            }
            if ($payment->status !== 'PENDING' || (int) $payment->revision !== $revision || ! $invoice->reconciled_at) {
                $this->fail('Pengajuan berubah, dibatalkan, atau saldo awal belum dikonfirmasi.');
            }
            if (Decimal::scaled($payment->amount, 2) > self::balances($pharmacy, $invoice)['available']) {
                $this->fail('Sisa utang berubah karena pembayaran lain. Pengajuan ini melebihi sisa yang dapat disetujui.');
            }
            DB::table('supplier_payments')->where('id', $id)->update(['status' => 'APPROVED', 'approved_by' => $actor, 'approved_at' => now(), 'revision' => $revision + 1, 'updated_at' => now()]);
            $this->access->audit($pharmacy, $actor, 'supplier_payment.approved', $id);
        });
    }

    public function post(Pharmacy $pharmacy, int $actor, string $id, int $revision, bool $confirmed, ?UploadedFile $proof = null): void
    {
        Validator::make(['proof' => $proof], ['proof' => 'nullable|file|mimes:png,jpg,jpeg,pdf|max:5120'])->validate();
        $paths = [];
        try {
            $this->access->locked($pharmacy, function () use ($pharmacy, $actor, $id, $revision, $confirmed, $proof, &$paths) {
                $this->check($pharmacy, $actor, 'payables.post');
                $payment = $this->payment($pharmacy, $id);
                $invoice = $this->invoice($pharmacy, $payment->supplier_invoice_id);
                if ($payment->status === 'POSTED') {
                    return;
                }
                if (! $confirmed || $payment->status !== 'APPROVED' || (int) $payment->revision !== $revision) {
                    $this->fail('Setujui pengajuan dan konfirmasikan dana sudah dibayarkan sebelum pencatatan.');
                }
                if (! $invoice->reconciled_at || Decimal::scaled($payment->amount, 2) > self::balances($pharmacy, $invoice)['outstanding']) {
                    $this->fail('Nominal melebihi sisa utang terbaru.');
                }
                $file = [];
                if ($proof) {
                    if ($payment->proof_path) {
                        $this->fail('Bukti sudah dilampirkan. Batalkan pengajuan dan ajukan ulang bila bukti perlu dikoreksi.');
                    }
                    $bytes = $proof->getContent();
                    $path = 'supplier-payment-proofs/'.$pharmacy->id.'/'.Str::uuid().'.enc';
                    $paths[] = $path;
                    if (! Storage::disk('local')->put($path, Crypt::encryptString($bytes))) {
                        throw new \RuntimeException('Bukti pembayaran belum dapat disimpan.');
                    }
                    $file = ['proof_path' => $path, 'proof_mime' => $proof->getMimeType(), 'proof_sha256' => hash('sha256', $bytes)];
                }
                if ($payment->proof_path) {
                    try {
                        $valid = Storage::disk('local')->exists($payment->proof_path)
                            && hash_equals($payment->proof_sha256, hash('sha256', Crypt::decryptString(Storage::disk('local')->get($payment->proof_path))));
                    } catch (DecryptException $exception) {
                        $valid = false;
                    }
                    if (! $valid) {
                        $this->fail('Bukti pembayaran tidak tersedia atau tidak sesuai. Batalkan pengajuan lalu ajukan kembali dengan bukti yang benar.');
                    }
                }
                DB::table('supplier_payments')->where('id', $id)->update(['status' => 'POSTED', 'posted_by' => $actor, 'posted_at' => now(), 'revision' => $revision + 1, 'updated_at' => now()] + $file);
                DB::table('supplier_invoices')->where('id', $invoice->id)->increment('revision', 1, ['updated_at' => now()]);
                Pharmacy::whereKey($pharmacy->id)->update(['has_transactions' => true]);
                CashManagement::record($pharmacy, $actor, 'SUPPLIER_PAYMENT', $id, $payment->number, $payment->method, -Decimal::scaled($payment->amount, 2), $payment->paid_on);
                $this->access->audit($pharmacy, $actor, 'supplier_payment.posted', $id, ['invoice_id' => $invoice->id, 'amount' => $payment->amount]);
            });
        } finally {
            foreach ($paths as $path) {
                if (! DB::table('supplier_payments')->where('proof_path', $path)->exists()) {
                    Storage::disk('local')->delete($path);
                }
            }
        }
    }

    public function cancel(Pharmacy $pharmacy, int $actor, string $id, int $revision, string $reason): void
    {
        $data = Validator::make(['reason' => $reason], ['reason' => 'required|string|max:1000'])->validate();
        $this->access->locked($pharmacy, function () use ($pharmacy, $actor, $id, $revision, $data) {
            $this->check($pharmacy, $actor, 'payables.cancel');
            $payment = $this->payment($pharmacy, $id);
            if (! in_array($payment->status, ['PENDING', 'APPROVED'], true) || (int) $payment->revision !== $revision) {
                $this->fail('Pembayaran berubah atau sudah diposting; pembatalan ditolak.');
            }
            DB::table('supplier_payments')->where('id', $id)->update(['status' => 'CANCELLED', 'cancelled_by' => $actor, 'cancelled_at' => now(),
                'cancellation_reason' => $data['reason'], 'revision' => $revision + 1, 'updated_at' => now()]);
            $this->access->audit($pharmacy, $actor, 'supplier_payment.cancelled', $id);
        });
    }
}
