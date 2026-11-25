<?php

namespace Tests\Feature;

use App\Models\Pharmacy;
use App\Models\User;
use App\Services\CashManagement;
use App\Services\PrescriptionDispensing;
use App\Services\SupplierPayables;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class EndToEndWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function created(string $route, array $parameters, array $input, string $table): object
    {
        $response = $this->post(route($route, $parameters), $input)->assertRedirect()->assertSessionHasNoErrors();
        $id = basename(parse_url($response->headers->get('Location'), PHP_URL_PATH));
        $record = DB::table($table)->where('id', $id)->first();
        $this->assertNotNull($record, 'Transaksi '.$route.' harus tersimpan.');

        return $record;
    }

    private function workspace(): array
    {
        $this->travelTo(Carbon::parse('2026-11-11 12:00:00', 'UTC'));
        Storage::set('local', Storage::fake('e2e-'.DB::getDriverName().'-'.getmypid()));
        $this->post('/register', ['name' => 'Owner QA E2E', 'email' => 'owner-e2e@example.test', 'password' => 'LocalE2ePassword2026', 'password_confirmation' => 'LocalE2ePassword2026', 'is_system_admin' => 1])->assertSessionHasNoErrors()->assertRedirect();
        $owner = User::where('email', 'owner-e2e@example.test')->firstOrFail();
        $this->assertFalse($owner->is_system_admin);
        DB::table('regions')->upsert([
            ['code' => '11', 'name' => 'Aceh', 'level' => 1, 'parent_code' => null], ['code' => '11.01', 'name' => 'Aceh Selatan', 'level' => 2, 'parent_code' => '11'],
            ['code' => '11.01.01', 'name' => 'Bakongan', 'level' => 3, 'parent_code' => '11.01'], ['code' => '11.01.01.2001', 'name' => 'Keude Bakongan', 'level' => 4, 'parent_code' => '11.01.01'],
        ], ['code'], ['name']);
        $this->postJson('/api/v1/pharmacies', ['code' => 'QA-E2E', 'name' => 'Apotek QA E2E', 'ownership_type' => 'Individual', 'phone' => '08000000001', 'owner_name' => 'Owner QA', 'owner_phone' => '08000000001',
            'address' => 'Alamat simulasi QA', 'province_code' => '11', 'city_code' => '11.01', 'district_code' => '11.01.01', 'village_code' => '11.01.01.2001', 'status' => 'ACTIVE'])->assertCreated()->assertJsonPath('data.status', 'DRAFT');
        $pharmacy = Pharmacy::where('code', 'QA-E2E')->firstOrFail();
        $this->post(route('purchases.store', $pharmacy), [])->assertStatus(422);
        $this->put(route('master.license', $pharmacy), ['nib' => '1234567890123', 'license_number' => 'QA-SIA', 'has_expiry' => 1, 'issued_on' => '2026-01-01', 'expires_on' => '2030-12-31', 'npwp' => '1234567890123456', 'document_status' => 'Recorded'])->assertSessionHasNoErrors();
        $this->post(route('master.personnel', $pharmacy), ['name' => 'Apoteker QA', 'str_number' => 'QA-STR', 'sip_number' => 'QA-SIP', 'sip_expires_on' => '2030-12-31', 'active' => 1])->assertSessionHasNoErrors();
        $person = $pharmacy->personnel()->firstOrFail();
        $this->post(route('master.assign', $pharmacy), ['personnel_id' => $person->id, 'starts_on' => '2026-01-01'])->assertSessionHasNoErrors();
        $this->post(route('master.documents.upload', $pharmacy), ['type' => 'license', 'name' => 'Dokumen simulasi QA', 'expires_on' => '2030-12-31', 'file' => UploadedFile::fake()->createWithContent('qa.pdf', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF")])->assertSessionHasNoErrors();
        $this->put(route('master.hours', $pharmacy), ['hours' => array_map(fn ($day) => ['day' => $day, 'is_open' => false, 'overnight' => false], range(0, 6))])->assertSessionHasNoErrors();
        $this->actingAs($owner)->post(route('master.activate', $pharmacy))->assertSessionHasNoErrors();
        $this->assertSame('ACTIVE', $pharmacy->fresh()->status);
        foreach (['UMUM' => false, 'RESEP' => true] as $sku => $rx) {
            $this->post(route('products.store', $pharmacy), ['sku' => $sku, 'brand' => 'QA '.$sku, 'category' => 'Obat', 'dosage_form' => 'Tablet', 'base_unit' => 'Tablet', 'purchase_unit' => 'Tablet', 'purchase_conversion' => 1,
                'purchase_price' => 100, 'selling_price' => 500, 'minimum_stock' => 1, 'maximum_stock' => 100, 'requires_prescription' => $rx ? 1 : 0])->assertSessionHasNoErrors();
        }
        $products = $pharmacy->products()->get()->keyBy('sku');
        $this->post(route('suppliers.store', $pharmacy), ['name' => 'Supplier QA', 'payment_days' => 30])->assertSessionHasNoErrors();
        $supplier = $pharmacy->suppliers()->firstOrFail();
        $order = $this->created('purchases.store', [$pharmacy], ['supplier_id' => $supplier->id, 'ordered_on' => '2026-11-11', 'payment_type' => 'credit', 'payment_days' => 30,
            'items' => $products->map(fn ($product) => ['product_id' => $product->id, 'unit_type' => 'base', 'quantity' => 20, 'unit_price' => 100, 'discount' => 0, 'tax' => 0])->values()->all()], 'purchase_orders');
        $this->post(route('purchases.finalize', [$pharmacy, $order->id]))->assertSessionHasNoErrors();
        $items = DB::table('purchase_order_items')->where('purchase_order_id', $order->id)->get();
        $receipt = $this->created('receipts.store', [$pharmacy, $order->id], ['received_on' => '2026-11-11', 'invoice_number' => 'QA-INV', 'ppn_type' => 'percentage', 'ppn_value' => 11,
            'items' => $items->map(fn ($item) => ['order_item_id' => $item->id, 'quantity' => 20, 'unit_price' => 100, 'batch_number' => 'QA-LOT', 'expires_on' => '2030-01-01', 'location' => 'Rak QA'])->all()], 'purchase_receipts');
        $this->post(route('receipts.post', [$pharmacy, $receipt->id]))->assertSessionHasNoErrors();
        $this->post(route('receipts.post', [$pharmacy, $receipt->id]))->assertSessionHasErrors();
        $this->assertEquals(40, DB::table('stock_movements')->sum('quantity'));
        $shift = $this->created('shifts.open', [$pharmacy], ['request_key' => (string) Str::uuid(), 'opening_cash' => 10000], 'cash_shifts');

        return [$owner, $pharmacy, $products['UMUM'], $products['RESEP'], $receipt, $shift];
    }

    private function supplierPayment(Pharmacy $pharmacy, object $invoice, string $amount): void
    {
        $record = $this->created('payables.store', [$pharmacy, $invoice->id], ['request_key' => (string) Str::uuid(), 'amount' => $amount, 'paid_on' => '2026-11-11', 'method' => 'transfer', 'reference' => 'QA-BANK'], 'supplier_payments');
        $this->post(route('payables.approve', [$pharmacy, $record->id]), ['revision' => 1])->assertSessionHasNoErrors();
        $this->post(route('payables.post', [$pharmacy, $record->id]), ['revision' => 2, 'paid_confirmed' => 1])->assertSessionHasNoErrors();
    }

    private function supplierReturn(Pharmacy $pharmacy, object $receipt, string $product, int $quantity): object
    {
        $line = DB::table('purchase_receipt_items as receipt')->join('purchase_order_items as item', 'item.id', '=', 'receipt.purchase_order_item_id')->where('receipt.purchase_receipt_id', $receipt->id)->where('item.product_id', $product)->select('receipt.id')->first();
        $record = $this->created('supplier-returns.store', [$pharmacy], ['request_key' => (string) Str::uuid(), 'receipt_id' => $receipt->id, 'reason' => 'Retur simulasi QA', 'items' => [['id' => $line->id, 'quantity' => $quantity]]], 'supplier_returns');
        $this->post(route('supplier-returns.action', [$pharmacy, $record->id, 'post']), ['revision' => 1, 'accepted' => 1])->assertSessionHasNoErrors();

        return DB::table('supplier_returns')->find($record->id);
    }

    public function test_purchase_sales_returns_payables_expense_stocktake_and_cash_close_balance_end_to_end(): void
    {
        [$owner,$pharmacy,$general,,$receipt,$shift] = $this->workspace();
        foreach (['discount' => 10, 'tax' => 11] as $kind => $value) {
            $this->post(route('pricing.store', [$pharmacy, $kind]), ['code' => 'QA-'.$kind, 'name' => 'QA '.$kind, 'type' => 'percentage', 'value' => $value, 'tax_mode' => $kind === 'tax' ? 'exclusive' : null, 'is_default' => 0])->assertSessionHasNoErrors();
        }
        $rules = DB::table('pos_pricing_rules')->where('pharmacy_id', $pharmacy->id)->get()->keyBy('kind');
        $invoice = DB::table('supplier_invoices')->where('purchase_receipt_id', $receipt->id)->first();
        $this->supplierPayment($pharmacy, $invoice, '4000');
        $return = $this->supplierReturn($pharmacy, $receipt, $general->id, 2);
        $this->assertEquals(222, $return->credit_amount);
        $this->assertEquals(0, $return->refund_due);
        $this->supplierPayment($pharmacy, $invoice, '218');
        $return = $this->supplierReturn($pharmacy, $receipt, $general->id, 1);
        $this->assertEquals(111, $return->refund_due);
        $this->post(route('supplier-returns.action', [$pharmacy, $return->id, 'recover']), ['revision' => 2, 'confirmed' => 1, 'method' => 'transfer', 'reference' => 'QA-REFUND'])->assertSessionHasNoErrors();
        $sale = $this->created('sales.store', [$pharmacy], ['request_key' => (string) Str::uuid(), 'discount_rule_id' => $rules['discount']->id, 'tax_rule_id' => $rules['tax']->id,
            'items' => [['product_id' => $general->id, 'quantity' => 4, 'unit_type' => 'base']]], 'sales');
        $this->assertEquals(1998, $sale->total);
        $this->post(route('sales.pay', [$pharmacy, $sale->id]), ['revision' => 1, 'payment_method' => 'cash', 'amount_received' => 2000])->assertSessionHasNoErrors();
        $this->post(route('sales.pay', [$pharmacy, $sale->id]), ['revision' => 1, 'payment_method' => 'cash', 'amount_received' => 2000])->assertSessionHasNoErrors();
        $this->get(route('sales.receipt', [$pharmacy, $sale->id]))->assertOk()->assertSee('1.998,00');
        $movement = DB::table('stock_movements')->where('sale_item_id', DB::table('sale_items')->where('sale_id', $sale->id)->value('id'))->where('type', 'SALE')->first();
        $refund = $this->created('returns.store', [$pharmacy, $sale->id], ['request_key' => (string) Str::uuid(), 'reason' => 'Retur QA', 'condition_checked' => 1, 'items' => [['sale_movement_id' => $movement->id, 'quantity' => 1, 'disposition' => 'RESTOCK']]], 'sale_returns');
        $this->post(route('returns.approve', [$pharmacy, $refund->id]), ['revision' => 1])->assertSessionHasNoErrors();
        $this->post(route('returns.refund', [$pharmacy, $refund->id]), ['revision' => 2, 'refund_method' => 'cash', 'refund_confirmed' => 1])->assertSessionHasNoErrors();
        $this->assertEquals(499.5, $refund->refund_amount);
        $this->post(route('operations.catalog.save', [$pharmacy, 'categories']), ['name' => 'Biaya QA', 'active' => 1])->assertSessionHasNoErrors();
        $category = DB::table('expense_categories')->where('pharmacy_id', $pharmacy->id)->first();
        $expense = $this->created('expenses.store', [$pharmacy], ['request_key' => (string) Str::uuid(), 'category_id' => $category->id, 'spent_on' => '2026-11-11', 'amount' => 200, 'method' => 'cash', 'description' => 'Biaya QA E2E'], 'operating_expenses');
        $this->post(route('expenses.action', [$pharmacy, $expense->id, 'post']), ['revision' => 1, 'confirmed' => 1])->assertSessionHasErrors('operation');
        $this->post(route('expenses.action', [$pharmacy, $expense->id, 'approve']), ['revision' => 1])->assertSessionHasNoErrors();
        $this->post(route('expenses.action', [$pharmacy, $expense->id, 'post']), ['revision' => 2, 'confirmed' => 1])->assertSessionHasNoErrors();
        $stocktake = $this->created('stocktakes.store', [$pharmacy], ['request_key' => (string) Str::uuid(), 'product_id' => $general->id], 'stocktakes');
        $lines = DB::table('stocktake_lines')->where('stocktake_id', $stocktake->id)->get();
        $this->assertEquals(14, $lines->sum('system_quantity'));
        $this->put(route('stocktakes.counts', [$pharmacy, $stocktake->id]), ['revision' => 1, 'lines' => $lines->map(fn ($line) => ['id' => $line->id, 'physical_quantity' => 13, 'reason' => 'Selisih fisik QA'])->all()])->assertSessionHasNoErrors();
        $this->post(route('stocktakes.approve', [$pharmacy, $stocktake->id]), ['revision' => 2])->assertSessionHasNoErrors();
        $this->post(route('stocktakes.post', [$pharmacy, $stocktake->id]), ['revision' => 3])->assertSessionHasNoErrors();
        $this->assertEquals(13, DB::table('stock_movements')->where('product_id', $general->id)->sum('quantity'));
        $this->assertSame(0, SupplierPayables::balances($pharmacy, DB::table('supplier_invoices')->find($invoice->id))['outstanding']);
        $this->assertSame(1129850, CashManagement::expected($shift));
        $this->post(route('shifts.action', [$pharmacy, $shift->id, 'close']), ['revision' => 1, 'counted_cash' => '11298.50'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('cash_shifts', ['id' => $shift->id, 'status' => 'CLOSED', 'difference' => '0.00']);
        foreach (['sales', 'purchases', 'inventory', 'finance'] as $kind) {
            $this->get(route('reports.index', [$pharmacy, 'kind' => $kind, 'from' => '2026-11-11', 'to' => '2026-11-11']))->assertOk();
            $xlsx = $this->get(route('reports.index', [$pharmacy, 'kind' => $kind, 'from' => '2026-11-11', 'to' => '2026-11-11', 'export' => 'xlsx']))->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')->streamedContent();
            $book = \Shuchkin\SimpleXLSX::parseData($xlsx);
            $this->assertNotFalse($book);
            $this->assertStringNotContainsString('QA-BANK', json_encode($book->rows()));
        }
        $this->get(route('inventory.ledger', [$pharmacy, $general->id]))->assertOk()->assertSee('Retur ke supplier');
        $this->get(route('audit.index', $pharmacy))->assertOk();
        $this->post('/logout')->assertRedirect('/login');
        $this->get(route('sales.index', $pharmacy))->assertRedirect('/login');
    }

    public function test_prescription_first_representative_partial_fills_refund_restore_and_late_soap_end_to_end(): void
    {
        [$owner,$pharmacy,,$rxProduct,,$shift] = $this->workspace();
        $patient = $this->created('patients.store', [$pharmacy], ['request_key' => (string) Str::uuid(), 'name' => 'Pasien QA', 'birth_date' => '1990-01-01', 'sex' => 'female', 'allergies' => 'Alergi QA privat'], 'patients');
        $doctor = $this->created('doctors.store', [$pharmacy], ['request_key' => (string) Str::uuid(), 'name' => 'Dokter QA', 'sip_number' => 'QA-SIP-DOKTER'], 'doctors');
        $visit = $this->created('visits.store', [$pharmacy], ['request_key' => (string) Str::uuid(), 'patient_id' => $patient->id, 'doctor_id' => $doctor->id, 'type' => 'new', 'complaint' => 'Keluhan QA privat'], 'visits');
        $this->post(route('visits.transition', [$pharmacy, $visit->id]), ['revision' => 1, 'action' => 'start'])->assertSessionHasErrors('record');
        $this->post(route('operations.catalog.save', [$pharmacy, 'tariffs']), ['name' => 'Konsultasi QA', 'price' => 1000, 'active' => 1])->assertSessionHasNoErrors();
        $tariff = DB::table('service_tariffs')->where('pharmacy_id', $pharmacy->id)->first();
        $bill = $this->created('billing.store', [$pharmacy], ['request_key' => (string) Str::uuid(), 'visit_id' => $visit->id, 'items' => [['tariff_id' => $tariff->id, 'quantity' => 1]]], 'service_bills');
        $this->post(route('billing.action', [$pharmacy, $bill->id, 'pay']), ['revision' => 1, 'confirmed' => 1, 'payment_method' => 'cash', 'amount_received' => 1000])->assertSessionHasNoErrors();
        $this->post(route('visits.transition', [$pharmacy, $visit->id]), ['revision' => 1, 'action' => 'start'])->assertSessionHasNoErrors();
        $this->put(route('prescriptions.save', [$pharmacy, $visit->id]), ['valid_until' => now()->addDays(30)->toDateString(), 'revision' => 0, 'items' => [['product_id' => $rxProduct->id, 'quantity' => 6, 'dose' => '1 tablet', 'frequency' => '2 kali', 'instructions' => 'Aturan QA privat']]])->assertSessionHasNoErrors();
        $this->post(route('prescriptions.finalize', [$pharmacy, $visit->id]), ['revision' => 1])->assertSessionHasNoErrors();
        $rx = DB::table('prescriptions')->where('visit_id', $visit->id)->first();
        $this->assertDatabaseCount('examinations', 0);
        $this->post(route('dispensing.store', [$pharmacy, $rx->id]), ['request_key' => (string) Str::uuid(), 'verified' => 1, 'recipient_type' => 'representative', 'items' => [['product_id' => $rxProduct->id, 'quantity' => 2]]])->assertSessionHasErrors('recipient_name');
        $first = $this->created('dispensing.store', [$pharmacy, $rx->id], ['request_key' => (string) Str::uuid(), 'verified' => 1, 'recipient_type' => 'representative', 'recipient_name' => 'Pengambil QA', 'recipient_relationship' => 'Keluarga', 'recipient_phone' => '08000000002', 'items' => [['product_id' => $rxProduct->id, 'quantity' => 2]]], 'sales');
        $this->post(route('sales.pay', [$pharmacy, $first->id]), ['revision' => 1, 'payment_method' => 'cash', 'amount_received' => 1000])->assertSessionHasNoErrors();
        $this->post(route('dispensing.handover', [$pharmacy, $first->id]))->assertSessionHasNoErrors();
        $this->get(route('dispensing.labels', [$pharmacy, $first->id]))->assertOk()->assertSee('Pasien QA');
        $second = $this->created('dispensing.store', [$pharmacy, $rx->id], ['request_key' => (string) Str::uuid(), 'verified' => 1, 'recipient_type' => 'self', 'items' => [['product_id' => $rxProduct->id, 'quantity' => 4]]], 'sales');
        $this->post(route('sales.pay', [$pharmacy, $second->id]), ['revision' => 1, 'payment_method' => 'cash', 'amount_received' => 2000])->assertSessionHasNoErrors();
        $this->post(route('dispensing.handover', [$pharmacy, $second->id]))->assertSessionHasNoErrors();
        $movement = DB::table('stock_movements')->where('sale_item_id', DB::table('sale_items')->where('sale_id', $first->id)->value('id'))->where('type', 'SALE')->first();
        $refund = $this->created('returns.store', [$pharmacy, $first->id], ['request_key' => (string) Str::uuid(), 'reason' => 'Retur klinis QA privat', 'condition_checked' => 1, 'returner_type' => 'self', 'items' => [['sale_movement_id' => $movement->id, 'quantity' => 1, 'disposition' => 'RESTOCK']]], 'sale_returns');
        $this->post(route('returns.approve', [$pharmacy, $refund->id]), ['revision' => 1])->assertSessionHasNoErrors();
        $this->post(route('returns.refund', [$pharmacy, $refund->id]), ['revision' => 2, 'refund_method' => 'cash', 'refund_confirmed' => 1])->assertSessionHasNoErrors();
        $attempt = ['request_key' => (string) Str::uuid(), 'verified' => 1, 'recipient_type' => 'self', 'items' => [['product_id' => $rxProduct->id, 'quantity' => 1]]];
        $this->post(route('dispensing.store', [$pharmacy, $rx->id]), $attempt)->assertSessionHasErrors('dispensing');
        $this->post(route('returns.restore_entitlement', [$pharmacy, $refund->id]), ['revision' => 3, 'reason' => 'Pemulihan hak QA privat'])->assertSessionHasNoErrors();
        $last = $this->created('dispensing.store', [$pharmacy, $rx->id], $attempt, 'sales');
        $this->post(route('sales.pay', [$pharmacy, $last->id]), ['revision' => 1, 'payment_method' => 'cash', 'amount_received' => 500])->assertSessionHasNoErrors();
        $this->post(route('dispensing.handover', [$pharmacy, $last->id]))->assertSessionHasNoErrors();
        $this->assertSame(0, PrescriptionDispensing::remaining($pharmacy, DB::table('prescriptions')->find($rx->id))[0]['remaining']);
        $image = UploadedFile::fake()->createWithContent('soap.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/a9sAAAAASUVORK5CYII='));
        $this->post(route('visits.complete', [$pharmacy, $visit->id]), ['revision' => 2, 'pending_reason' => 'SOAP online menyusul QA'])->assertSessionHasNoErrors();
        $this->put(route('examinations.save', [$pharmacy, $visit->id]), ['revision' => 0, 'documentation_mode' => 'IMAGE', 'images' => [$image], 'image_notes' => 'Gambar SOAP QA privat'])->assertSessionHasNoErrors();
        $this->post(route('examinations.finalize', [$pharmacy, $visit->id]), ['revision' => 1])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('visits', ['id' => $visit->id, 'status' => 'COMPLETED']);
        $this->assertDatabaseHas('examinations', ['visit_id' => $visit->id, 'status' => 'FINAL']);
        $this->assertEquals(14, DB::table('stock_movements')->where('product_id', $rxProduct->id)->sum('quantity'));
        $this->assertSame(1400000, CashManagement::expected($shift));
        $audit = DB::table('audit_entries')->pluck('details')->implode(' ');
        foreach (['Keluhan QA privat', 'Aturan QA privat', 'Pengambil QA', 'Retur klinis QA privat', 'Gambar SOAP QA privat'] as $private) {
            $this->assertStringNotContainsString($private, $audit);
        }
        $this->get(route('reports.index', [$pharmacy, 'kind' => 'sales']))->assertOk()->assertViewHas('summary', fn ($summary) => (float) $summary['amount'] === 4000.0);
        $this->post(route('shifts.action', [$pharmacy, $shift->id, 'close']), ['revision' => 1, 'counted_cash' => 14000])->assertSessionHasNoErrors();
    }

    public function test_transaction_calendar_and_expiry_follow_configured_timezone_at_midnight(): void
    {
        [, $pharmacy,$general] = $this->workspace();
        $pharmacy->settings()->update(['timezone' => 'Asia/Jayapura']);
        $pharmacy->refresh();
        $this->travelTo(Carbon::parse('2026-11-11 15:05:00', 'UTC'));
        $this->get(route('expenses.create', $pharmacy))->assertOk()->assertViewHas('today', '2026-11-12');
        $this->get(route('payables.index', $pharmacy))->assertOk()->assertViewHas('today', '2026-11-12');
        $this->get(route('reports.index', [$pharmacy, 'kind' => 'inventory']))->assertOk()->assertViewHas('today', '2026-11-12');
        $this->post(route('operations.catalog.save', [$pharmacy, 'categories']), ['name' => 'Biaya zona waktu', 'active' => 1])->assertSessionHasNoErrors();
        $category = DB::table('expense_categories')->where('pharmacy_id', $pharmacy->id)->first();
        $this->post(route('expenses.store', $pharmacy), ['request_key' => (string) Str::uuid(), 'category_id' => $category->id, 'spent_on' => '2026-11-12', 'amount' => 1, 'method' => 'cash', 'description' => 'Biaya hari lokal'])->assertSessionHasNoErrors();
        $source = DB::table('stock_movements')->where('product_id', $general->id)->where('type', 'PURCHASE_RECEIPT')->first();
        DB::table('stock_movements')->where('id', $source->id)->update(['expires_on' => '2026-11-12']);
        $sale = $this->created('sales.store', [$pharmacy], ['request_key' => (string) Str::uuid(), 'items' => [['product_id' => $general->id, 'quantity' => 1, 'unit_type' => 'base']]], 'sales');
        $this->post(route('sales.pay', [$pharmacy, $sale->id]), ['revision' => 1, 'payment_method' => 'cash', 'amount_received' => 500])->assertSessionHasErrors();
        $this->assertDatabaseHas('sales', ['id' => $sale->id, 'status' => 'DRAFT']);
    }

    public function test_every_authenticated_module_route_rejects_guests(): void
    {
        $checked = 0;
        foreach (app('router')->getRoutes() as $route) {
            if (! in_array('auth', $route->gatherMiddleware(), true)) {
                continue;
            }
            $uri = $route->uri();
            if (! str_starts_with($uri, 'a/{pharmacy}') && ! str_starts_with($uri, 'api/v1/pharmacies') && ! in_array($uri, ['profile', 'profile/password', 'pharmacies', 'pharmacies/create', 'system/pharmacies'], true)) {
                continue;
            }
            $uri = '/'.preg_replace_callback('/\{([^}?]+)\??\}/', function ($match) use ($route) {
                $constraint = $route->wheres[$match[1]] ?? null;
                foreach ([(string) Str::uuid(), '1', 'post', 'pay', 'close', 'approve', 'cancel', 'tariffs', 'categories', 'discount', 'tax'] as $value) {
                    if (! $constraint || preg_match('~^(?:'.$constraint.')$~D', $value)) {
                        return $value;
                    }
                }
                throw new \RuntimeException('Tambahkan nilai valid untuk parameter route '.$match[1].' pada '.$route->uri());
            }, $uri);
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $response = $this->call($method, $uri);
                if (str_starts_with($uri, '/api/')) {
                    $response->assertUnauthorized();
                } else {
                    $response->assertRedirect(route('login'));
                }
                $checked++;
            }
        }
        $this->assertGreaterThan(100, $checked, 'Seluruh modul yang sudah terpasang harus termasuk pemeriksaan autentikasi.');
    }
}
