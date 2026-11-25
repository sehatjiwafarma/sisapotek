<?php

namespace App\Http\Controllers;

use App\Models\Pharmacy;
use App\Services\AccessManager;
use App\Services\SupplierPayables;
use App\Support\TableSort;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class SupplierPayableController extends Controller
{
    public function index(Request $request, Pharmacy $pharmacy): View
    {
        $filters = $request->validate(['q' => 'nullable|string|max:150', 'supplier' => 'nullable|uuid', 'status' => ['nullable', Rule::in(array_keys(SupplierPayables::invoiceLabels()))], 'due' => ['nullable', Rule::in(['overdue', 'today'])]]);
        $query = SupplierPayables::query($pharmacy);
        if ($term = trim($filters['q'] ?? '')) {
            $query->where(fn ($query) => $query->whereLike('number', '%'.$term.'%')->orWhereLike('supplier_name', '%'.$term.'%')->orWhereLike('supplier_invoice_number', '%'.$term.'%'));
        }
        $query->when($filters['supplier'] ?? null, fn ($query, $id) => $query->where('supplier_id', $id));
        $query->when($filters['status'] ?? null, fn ($query, $status) => $query->where('balance_status', $status));
        $today = now($pharmacy->timezone ?: 'Asia/Jakarta')->toDateString();
        if ($filters['due'] ?? null) {
            $query->whereNotNull('reconciled_at')->where('outstanding', '>', 0)->where('due_on', $filters['due'] === 'overdue' ? '<' : '=', $today);
        }
        $summary = (clone $query)->selectRaw('COALESCE(SUM(CASE WHEN reconciled_at IS NOT NULL THEN outstanding ELSE 0 END),0) AS outstanding')
            ->selectRaw('COALESCE(SUM(CASE WHEN reconciled_at IS NOT NULL AND due_on < ? AND outstanding > 0 THEN outstanding ELSE 0 END),0) AS overdue', [$today])
            ->selectRaw('SUM(CASE WHEN reconciled_at IS NULL THEN 1 ELSE 0 END) AS unconfirmed')->first();
        TableSort::apply($query, ['number' => 'number', 'supplier' => 'supplier_name', 'received' => 'received_on', 'due' => 'due_on', 'amount' => 'amount', 'paid' => fn ($query, $direction) => $query->orderByRaw('opening_paid_amount + paid_amount '.$direction), 'outstanding' => 'outstanding', 'status' => 'balance_status'], 'due');

        return view('payables.index', ['invoices' => $query->orderBy('id')->paginate(15)->withQueryString(), 'summary' => $summary, 'today' => $today,
            'suppliers' => $pharmacy->suppliers()->orderBy('name')->get(['id', 'name']), 'invoiceLabels' => SupplierPayables::invoiceLabels()]);
    }

    private function invoice(Pharmacy $pharmacy, string $id): object
    {
        $invoice = SupplierPayables::query($pharmacy)->where('id', $id)->first();
        abort_unless($invoice, 404);

        return $invoice;
    }

    public function show(Request $request, Pharmacy $pharmacy, string $invoice): View
    {
        $invoice = $this->invoice($pharmacy, $invoice);
        $payments = DB::table('supplier_payments')->where('pharmacy_id', $pharmacy->id)->where('supplier_invoice_id', $invoice->id)
            ->select('id', 'number', 'paid_on', 'amount', 'method', 'status', 'created_at');
        TableSort::apply($payments, ['number' => 'number', 'date' => 'paid_on', 'amount' => 'amount', 'method' => 'method', 'status' => 'status'], 'date', 'desc', 'payments_');
        $reconciler = $invoice->reconciled_by ? DB::table('users')->where('id', $invoice->reconciled_by)->value('name') : null;

        return view('payables.show', ['invoice' => $invoice, 'balance' => SupplierPayables::balances($pharmacy, $invoice),
            'payments' => $payments->orderBy('id')->paginate(10, ['*'], 'payments_page')->withQueryString(), 'requestKey' => $request->old('request_key', (string) Str::uuid()),
            'today' => now($pharmacy->timezone ?: 'Asia/Jakarta')->toDateString(), 'reconciler' => $reconciler,
            'invoiceLabels' => SupplierPayables::invoiceLabels(), 'paymentLabels' => SupplierPayables::paymentLabels(), 'methods' => SupplierPayables::methods()]);
    }

    public function reconcile(Request $request, Pharmacy $pharmacy, string $invoice, SupplierPayables $payables): RedirectResponse
    {
        $data = $request->validate(['revision' => 'required|integer|min:1']);
        $payables->reconcile($pharmacy, $request->user()->id, $invoice, (int) $data['revision'], $request->all());

        return redirect()->route('payables.show', [$pharmacy, $invoice])->with('status', 'Saldo awal dikonfirmasi. Pembayaran selanjutnya mengikuti sisa utang tersebut.');
    }

    public function store(Request $request, Pharmacy $pharmacy, string $invoice, SupplierPayables $payables): RedirectResponse
    {
        $request->validate(['proof' => 'nullable|file|mimes:png,jpg,jpeg,pdf|max:5120']);
        $id = $payables->create($pharmacy, $request->user()->id, $invoice, $request->all(), $request->file('proof'));

        return redirect()->route('payables.payment', [$pharmacy, $id])->with('status', 'Pembayaran diajukan. Periksa dan setujui sebelum pencatatan pembayaran.');
    }

    private function details(Pharmacy $pharmacy, string $id): array
    {
        $payment = DB::table('supplier_payments')->where('pharmacy_id', $pharmacy->id)->where('id', $id)->first();
        abort_unless($payment, 404);
        $invoice = $this->invoice($pharmacy, $payment->supplier_invoice_id);
        $hasProof = (bool) $payment->proof_path;
        unset($payment->proof_path,$payment->proof_mime,$payment->proof_sha256,$payment->request_key);
        $actors = DB::table('users')->whereIn('id', array_filter([$payment->created_by, $payment->approved_by, $payment->posted_by, $payment->cancelled_by]))->pluck('name', 'id');

        return compact('payment', 'invoice', 'hasProof', 'actors') + ['balance' => SupplierPayables::balances($pharmacy, $invoice), 'paymentLabels' => SupplierPayables::paymentLabels(), 'methods' => SupplierPayables::methods()];
    }

    public function payment(Pharmacy $pharmacy, string $payment): View
    {
        return view('payables.payment', $this->details($pharmacy, $payment));
    }

    public function approve(Request $request, Pharmacy $pharmacy, string $payment, SupplierPayables $payables): RedirectResponse
    {
        $data = $request->validate(['revision' => 'required|integer|min:1']);
        $payables->approve($pharmacy, $request->user()->id, $payment, (int) $data['revision']);

        return redirect()->route('payables.payment', [$pharmacy, $payment])->with('status', 'Pengajuan disetujui. Dana belum mengurangi utang sampai pembayaran dicatat.');
    }

    public function post(Request $request, Pharmacy $pharmacy, string $payment, SupplierPayables $payables): RedirectResponse
    {
        $data = $request->validate(['revision' => 'required|integer|min:1', 'paid_confirmed' => 'required|accepted', 'proof' => 'nullable|file|mimes:png,jpg,jpeg,pdf|max:5120']);
        $payables->post($pharmacy, $request->user()->id, $payment, (int) $data['revision'], true, $request->file('proof'));

        return redirect()->route('payables.payment', [$pharmacy, $payment])->with('status', 'Pembayaran tercatat dan sisa utang diperbarui satu kali.');
    }

    public function cancel(Request $request, Pharmacy $pharmacy, string $payment, SupplierPayables $payables): RedirectResponse
    {
        $data = $request->validate(['revision' => 'required|integer|min:1', 'reason' => 'required|string|max:1000']);
        $payables->cancel($pharmacy, $request->user()->id, $payment, (int) $data['revision'], $data['reason']);

        return redirect()->route('payables.payment', [$pharmacy, $payment])->with('status', 'Pengajuan dibatalkan. Sisa utang tidak dikurangi.');
    }

    public function proof(Request $request, Pharmacy $pharmacy, string $payment): Response
    {
        $record = DB::table('supplier_payments')->where('pharmacy_id', $pharmacy->id)->where('id', $payment)->first();
        abort_unless($record && $record->proof_path && Storage::disk('local')->exists($record->proof_path), 404);
        try {
            $bytes = Crypt::decryptString(Storage::disk('local')->get($record->proof_path));
        } catch (DecryptException $exception) {
            abort(404);
        }
        abort_unless(hash_equals($record->proof_sha256, hash('sha256', $bytes)), 404);
        app(AccessManager::class)->audit($pharmacy, $request->user()->id, 'supplier_payment.proof_viewed', $payment);
        $extension = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'application/pdf' => 'pdf'][$record->proof_mime] ?? 'bin';

        return response($bytes, 200, ['Content-Type' => $record->proof_mime, 'Content-Disposition' => 'attachment; filename="bukti-pembayaran.'.$extension.'"']);
    }

    public function receipt(Pharmacy $pharmacy, string $payment): View
    {
        $data = $this->details($pharmacy, $payment);
        abort_unless($data['payment']->status === 'POSTED', 422, 'Bukti pencatatan hanya tersedia untuk pembayaran yang diposting.');

        return view('payables.receipt', $data);
    }
}
