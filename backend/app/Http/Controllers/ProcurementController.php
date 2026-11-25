<?php

namespace App\Http\Controllers;

use App\Models\Pharmacy;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Services\AccessManager;
use App\Services\Procurement;
use App\Services\PurchaseLetter;
use App\Support\TableSort;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProcurementController extends Controller
{
    public function suppliers(Request $request, Pharmacy $pharmacy)
    {
        $filters = $request->validate(['q' => 'nullable|string|max:150', 'status' => ['nullable', Rule::in(['active', 'inactive'])]]);
        $query = $pharmacy->suppliers();
        if ($term = $filters['q'] ?? null) {
            $query->where(fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($term).'%'])->orWhereRaw('LOWER(code) LIKE ?', ['%'.mb_strtolower($term).'%'])->orWhere('phone', 'like', '%'.$term.'%'));
        }
        if ($filters['status'] ?? null) {
            $query->where('active', $filters['status'] === 'active');
        }
        TableSort::apply($query, ['name' => 'name', 'contact' => 'contact_name', 'phone' => 'phone', 'terms' => 'payment_days', 'status' => fn ($q, $d) => $q->orderBy('active', $d === 'asc' ? 'desc' : 'asc')], 'name');

        return view('procurement.suppliers', ['suppliers' => $query->orderBy('id')->paginate(15)->withQueryString()]);
    }

    public function supplierCreate(Pharmacy $pharmacy)
    {
        return view('procurement.supplier-form', ['supplier' => new Supplier(['payment_days' => 0])]);
    }

    public function supplierEdit(Pharmacy $pharmacy, string $supplier)
    {
        return view('procurement.supplier-form', ['supplier' => $pharmacy->suppliers()->findOrFail($supplier)]);
    }

    public function supplierSave(Request $request, Pharmacy $pharmacy, AccessManager $manager, ?string $supplier = null)
    {
        $data = $request->validate(['name' => 'required|string|max:150', 'contact_name' => 'nullable|string|max:100', 'phone' => 'nullable|string|max:30',
            'email' => 'nullable|email|max:254', 'address' => 'nullable|string|max:1000', 'tax_number' => 'nullable|string|max:30', 'payment_days' => 'required|integer|min:0|max:3650']);
        $manager->locked($pharmacy, function () use ($manager, $pharmacy, $request, $supplier, $data) {
            $manager->actor($pharmacy, $request->user()->id, $supplier ? 'suppliers.update' : 'suppliers.create');
            $record = $supplier ? $pharmacy->suppliers()->lockForUpdate()->findOrFail($supplier) : $pharmacy->suppliers()->make();
            $before = $record->exists ? $record->getAttributes() : null;
            $record->fill($data);
            if (! $supplier) {
                $record->code = 'SUP-'.strtoupper(Str::random(10));
                $record->active = true;
            }
            $record->save();
            $manager->audit($pharmacy, $request->user()->id, $supplier ? 'supplier.updated' : 'supplier.created', $record->id, ['before' => $before, 'after' => $record->getAttributes()]);
        });

        return redirect()->route('suppliers.index', $pharmacy)->with('status', 'Data supplier disimpan.');
    }

    public function supplierStatus(Request $request, Pharmacy $pharmacy, string $supplier, AccessManager $manager)
    {
        $request->validate(['active' => 'required|boolean']);
        $manager->locked($pharmacy, function () use ($manager, $pharmacy, $request, $supplier) {
            $manager->actor($pharmacy, $request->user()->id, 'suppliers.deactivate');
            $record = $pharmacy->suppliers()->lockForUpdate()->findOrFail($supplier);
            $record->update(['active' => $request->boolean('active')]);
            $manager->audit($pharmacy, $request->user()->id, 'supplier.status_changed', $record->id, ['active' => $record->active]);
        });

        return back()->with('status', 'Status supplier diperbarui.');
    }

    public function orders(Request $request, Pharmacy $pharmacy)
    {
        $filters = $request->validate(['q' => 'nullable|string|max:150', 'status' => ['nullable', Rule::in(array_keys(PurchaseOrder::STATUSES))]]);
        $query = $pharmacy->purchaseOrders();
        if ($term = $filters['q'] ?? null) {
            $query->where(fn ($q) => $q->whereRaw('LOWER(number) LIKE ?', ['%'.mb_strtolower($term).'%'])->orWhereRaw('LOWER(supplier_name) LIKE ?', ['%'.mb_strtolower($term).'%']));
        }
        $query->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status));
        TableSort::apply($query, ['number' => 'number', 'date' => 'ordered_on', 'supplier' => 'supplier_name', 'total' => 'total', 'status' => 'status'], 'date', 'desc');

        return view('procurement.orders', ['orders' => $query->orderBy('id')->paginate(15)->withQueryString()]);
    }

    public function orderCreate(Pharmacy $pharmacy)
    {
        return $this->orderForm($pharmacy, new PurchaseOrder(['ordered_on' => now($pharmacy->settings?->timezone ?? 'Asia/Jakarta')->toDateString(), 'payment_type' => 'cash', 'payment_days' => 0]));
    }

    public function orderEdit(Pharmacy $pharmacy, string $order)
    {
        $record = $pharmacy->purchaseOrders()->with('items')->findOrFail($order);
        abort_unless($record->status === 'DRAFT', 422, 'Hanya PO draft yang dapat diubah.');

        return $this->orderForm($pharmacy, $record);
    }

    private function orderForm(Pharmacy $pharmacy, PurchaseOrder $order)
    {
        return view('procurement.order-form', ['order' => $order, 'suppliers' => $pharmacy->suppliers()->where('active', true)->orderBy('name')->get(), 'products' => $pharmacy->products()->where('active', true)->orderBy('name')->orderBy('brand')->get()]);
    }

    public function orderSave(Request $request, Pharmacy $pharmacy, Procurement $service, ?string $order = null)
    {
        $data = $request->validate(['supplier_id' => 'required|uuid', 'ordered_on' => 'required|date_format:Y-m-d', 'expected_on' => 'nullable|date_format:Y-m-d|after_or_equal:ordered_on',
            'payment_type' => ['required', Rule::in(['cash', 'credit'])], 'payment_days' => 'required|integer|min:0|max:3650', 'notes' => 'nullable|string|max:2000',
            'items' => 'required|array|min:1|max:100', 'items.*.product_id' => 'required|uuid|distinct', 'items.*.unit_type' => ['required', Rule::in(['base', 'purchase'])],
            'items.*.quantity' => 'required|numeric|gt:0|max:10000|decimal:0,3', 'items.*.unit_price' => 'required|numeric|min:0|max:10000000|decimal:0,2',
            'items.*.discount' => 'required|numeric|min:0|max:100000000000|decimal:0,2', 'items.*.tax' => 'required|numeric|min:0|max:100000000000|decimal:0,2']);
        if ($data['payment_type'] === 'credit' && $data['payment_days'] < 1) {
            throw ValidationException::withMessages(['payment_days' => 'Termin kredit minimal 1 hari.']);
        }
        $record = $service->saveOrder($pharmacy, $request->user()->id, $data, $order);

        return redirect()->route('purchases.show', [$pharmacy, $record])->with('status', 'Draft pembelian disimpan.');
    }

    public function orderShow(Pharmacy $pharmacy, string $order)
    {
        return view('procurement.order-show', ['order' => $pharmacy->purchaseOrders()->with(['items', 'receipts' => fn ($q) => $q->latest()])->findOrFail($order)]);
    }

    public function finalize(Request $request, Pharmacy $pharmacy, string $order, Procurement $service)
    {
        $service->finalize($pharmacy, $request->user()->id, $order);

        return back()->with('status', 'PO dikonfirmasi menjadi pesanan.');
    }

    public function letterForm(Pharmacy $pharmacy, string $order, PurchaseLetter $letters)
    {
        $record = $pharmacy->purchaseOrders()->with('items')->findOrFail($order);

        return view('procurement.letter-form', $letters->context($pharmacy, $record));
    }

    public function letterExport(Request $request, Pharmacy $pharmacy, string $order, PurchaseLetter $letters)
    {
        $record = $pharmacy->purchaseOrders()->with('items')->findOrFail($order);
        $data = $request->validate([
            'kind' => 'required|in:obat,psikotropika', 'signer' => 'required|string|max:150',
            'signer_address' => 'required|string|max:1000', 'signer_phone' => 'nullable|string|max:30',
            'sip' => 'required|string|max:100', 'supplier_address' => 'required|string|max:1000',
            'city' => 'required|string|max:150', 'ingredients' => 'required|array',
            'ingredients.*' => 'nullable|string|max:500', 'strengths' => 'required|array',
            'strengths.*' => 'nullable|string|max:500',
        ]);
        $context = $letters->context($pharmacy, $record);
        $pdf = $letters->render($context, $data);
        app(AccessManager::class)->audit($pharmacy, $request->user()->id, 'purchase.letter_exported', $record->id, ['kind' => $data['kind']]);

        return response($pdf)->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'attachment; filename="surat-pesanan-'.Str::slug($record->number).'.pdf"')
            ->header('Cache-Control', 'private, no-store');
    }

    public function cancel(Request $request, Pharmacy $pharmacy, string $order, Procurement $service)
    {
        $data = $request->validate(['reason' => 'required|string|min:5|max:2000']);
        $service->cancel($pharmacy, $request->user()->id, $order, $data['reason']);

        return back()->with('status', 'PO dibatalkan.');
    }

    public function receiptCreate(Pharmacy $pharmacy, string $order)
    {
        $record = $pharmacy->purchaseOrders()->with('items')->findOrFail($order);
        abort_unless(in_array($record->status, ['ORDERED', 'PARTIAL']), 422);

        return view('procurement.receipt-form', ['order' => $record]);
    }

    public function receiptSave(Request $request, Pharmacy $pharmacy, string $order, Procurement $service, ?string $receiptId = null)
    {
        $today = now($pharmacy->settings?->timezone ?? 'Asia/Jakarta')->toDateString();
        $request->mergeIfMissing(['ppn_type' => 'nominal', 'ppn_value' => '0', 'ppn_scope' => 'total']);
        $data = $request->validate(['ppn_type' => ['required', Rule::in(['percentage', 'nominal'])],
            'ppn_scope' => ['required', Rule::in(['total', 'product'])],
            'ppn_value' => 'required|numeric|min:0|max:'.($request->input('ppn_type') === 'percentage' ? '100' : '100000000000').'|decimal:0,2',
            'received_on' => 'required|date_format:Y-m-d|before_or_equal:'.$today, 'invoice_number' => 'nullable|string|max:80', 'notes' => 'nullable|string|max:2000',
            'items' => 'required|array|min:1|max:100', 'items.*.order_item_id' => 'required|uuid|distinct', 'items.*.quantity' => 'required|numeric|min:0|max:10000|decimal:0,3',
            'items.*.unit_price' => 'required|numeric|min:0|max:10000000|decimal:0,2', 'items.*.batch_number' => 'nullable|string|max:80', 'items.*.expires_on' => 'nullable|date_format:Y-m-d', 'items.*.location' => 'nullable|string|max:100']);
        $receipt = $service->receive($pharmacy, $request->user()->id, $order, $data, $receiptId);

        return redirect()->route('receipts.show', [$pharmacy, $receipt])->with('status', 'Draft penerimaan disimpan. Posting untuk menambahkan stok.');
    }

    public function receiptShow(Pharmacy $pharmacy, string $receipt)
    {
        $record = $pharmacy->purchaseReceipts()->with(['order', 'items.orderItem'])->findOrFail($receipt);
        $payable = request()->attributes->get('membership')->allows('payables.view')
            ? \App\Services\SupplierPayables::query($pharmacy)->where('purchase_receipt_id', $receipt)->first() : null;

        return view('procurement.receipt-show', ['receipt' => $record, 'payable' => $payable]);
    }

    public function receiptEdit(Pharmacy $pharmacy, string $receipt)
    {
        $record = $pharmacy->purchaseReceipts()->with(['items', 'order.items'])->findOrFail($receipt);
        abort_unless($record->status === 'DRAFT' && in_array($record->order->status, ['ORDERED', 'PARTIAL']), 422, 'Penerimaan ini tidak dapat diubah.');

        return view('procurement.receipt-form', ['receipt' => $record, 'order' => $record->order]);
    }

    public function receiptUpdate(Request $request, Pharmacy $pharmacy, string $receipt, Procurement $service)
    {
        $record = $pharmacy->purchaseReceipts()->findOrFail($receipt);

        return $this->receiptSave($request, $pharmacy, $record->purchase_order_id, $service, $receipt);
    }

    public function post(Request $request, Pharmacy $pharmacy, string $receipt, Procurement $service)
    {
        $service->post($pharmacy, $request->user()->id, $receipt);

        return back()->with('status', 'Penerimaan diposting. Stok barang telah ditambahkan.');
    }
}
