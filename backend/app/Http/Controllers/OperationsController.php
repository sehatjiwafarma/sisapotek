<?php

namespace App\Http\Controllers;

use App\Models\Pharmacy;
use App\Services\AccessManager;
use App\Services\CashManagement;
use App\Services\OperatingExpenses;
use App\Services\OperationalReports;
use App\Services\ServiceBilling;
use App\Services\SupplierPayables;
use App\Services\SupplierReturns;
use App\Services\VisitRegistration;
use App\Support\Decimal;
use App\Support\TableSort;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Shuchkin\SimpleXLSXGen;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OperationsController extends Controller
{
    private function row(Pharmacy $pharmacy, string $table, string $id): object
    {
        $row = DB::table($table)->where('pharmacy_id', $pharmacy->id)->where('id', $id)->first();
        abort_unless($row, 404);

        return $row;
    }

    private function common(Request $request, Pharmacy $pharmacy): array
    {
        return ['requestKey' => $request->old('request_key', (string) Str::uuid()), 'today' => now($pharmacy->timezone ?: 'Asia/Jakarta')->toDateString(), 'methods' => SupplierPayables::methods()];
    }

    private function listing(Request $request, Pharmacy $pharmacy, string $table, string $view, string $title, array $extra = []): View
    {
        $filters = $request->validate(['q' => 'nullable|string|max:150', 'status' => ['nullable', Rule::in(['PENDING', 'APPROVED', 'POSTED', 'CANCELLED', 'DRAFT', 'PAID', 'OPEN', 'CLOSED'])]]);
        $query = DB::table($table)->where('pharmacy_id', $pharmacy->id);
        if ($view === 'billing') {
            $query->addSelect('service_bills.*')->addSelect(['patient_name' => DB::table('visits')
                ->select('patient_name')->whereColumn('visits.id', 'service_bills.visit_id')
                ->whereColumn('visits.pharmacy_id', 'service_bills.pharmacy_id')->limit(1)]);
        }
        $query->when($filters['q'] ?? null, function ($q, $term) use ($view) {
            $q->where(function ($q) use ($view, $term) {
                $q->whereLike('number', '%'.$term.'%');
                if ($view === 'billing') {
                    $q->orWhereExists(fn ($visits) => $visits->selectRaw('1')->from('visits')
                        ->whereColumn('visits.id', 'service_bills.visit_id')
                        ->whereColumn('visits.pharmacy_id', 'service_bills.pharmacy_id')
                        ->whereLike('visits.patient_name', '%'.$term.'%'));
                }
            });
        })->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status));
        TableSort::apply($query, ['number' => 'number', 'amount' => $table === 'operating_expenses' ? 'amount' : 'total', 'status' => 'status', 'date' => $table === 'cash_shifts' ? 'opened_at' : 'created_at'], 'date', 'desc');

        return view('operations.list', ['records' => $query->orderBy('id')->paginate(15)->withQueryString(), 'module' => $view, 'title' => $title] + $extra + $this->common($request, $pharmacy));
    }

    public function returnsIndex(Request $request, Pharmacy $pharmacy): View
    {
        return $this->listing($request, $pharmacy, 'supplier_returns', 'supplier-returns', 'Retur pembelian supplier');
    }

    public function returnCreate(Request $request, Pharmacy $pharmacy): View
    {
        $data = $request->validate(['q' => 'nullable|string|max:150', 'receipt_id' => 'nullable|uuid']);
        $receipt = ! empty($data['receipt_id']) ? $this->row($pharmacy, 'purchase_receipts', $data['receipt_id']) : null;
        abort_if($receipt && $receipt->status !== 'POSTED', 422);
        $receipts = DB::table('purchase_receipts as receipt')->join('purchase_orders as po', 'po.id', '=', 'receipt.purchase_order_id')->where('receipt.pharmacy_id', $pharmacy->id)->where('receipt.status', 'POSTED')
            ->when($data['q'] ?? null, fn ($q, $term) => $q->where(fn ($q) => $q->whereLike('receipt.number', '%'.$term.'%')->orWhereLike('receipt.invoice_number', '%'.$term.'%')->orWhereLike('po.supplier_name', '%'.$term.'%')))
            ->select('receipt.*', 'po.supplier_name')->orderByDesc('receipt.received_on')->orderBy('receipt.id')->limit(50)->get();
        $lines = $receipt ? SupplierReturns::lines($pharmacy, $receipt->id) : collect();
        foreach ($lines as $line) {
            $line->returned = SupplierReturns::returned($pharmacy, $line->id) / 1000;
        }

        return view('operations.return-create', compact('receipt', 'receipts', 'lines') + $this->common($request, $pharmacy));
    }

    public function returnStore(Request $request, Pharmacy $pharmacy, SupplierReturns $service): RedirectResponse
    {
        $id = $service->create($pharmacy, $request->user()->id, $request->all());

        return redirect()->route('supplier-returns.show', [$pharmacy, $id])->with('status', 'Retur diajukan. Periksa sebelum posting.');
    }

    public function returnShow(Request $request, Pharmacy $pharmacy, string $record): View
    {
        $record = $this->row($pharmacy, 'supplier_returns', $record);
        $receipt = $this->row($pharmacy, 'purchase_receipts', $record->purchase_receipt_id);
        $lines = DB::table('supplier_return_items')->where('supplier_return_id', $record->id)->get();

        return view('operations.return-show', compact('record', 'receipt', 'lines') + $this->common($request, $pharmacy));
    }

    public function returnAction(Request $request, Pharmacy $pharmacy, string $record, string $action, SupplierReturns $service): RedirectResponse
    {
        abort_unless(in_array($action, ['post', 'cancel', 'recover'], true), 404);
        $service->{$action}($pharmacy, $request->user()->id, $record, $request->all());

        return back()->with('status', 'Proses retur supplier berhasil dicatat.');
    }

    public function billingIndex(Request $request, Pharmacy $pharmacy): View
    {
        return $this->listing($request, $pharmacy, 'service_bills', 'billing', 'Tagihan layanan');
    }

    public function billingCreate(Request $request, Pharmacy $pharmacy): View
    {
        $data = $request->validate(['q' => 'nullable|string|max:150', 'visit_id' => 'nullable|uuid']);
        $visit = ! empty($data['visit_id']) ? $this->row($pharmacy, 'visits', $data['visit_id']) : null;
        $visits = DB::table('visits')->where('pharmacy_id', $pharmacy->id)->whereIn('status', ['REGISTERED', 'IN_PROGRESS', 'COMPLETED'])
            ->whereNotIn('id', DB::table('service_bills')->where('pharmacy_id', $pharmacy->id)->whereNotNull('open_visit_key')->select('open_visit_key'))
            ->when($data['q'] ?? null, fn ($q, $term) => $q->where(fn ($q) => $q->whereLike('number', '%'.$term.'%')->orWhereLike('patient_name', '%'.$term.'%')->orWhereLike('medical_record_number', '%'.$term.'%')))
            ->orderByDesc('registered_at')->orderBy('id')->limit(50)->get();
        $tariffs = DB::table('service_tariffs')->where('pharmacy_id', $pharmacy->id)->where('active', true)->orderBy('name')->get();
        $registeredServices = VisitRegistration::services($visit);
        foreach ($registeredServices as $registered) {
            $tariffs = $tariffs->reject(fn ($tariff) => $tariff->id === $registered['tariff_id']);
            $tariffs->push((object) ['id' => $registered['tariff_id'], 'name' => $registered['name'], 'price' => $registered['unit_price']]);
        }
        $tariffs = $tariffs->sortBy('name')->values();
        return view('operations.billing-create', compact('visit', 'visits', 'tariffs') + $this->common($request, $pharmacy));
    }

    public function billingStore(Request $request, Pharmacy $pharmacy, ServiceBilling $service): RedirectResponse
    {
        $id = $service->create($pharmacy, $request->user()->id, $request->all());

        return redirect()->route('billing.show', [$pharmacy, $id])->with('status', 'Draft tagihan layanan dibuat.');
    }

    public function billingShow(Request $request, Pharmacy $pharmacy, string $record): View
    {
        $record = $this->row($pharmacy, 'service_bills', $record);
        $visit = $this->row($pharmacy, 'visits', $record->visit_id);
        $lines = DB::table('service_bill_items')->where('service_bill_id', $record->id)->get();
        $sale = $record->sale_id ? $this->row($pharmacy, 'sales', $record->sale_id) : null;
        $medicine = $sale ? DB::table('sale_items')->where('sale_id', $sale->id)->get() : collect();

        return view('operations.billing-show', compact('record', 'visit', 'lines', 'sale', 'medicine') + $this->common($request, $pharmacy));
    }

    public function billingAction(Request $request, Pharmacy $pharmacy, string $record, string $action, ServiceBilling $service): RedirectResponse
    {
        abort_unless(in_array($action, ['pay', 'cancel'], true), 404);
        $service->{$action}($pharmacy, $request->user()->id, $record, $request->all());

        return back()->with('status', 'Tagihan layanan berhasil diproses.');
    }

    public function catalogs(Request $request, Pharmacy $pharmacy, string $kind): View
    {
        abort_unless(in_array($kind, ['tariffs', 'categories'], true), 404);
        $table = $kind === 'tariffs' ? 'service_tariffs' : 'expense_categories';
        $member = $request->attributes->get('membership');
        $permission = $kind === 'tariffs'
            ? ($member?->allows('billing.tariffs') ? 'billing.tariffs' : 'billing.view')
            : ($member?->allows('expenses.categories') ? 'expenses.categories' : 'expenses.view');
        app(AccessManager::class)->actor($pharmacy, $request->user()->id, $permission);
        $data = $request->validate(['q' => 'nullable|string|max:150', 'edit' => 'nullable|uuid']);
        $query = DB::table($table)->where('pharmacy_id', $pharmacy->id)->when($data['q'] ?? null, fn ($q, $term) => $q->whereLike('name', '%'.$term.'%'));
        TableSort::apply($query, ['name' => 'name', 'active' => 'active'] + ($kind === 'tariffs' ? ['price' => 'price'] : []), 'name');

        return view('operations.catalog', ['records' => $query->orderBy('id')->paginate(15)->withQueryString(), 'kind' => $kind, 'editing' => ! empty($data['edit']) ? $this->row($pharmacy, $table, $data['edit']) : null] + $this->common($request, $pharmacy));
    }

    public function catalogSave(Request $request, Pharmacy $pharmacy, string $kind): RedirectResponse
    {
        $data = $request->validate(['id' => 'nullable|uuid']);
        if ($kind === 'tariffs') {
            app(ServiceBilling::class)->tariff($pharmacy, $request->user()->id, $request->all(), $data['id'] ?? null);
        } elseif ($kind === 'categories') {
            app(OperatingExpenses::class)->category($pharmacy, $request->user()->id, $request->all(), $data['id'] ?? null);
        } else {
            abort(404);
        }

        return redirect()->route('operations.catalog', [$pharmacy, $kind])->with('status', 'Master berhasil disimpan.');
    }

    public function expensesIndex(Request $request, Pharmacy $pharmacy): View
    {
        return $this->listing($request, $pharmacy, 'operating_expenses', 'expenses', 'Pengeluaran operasional');
    }

    public function expenseCreate(Request $request, Pharmacy $pharmacy): View
    {
        $categories = DB::table('expense_categories')->where('pharmacy_id', $pharmacy->id)->where('active', true)->orderBy('name')->get();

        return view('operations.expense-create', compact('categories') + $this->common($request, $pharmacy));
    }

    public function expenseStore(Request $request, Pharmacy $pharmacy, OperatingExpenses $service): RedirectResponse
    {
        $id = $service->create($pharmacy, $request->user()->id, $request->all());

        return redirect()->route('expenses.show', [$pharmacy, $id])->with('status', 'Pengeluaran diajukan untuk persetujuan.');
    }

    public function expenseShow(Request $request, Pharmacy $pharmacy, string $record): View
    {
        $record = $this->row($pharmacy, 'operating_expenses', $record);

        return view('operations.expense-show', compact('record') + $this->common($request, $pharmacy));
    }

    public function expenseAction(Request $request, Pharmacy $pharmacy, string $record, string $action, OperatingExpenses $service): RedirectResponse
    {
        $service->transition($pharmacy, $request->user()->id, $record, $action, $request->all());

        return back()->with('status', 'Pengeluaran berhasil diproses.');
    }

    public function shiftsIndex(Request $request, Pharmacy $pharmacy): View
    {
        $member = app(AccessManager::class)->actor($pharmacy, $request->user()->id, 'shifts.view');
        $filters = $request->validate(['status' => ['nullable', Rule::in(['OPEN', 'CLOSED'])]]);
        $query = DB::table('cash_shifts as shift')->join('users', 'users.id', '=', 'shift.user_id')->where('shift.pharmacy_id', $pharmacy->id)->select('shift.*', 'users.name as cashier');
        if (! $member->allows('shifts.manage')) {
            $query->where('shift.user_id', $request->user()->id);
        }
        $query->when($filters['status'] ?? null, fn ($q, $status) => $q->where('shift.status', $status));
        TableSort::apply($query, ['number' => 'shift.number', 'status' => 'shift.status', 'date' => 'shift.opened_at'], 'date', 'desc');

        return view('operations.shifts', ['records' => $query->orderBy('shift.id')->paginate(15)->withQueryString(), 'enabled' => DB::table('cash_controls')->where('pharmacy_id', $pharmacy->id)->exists()] + $this->common($request, $pharmacy));
    }

    public function shiftOpen(Request $request, Pharmacy $pharmacy, CashManagement $service): RedirectResponse
    {
        $id = $service->open($pharmacy, $request->user()->id, $request->all());

        return redirect()->route('shifts.show', [$pharmacy, $id])->with('status', 'Shift dibuka. Transaksi tunai wajib melalui shift petugas yang terbuka.');
    }

    public function shiftShow(Request $request, Pharmacy $pharmacy, string $record): View
    {
        $record = $this->row($pharmacy, 'cash_shifts', $record);
        $member = app(AccessManager::class)->actor($pharmacy, $request->user()->id, 'shifts.view');
        abort_unless((int) $record->user_id === $request->user()->id || $member->allows('shifts.manage'), 403);
        $query = DB::table('cash_entries')->where('pharmacy_id', $pharmacy->id)->where('cash_shift_id', $record->id);
        $totals = (clone $query)->select('method')->selectRaw('SUM(amount) as amount')->groupBy('method')->get();
        TableSort::apply($query, ['number' => 'number', 'date' => 'created_at', 'amount' => 'amount', 'method' => 'method', 'type' => 'source_type'], 'date', 'desc');

        return view('operations.shift-show', ['record' => $record, 'expected' => CashManagement::expected($record), 'totals' => $totals, 'entries' => $query->orderBy('id')->paginate(20)->withQueryString(),
            'cashier' => DB::table('users')->where('id', $record->user_id)->value('name')] + $this->common($request, $pharmacy));
    }

    public function shiftAction(Request $request, Pharmacy $pharmacy, string $record, string $action, CashManagement $service): RedirectResponse
    {
        abort_unless(in_array($action, ['close', 'deposit'], true), 404);
        $service->{$action}($pharmacy, $request->user()->id, $record, $request->all());

        return back()->with('status', 'Shift berhasil diperbarui.');
    }

    public function reports(Request $request, Pharmacy $pharmacy): View|StreamedResponse
    {
        $member = $request->attributes->get('membership');
        $available = array_filter(OperationalReports::TYPES, fn ($label, $kind) => $member->allows('reports.'.$kind), ARRAY_FILTER_USE_BOTH);
        abort_unless($available, 403);
        $filters = $request->validate(['kind' => ['nullable', Rule::in(array_keys($available))], 'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d', 'q' => 'nullable|string|max:150', 'export' => 'nullable|in:xlsx']);
        $kind = $filters['kind'] ?? array_key_first($available);
        $today = now($pharmacy->timezone ?: 'Asia/Jakarta')->toDateString();
        $from = $filters['from'] ?? substr($today, 0, 7).'-01';
        $to = $filters['to'] ?? $today;
        if ($from > $to || Carbon::parse($from)->diffInDays($to) > 366) {
            CashManagement::fail('Pilih periode berurutan maksimal 366 hari.');
        }
        $query = OperationalReports::query($pharmacy, $kind, $from, $to);
        if ($term = $filters['q'] ?? null) {
            $query->where(fn ($q) => $q->whereLike('number', '%'.$term.'%')->orWhereLike('category', '%'.$term.'%'));
        }
        $summary = ['count' => (clone $query)->count(), 'amount' => (clone $query)->sum('amount')];
        if ($kind === 'sales') {
            $summary += OperationalReports::margin($pharmacy, clone $query);
            $summary['refunds'] = abs((clone $query)->where('category', 'REFUND')->sum('amount'));
        }
        if ($kind === 'finance') {
            $summary['debt'] = SupplierPayables::query($pharmacy)->whereNotNull('reconciled_at')->sum('outstanding');
            $summary['unconfirmed'] = SupplierPayables::query($pharmacy)->whereNull('reconciled_at')->count();
            $summary['supplier_refunds'] = DB::table('supplier_returns')->where('pharmacy_id', $pharmacy->id)->where('status', 'POSTED')->whereNull('recovered_at')->sum('refund_due');
        }
        if ($kind === 'inventory') {
            $summary['low'] = (clone $query)->whereRaw('COALESCE(available,0) <= minimum_stock')->count();
            $summary['near'] = (clone $query)->where('near_expiry', '>', 0)->count();
            $summary['expired'] = (clone $query)->where('expired', '>', 0)->count();
        }
        $columns = ['number' => 'number', 'category' => 'category', 'amount' => 'amount', 'status' => 'status'] + ($kind === 'inventory' ? ['available' => 'available', 'near' => 'near_expiry', 'expired' => 'expired'] : ['date' => fn ($q, $direction) => $q->orderBy('date', $direction)]);
        TableSort::apply($query, $columns, $kind === 'inventory' ? 'number' : 'date', 'desc');
        $query->orderBy('id');
        if (($filters['export'] ?? null) === 'xlsx') {
            $this->authorizeExport($pharmacy, $request->user()->id);
            app(AccessManager::class)->audit($pharmacy, $request->user()->id, 'report.exported', $kind, ['from' => $from, 'to' => $to]);

            return response()->streamDownload(function () use ($query, $kind) {
                $headers = $kind === 'inventory' ? ['SKU', 'Produk', 'Satuan', 'Stok fisik', 'Layak jual', 'Dekat ED', 'Kedaluwarsa'] : ['Nomor', 'Tanggal', 'Jenis', 'Metode/status', 'Nominal'];
                $rows = [array_map(fn ($label) => '<style bgcolor="#18765B" color="#FFFFFF"><b>'.$label.'</b></style>', $headers)];
                foreach ($query->cursor() as $row) {
                    $cells = $kind === 'inventory' ? [$row->number, $row->category, $row->status, $row->amount, $row->available, $row->near_expiry, $row->expired] : [$row->number, $row->date, $row->category, $row->status, $row->amount];
                    foreach ($cells as $index => &$cell) {
                        // Force identifiers and user text to literal strings, including leading zeros and markup.
                        $cell = $index < ($kind === 'inventory' ? 3 : 4)
                            ? "\0".(string) $cell
                            : '<style nf="'.($kind === 'inventory' ? '#,##0.####' : '#,##0.00').'">'.($cell ?? 0).'</style>';
                    }
                    unset($cell);
                    $rows[] = $cells;
                }
                $book = SimpleXLSXGen::fromArray($rows, 'Laporan')->freezePanes('A2');
                $book->autoFilter('A1:'.($kind === 'inventory' ? 'G' : 'E').count($rows));
                foreach ($headers as $index => $label) {
                    $book->setColWidth($index + 1, $kind === 'inventory' && $index === 1 ? 40 : 24);
                }
                echo (string) $book;
            }, 'laporan-'.$kind.'-'.$from.'-'.$to.'.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
        }

        return view('operations.reports', ['records' => $query->paginate(20)->withQueryString(), 'summary' => $summary, 'kind' => $kind, 'types' => $available, 'from' => $from, 'to' => $to, 'today' => $today]);
    }

    public function profitLoss(Request $request, Pharmacy $pharmacy): View
    {
        abort_unless($request->attributes->get('membership')?->isOwner(), 403);
        $filters = $request->validate(['from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d']);
        $today = now($pharmacy->timezone)->toDateString();
        $from = $filters['from'] ?? substr($today, 0, 7).'-01';
        $to = $filters['to'] ?? $today;
        if ($from > $to || Carbon::parse($from)->diffInDays($to) > 366) {
            CashManagement::fail('Pilih periode berurutan maksimal 366 hari.');
        }
        $report = OperationalReports::query($pharmacy, 'sales', $from, $to);
        $summary = OperationalReports::margin($pharmacy, clone $report);
        $summary['services'] = Decimal::scaled((string) (clone $report)->where('category', 'SERVICE')->sum('amount'), 2);
        $summary['expenses'] = Decimal::scaled((string) DB::table('operating_expenses')->where('pharmacy_id', $pharmacy->id)->where('status', 'POSTED')->whereBetween('spent_on', [$from, $to])->sum('amount'), 2);
        $summary['gross'] = $summary['margin'] === null ? null : $summary['margin'] + $summary['services'];
        $summary['net'] = $summary['gross'] === null ? null : $summary['gross'] - $summary['expenses'];

        return view('operations.profit-loss', compact('summary', 'from', 'to'));
    }

    private function authorizeExport(Pharmacy $pharmacy, int $actor): void
    {
        app(AccessManager::class)->actor($pharmacy, $actor, 'reports.export');
    }
}
