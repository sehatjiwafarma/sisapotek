<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AccessManager;
use App\Services\CashManagement;
use App\Services\ClinicalMaster;
use App\Services\OperatingExpenses;
use App\Services\OperationalReports;
use App\Services\PointOfSale;
use App\Services\PrescriptionDispensing;
use App\Services\PrescriptionRecords;
use App\Services\Procurement;
use App\Services\SaleReturns;
use App\Services\ServiceBilling;
use App\Services\SupplierPayables;
use App\Services\VisitRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class OperationalFinanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_and_report_permissions_match_navigation_and_server_access(): void
    {
        $owner = User::factory()->create();
        $pharmacy = app(AccessManager::class)->provision($owner->id, ['name' => 'Apotek Hak Akses']);
        $pharmacy->forceFill(['status' => 'ACTIVE'])->save();
        $staff = User::factory()->create();
        $role = $pharmacy->roles()->create(['name' => 'Petugas', 'permissions' => ['expenses.categories', 'billing.view', 'reports.sales'], 'is_owner' => false]);
        $member = $pharmacy->memberships()->create(['user_id' => $staff->id, 'active' => true]);
        app(AccessManager::class)->syncRoles($member, collect([$role]));

        $this->actingAs($staff)->get(route('operations.catalog', [$pharmacy, 'categories']))->assertOk()->assertSee('Kategori pengeluaran');
        $this->get(route('operations.catalog', [$pharmacy, 'tariffs']))->assertOk()->assertSee('Layanan &amp; tarif', false);
        $this->get(route('reports.index', [$pharmacy, 'kind' => 'sales']))->assertOk()->assertSee('Laporan operasional');
        $this->post(route('operations.catalog.save', [$pharmacy, 'tariffs']), ['name' => 'Tidak berwenang', 'price' => 1, 'active' => 1])->assertForbidden();

        $role->update(['permissions' => []]);
        $this->get(route('operations.catalog', [$pharmacy, 'categories']))->assertForbidden();
        $this->get(route('operations.catalog', [$pharmacy, 'tariffs']))->assertForbidden();
        $this->get(route('reports.index', [$pharmacy, 'kind' => 'sales']))->assertForbidden();
    }

    private function workspace(string $type = 'credit'): array
    {
        $this->travelTo(now()->setDate(2026, 11, 9)->setTime(12, 0));
        Storage::set('local', Storage::fake('supplier-payables-'.getmypid()));
        $owner = User::factory()->create();
        $pharmacy = app(AccessManager::class)->provision($owner->id, ['name' => 'Apotek Utang']);
        $pharmacy->forceFill(['status' => 'ACTIVE'])->save();
        $supplier = $pharmacy->suppliers()->create(['code' => 'SUP', 'name' => 'Supplier Utang', 'payment_days' => 30, 'active' => true]);
        $product = $pharmacy->products()->create(['sku' => 'PAYABLE', 'name' => 'Obat Uji', 'brand' => 'Brand', 'category' => 'Obat', 'base_unit' => 'Tablet', 'purchase_unit' => 'Tablet', 'purchase_conversion' => 1, 'selling_price' => 200, 'minimum_stock' => 0, 'active' => true, 'requires_prescription' => false]);
        $service = app(Procurement::class);
        $order = $service->saveOrder($pharmacy, $owner->id, ['supplier_id' => $supplier->id, 'ordered_on' => '2026-11-01', 'payment_type' => $type, 'payment_days' => 30,
            'items' => [['product_id' => $product->id, 'unit_type' => 'base', 'quantity' => 10, 'unit_price' => 100]]]);
        $service->finalize($pharmacy, $owner->id, $order->id);
        $receipt = $service->receive($pharmacy, $owner->id, $order->id, ['received_on' => '2026-11-04', 'invoice_number' => 'FAKTUR-SUP', 'notes' => 'Sebagian', 'ppn_type' => 'percentage', 'ppn_value' => 11,
            'items' => [['order_item_id' => $order->items()->first()->id, 'quantity' => 5, 'unit_price' => 100, 'batch_number' => 'LOT', 'expires_on' => '2030-01-01']]]);
        $this->actingAs($owner);

        return [$owner, $pharmacy, $supplier, $order, $receipt];
    }

    private function postedInvoice($pharmacy, $owner, $receipt): object
    {
        app(Procurement::class)->post($pharmacy, $owner->id, $receipt->id);

        return DB::table('supplier_invoices')->where('purchase_receipt_id', $receipt->id)->first();
    }

    private function data(string $amount = '100.25'): array
    {
        return ['request_key' => (string) Str::uuid(), 'amount' => $amount, 'paid_on' => '2026-11-09', 'method' => 'transfer', 'reference' => 'REF-PRIVAT', 'notes' => 'Catatan pembayaran'];
    }

    private function payment($pharmacy, $invoice, string $amount = '100.25', array $extra = []): object
    {
        $response = $this->post(route('payables.store', [$pharmacy, $invoice->id]), $extra + $this->data($amount))->assertSessionHasNoErrors()->assertRedirect();

        return DB::table('supplier_payments')->find(basename(parse_url($response->headers->get('Location'), PHP_URL_PATH)));
    }

    private function finish($pharmacy, $payment): void
    {
        $this->post(route('payables.approve', [$pharmacy, $payment->id]), ['revision' => 1])->assertSessionHasNoErrors();
        $this->post(route('payables.post', [$pharmacy, $payment->id]), ['revision' => 2, 'paid_confirmed' => 1])->assertSessionHasNoErrors();
    }

    private function image(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('bukti.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/a9sAAAAASUVORK5CYII='));
    }

    private function supplierReturn($pharmacy, $receipt, string $quantity): object
    {
        $line = DB::table('purchase_receipt_items')->where('purchase_receipt_id', $receipt->id)->first();
        $response = $this->post(route('supplier-returns.store', $pharmacy), ['request_key' => (string) Str::uuid(), 'receipt_id' => $receipt->id, 'reason' => 'Rusak', 'items' => [['id' => $line->id, 'quantity' => $quantity]]])->assertSessionHasNoErrors();

        return DB::table('supplier_returns')->find(basename(parse_url($response->headers->get('Location'), PHP_URL_PATH)));
    }

    private function postReturn($pharmacy, $record): void
    {
        $this->post(route('supplier-returns.action', [$pharmacy, $record->id, 'post']), ['revision' => 1, 'accepted' => 1])->assertSessionHasNoErrors();
    }

    private function visit($pharmacy, $owner): string
    {
        $master = app(ClinicalMaster::class);
        $patient = $master->savePatient($pharmacy, $owner->id, ['request_key' => (string) Str::uuid(), 'name' => 'Pasien Layanan', 'birth_date' => '1990-01-01', 'sex' => 'female']);
        $doctor = $master->saveDoctor($pharmacy, $owner->id, ['request_key' => (string) Str::uuid(), 'name' => 'Dokter Layanan']);
        $service = app(VisitRegistration::class);
        $id = $service->save($pharmacy, $owner->id, ['request_key' => (string) Str::uuid(), 'patient_id' => $patient, 'doctor_id' => $doctor, 'type' => 'new', 'complaint' => 'Keluhan privat']);
        // A historical visit created before payment became a prerequisite.
        DB::table('visits')->where('id', $id)->update(['status' => 'IN_PROGRESS', 'started_at' => now(), 'revision' => 2]);

        return $id;
    }

    private function tariff($pharmacy, $owner): string
    {
        return app(ServiceBilling::class)->tariff($pharmacy, $owner->id, ['name' => 'Konsultasi', 'price' => '50000.25', 'active' => 1]);
    }

    private function bill($pharmacy, string $visit, string $tariff, ?string $sale = null): object
    {
        $response = $this->post(route('billing.store', $pharmacy), ['request_key' => (string) Str::uuid(), 'visit_id' => $visit, 'items' => [['tariff_id' => $tariff, 'quantity' => 1]]])->assertSessionHasNoErrors();
        $id = basename(parse_url($response->headers->get('Location'), PHP_URL_PATH));
        if ($sale) {
            // Reconstruct a legacy combined draft to exercise compatibility.
            $medicine = DB::table('sales')->where('id', $sale)->value('total');
            DB::table('service_bills')->where('id', $id)->update(['sale_id' => $sale, 'open_sale_key' => $sale, 'medicine_total' => $medicine, 'total' => DB::raw('services_total + '.(float) $medicine)]);
        }
        return DB::table('service_bills')->find($id);
    }

    private function expense($pharmacy, $owner, string $amount = '25'): object
    {
        $category = DB::table('expense_categories')->where('pharmacy_id', $pharmacy->id)->where('name', 'Listrik')->value('id') ?? app(OperatingExpenses::class)->category($pharmacy, $owner->id, ['name' => 'Listrik', 'active' => 1]);
        $response = $this->post(route('expenses.store', $pharmacy), ['request_key' => (string) Str::uuid(), 'category_id' => $category, 'spent_on' => '2026-11-09', 'description' => 'Biaya listrik', 'amount' => $amount, 'method' => 'cash'])->assertSessionHasNoErrors();

        return DB::table('operating_expenses')->find(basename(parse_url($response->headers->get('Location'), PHP_URL_PATH)));
    }

    public function test_supplier_return_splits_debt_credit_and_refund_and_cannot_exceed_received_stock(): void
    {
        [$owner,$pharmacy,,,$receipt] = $this->workspace();
        $invoice = $this->postedInvoice($pharmacy, $owner, $receipt);
        $payment = $this->payment($pharmacy, $invoice, '400');
        $this->finish($pharmacy, $payment);
        $return = $this->supplierReturn($pharmacy, $receipt, '2');
        $this->assertEquals(222, $return->total);
        $this->get(route('supplier-returns.show', [$pharmacy, $return->id]))->assertOk();
        $this->postReturn($pharmacy, $return);
        $this->postReturn($pharmacy, $return);
        $return = DB::table('supplier_returns')->find($return->id);
        $this->assertEquals(155, $return->credit_amount);
        $this->assertEquals(67, $return->refund_due);
        $this->assertSame(0, SupplierPayables::balances($pharmacy, DB::table('supplier_invoices')->find($invoice->id))['outstanding']);
        $url = route('supplier-returns.action', [$pharmacy, $return->id, 'recover']);
        $this->post($url, ['revision' => 2, 'confirmed' => 1, 'method' => 'transfer', 'reference' => 'DANA-67'])->assertSessionHasNoErrors();
        $this->post($url, ['revision' => 2, 'confirmed' => 1, 'method' => 'transfer', 'reference' => 'DANA-67'])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('cash_entries', 2);
        $second = $this->supplierReturn($pharmacy, $receipt, '3');
        $this->postReturn($pharmacy, $second);
        $this->assertEquals(555, DB::table('supplier_returns')->where('status', 'POSTED')->sum('total'));
        $this->assertEquals(0, DB::table('stock_movements')->sum('quantity'));
        $line = DB::table('purchase_receipt_items')->where('purchase_receipt_id', $receipt->id)->first();
        $this->post(route('supplier-returns.store', $pharmacy), ['request_key' => (string) Str::uuid(), 'receipt_id' => $receipt->id, 'reason' => 'Berlebih', 'items' => [['id' => $line->id, 'quantity' => '0.001']]])->assertSessionHasErrors('operation');
        $this->get(route('payables.show', [$pharmacy, $invoice->id]))->assertOk()->assertSee('Pengurangan utang karena retur');
    }

    public function test_competing_returns_and_approved_payments_are_rechecked_before_posting(): void
    {
        [$owner,$pharmacy,,,$receipt] = $this->workspace();
        $invoice = $this->postedInvoice($pharmacy, $owner, $receipt);
        $first = $this->supplierReturn($pharmacy, $receipt, '4');
        $second = $this->supplierReturn($pharmacy, $receipt, '4');
        $payment = $this->payment($pharmacy, $invoice, '100');
        $this->post(route('payables.approve', [$pharmacy, $payment->id]), ['revision' => 1])->assertSessionHasNoErrors();
        $this->post(route('supplier-returns.action', [$pharmacy, $first->id, 'post']), ['revision' => 1, 'accepted' => 1])->assertSessionHasErrors('operation');
        $this->post(route('payables.cancel', [$pharmacy, $payment->id]), ['revision' => 2, 'reason' => 'Retur dahulu'])->assertSessionHasNoErrors();
        $this->postReturn($pharmacy, $first);
        $this->post(route('supplier-returns.action', [$pharmacy, $second->id, 'post']), ['revision' => 1, 'accepted' => 1])->assertSessionHasErrors('operation');
        $this->assertEquals(1, DB::table('stock_movements')->sum('quantity'));
        $this->post(route('supplier-returns.action', [$pharmacy, $first->id, 'cancel']), ['revision' => 2, 'reason' => 'Koreksi'])->assertSessionHasErrors('operation');
    }

    public function test_service_tariff_snapshot_payment_and_shift_reconciliation_are_exact_and_idempotent(): void
    {
        [$owner,$pharmacy] = $this->workspace();
        $visit = $this->visit($pharmacy, $owner);
        $tariff = $this->tariff($pharmacy, $owner);
        $bill = $this->bill($pharmacy, $visit, $tariff);
        app(ServiceBilling::class)->tariff($pharmacy, $owner->id, ['name' => 'Konsultasi baru', 'price' => 100, 'active' => 1], $tariff);
        $this->assertEquals(50000.25, $bill->total);
        $shift = app(CashManagement::class)->open($pharmacy, $owner->id, ['request_key' => (string) Str::uuid(), 'opening_cash' => '100']);
        $pay = ['revision' => 1, 'confirmed' => 1, 'payment_method' => 'cash', 'amount_received' => '50101'];
        $this->post(route('billing.action', [$pharmacy, $bill->id, 'pay']), $pay)->assertSessionHasNoErrors();
        $this->post(route('billing.action', [$pharmacy, $bill->id, 'pay']), $pay)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('cash_entries', 1);
        $this->assertEquals(100.75, DB::table('service_bills')->find($bill->id)->change_amount);
        $this->assertSame(5010025, CashManagement::expected(DB::table('cash_shifts')->find($shift)));
        $close = route('shifts.action', [$pharmacy, $shift, 'close']);
        $this->post($close, ['revision' => 1, 'counted_cash' => '50100'])->assertSessionHasErrors('operation');
        $this->post($close, ['revision' => 1, 'counted_cash' => '50100', 'reason' => 'Selisih pembulatan fisik'])->assertSessionHasNoErrors();
        $this->post($close, ['revision' => 1, 'counted_cash' => '50100', 'reason' => 'Ulang'])->assertSessionHasNoErrors();
        $this->assertEquals(-0.25, DB::table('cash_shifts')->find($shift)->difference);
        $this->get(route('shifts.show', [$pharmacy, $shift]))->assertOk()->assertSee('Selisih pembulatan fisik');
        $this->get(route('billing.show', [$pharmacy, $bill->id]))->assertOk()->assertSee('Konsultasi')->assertSee('100,75');
        $this->assertDatabaseCount('examinations', 0);
    }

    public function test_expenses_require_approval_available_cash_and_open_shift_then_post_once(): void
    {
        [$owner,$pharmacy] = $this->workspace();
        $record = $this->expense($pharmacy, $owner, '25.50');
        $post = route('expenses.action', [$pharmacy, $record->id, 'post']);
        $this->post($post, ['revision' => 1, 'confirmed' => 1])->assertSessionHasErrors('operation');
        $shift = app(CashManagement::class)->open($pharmacy, $owner->id, ['request_key' => (string) Str::uuid(), 'opening_cash' => 20]);
        $this->post(route('expenses.action', [$pharmacy, $record->id, 'approve']), ['revision' => 1])->assertSessionHasNoErrors();
        $this->post($post, ['revision' => 2, 'confirmed' => 1])->assertSessionHasErrors('operation');
        $this->assertDatabaseCount('cash_entries', 0);
        $key = (string) Str::uuid();
        $this->post(route('shifts.action', [$pharmacy, $shift, 'deposit']), ['request_key' => $key, 'amount' => 10, 'reason' => 'Dari pemilik'])->assertSessionHasNoErrors();
        $this->post(route('shifts.action', [$pharmacy, $shift, 'deposit']), ['request_key' => $key, 'amount' => 10, 'reason' => 'Dari pemilik'])->assertSessionHasNoErrors();
        $this->post($post, ['revision' => 2, 'confirmed' => 1])->assertSessionHasNoErrors();
        $this->post($post, ['revision' => 2, 'confirmed' => 1])->assertSessionHasNoErrors();
        $this->assertSame(450, CashManagement::expected(DB::table('cash_shifts')->find($shift)));
        $this->assertDatabaseCount('cash_entries', 2);
        $this->get(route('expenses.show', [$pharmacy, $record->id]))->assertOk()->assertSee('25,50');
        $this->post(route('expenses.action', [$pharmacy, $record->id, 'cancel']), ['revision' => 3, 'reason' => 'Coba'])->assertSessionHasErrors('operation');
        app(CashManagement::class)->close($pharmacy, $owner->id, $shift, ['revision' => 1, 'counted_cash' => '4.50']);
        $other = $this->expense($pharmacy, $owner, '1');
        $this->post(route('expenses.action', [$pharmacy, $other->id, 'approve']), ['revision' => 1])->assertSessionHasNoErrors();
        $this->post(route('expenses.action', [$pharmacy, $other->id, 'post']), ['revision' => 2, 'confirmed' => 1])->assertSessionHasErrors('operation');
    }

    public function test_navigation_forms_reports_and_excel_are_available_and_isolated(): void
    {
        [$owner,$pharmacy,,,$receipt] = $this->workspace();
        $this->postedInvoice($pharmacy, $owner, $receipt);
        $visit = $this->visit($pharmacy, $owner);
        $tariff = $this->tariff($pharmacy, $owner);
        $bill = $this->bill($pharmacy, $visit, $tariff);
        $this->expense($pharmacy, $owner);
        foreach (['supplier-returns.index', 'billing.index', 'expenses.index', 'shifts.index'] as $name) {
            $this->get(route($name, $pharmacy))->assertOk();
        }
        $this->get(route('supplier-returns.create', [$pharmacy, 'receipt_id' => $receipt->id]))->assertOk()->assertSee($receipt->number);
        $this->get(route('billing.create', [$pharmacy, 'visit_id' => $visit]))->assertOk()->assertSee('Konsultasi');
        $this->get(route('expenses.create', $pharmacy))->assertOk()->assertSee('Listrik');
        foreach (['tariffs', 'categories'] as $kind) {
            $this->get(route('operations.catalog', [$pharmacy, $kind]))->assertOk();
        }
        foreach (['sales', 'purchases', 'inventory', 'finance'] as $kind) {
            $this->get(route('reports.index', [$pharmacy, 'kind' => $kind]))->assertOk();
        }
        $xlsx = $this->get(route('reports.index', [$pharmacy, 'kind' => 'purchases', 'export' => 'xlsx']))->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')->streamedContent();
        $book = \Shuchkin\SimpleXLSX::parseData($xlsx);
        $this->assertNotFalse($book);
        $this->assertContains($receipt->number, array_column($book->rows(), 0));
        $this->assertStringNotContainsString('Keluhan privat', json_encode($book->rows()));
        $this->assertSame(['Nomor', 'Tanggal', 'Jenis', 'Metode/status', 'Nominal'], $book->rows()[0]);
        $this->assertIsNumeric($book->rows()[1][4]);
        $product = $pharmacy->products()->first();
        $product->update(['sku' => '001234567890123456', 'name' => '<f>1+1</f> & obat']);
        $export = $this->get(route('reports.index', [$pharmacy, 'kind' => 'inventory', 'q' => '001234567890123456', 'export' => 'xlsx']))
            ->assertOk()->assertDownload('laporan-inventory-'.now()->startOfMonth()->toDateString().'-'.now()->toDateString().'.xlsx');
        $inventory = \Shuchkin\SimpleXLSX::parseData($export->streamedContent());
        $this->assertNotFalse($inventory);
        $this->assertCount(2, $inventory->rows());
        $this->assertSame('001234567890123456', $inventory->rows()[1][0]);
        $this->assertSame('<f>1+1</f> & obat', $inventory->rows()[1][1]);
        $this->assertIsNumeric($inventory->rows()[1][3]);
        $empty = $this->get(route('reports.index', [$pharmacy, 'kind' => 'inventory', 'q' => 'NO-MATCH-EXPORT', 'export' => 'xlsx']))->assertOk();
        $this->assertCount(1, \Shuchkin\SimpleXLSX::parseData($empty->streamedContent())->rows());
        $this->get(route('reports.index', [$pharmacy, 'from' => '2026-11-10', 'to' => '2026-11-01']))->assertSessionHasErrors('operation');
        $foreign = app(AccessManager::class)->provision($owner->id, ['name' => 'Apotek lain']);
        $this->get(route('billing.show', [$foreign, $bill->id]))->assertNotFound();
        $staff = User::factory()->create();
        $role = $pharmacy->roles()->create(['name' => 'Pembaca', 'permissions' => ['billing.view', 'expenses.view', 'supplier_returns.view', 'shifts.view', 'dashboard.view', 'reports.sales'], 'is_owner' => false]);
        $member = $pharmacy->memberships()->create(['user_id' => $staff->id, 'active' => true]);
        app(AccessManager::class)->syncRoles($member, collect([$role]));
        $this->actingAs($staff)->get(route('billing.show', [$pharmacy, $bill->id]))->assertOk();
        $this->post(route('billing.action', [$pharmacy, $bill->id, 'pay']), ['revision' => 1, 'confirmed' => 1, 'payment_method' => 'cash', 'amount_received' => $bill->total])->assertForbidden();
        $this->get(route('reports.index', [$pharmacy, 'kind' => 'finance']))->assertSessionHasErrors('kind');
        $this->get(route('reports.index', [$pharmacy, 'kind' => 'sales', 'export' => 'xlsx']))->assertForbidden();
    }

    public function test_combined_service_and_prescription_payment_is_atomic_and_does_not_duplicate_cash_or_revenue(): void
    {
        [$owner,$pharmacy,,,$receipt] = $this->workspace();
        $this->postedInvoice($pharmacy, $owner, $receipt);
        $product = $pharmacy->products()->first();
        $product->update(['requires_prescription' => true]);
        $visit = $this->visit($pharmacy, $owner);
        $tariff = $this->tariff($pharmacy, $owner);
        $records = app(PrescriptionRecords::class);
        $records->save($pharmacy, $owner->id, $visit, 0, ['valid_until' => now()->addDays(30)->toDateString(), 'items' => [['product_id' => $product->id, 'quantity' => 2, 'dose' => '1 tablet', 'frequency' => '2 kali', 'instructions' => 'Aturan simulasi']]]);
        $records->finalize($pharmacy, $owner->id, $visit, 1);
        $rx = DB::table('prescriptions')->where('visit_id', $visit)->first();
        $sale = app(PrescriptionDispensing::class)->create($pharmacy, $owner->id, $rx->id, ['request_key' => (string) Str::uuid(), 'verified' => 1, 'recipient_type' => 'self', 'items' => [['product_id' => $product->id, 'quantity' => 2]]]);
        $bill = $this->bill($pharmacy, $visit, $tariff, $sale);
        $this->get(route('billing.create', [$pharmacy, 'visit_id' => $visit]))->assertOk()->assertDontSee('Gabungkan draft obat');
        $this->get(route('billing.show', [$pharmacy, $bill->id]))->assertOk()->assertSee('Obat Uji');
        $this->post(route('sales.pay', [$pharmacy, $sale]), ['revision' => 1, 'payment_method' => 'cash', 'amount_received' => 400])->assertSessionHasErrors('operation');
        $this->post(route('sales.cancel', [$pharmacy, $sale]), ['reason' => 'Coba bypass'])->assertSessionHasErrors('operation');
        $shift = app(CashManagement::class)->open($pharmacy, $owner->id, ['request_key' => (string) Str::uuid(), 'opening_cash' => 0]);
        $pay = ['revision' => 1, 'confirmed' => 1, 'payment_method' => 'transfer', 'payment_reference' => 'GABUNG', 'amount_received' => $bill->total];
        $this->post(route('billing.action', [$pharmacy, $bill->id, 'pay']), $pay)->assertSessionHasNoErrors();
        $this->post(route('billing.action', [$pharmacy, $bill->id, 'pay']), $pay)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('sales', ['id' => $sale, 'status' => 'PAID']);
        $this->assertDatabaseHas('service_bills', ['id' => $bill->id, 'status' => 'PAID']);
        $this->assertEquals(3, DB::table('stock_movements')->sum('quantity'));
        $this->assertEquals($bill->total, DB::table('cash_entries')->sum('amount'));
        $this->assertDatabaseCount('cash_entries', 2);
        $this->assertSame(0, CashManagement::expected(DB::table('cash_shifts')->find($shift)));
        $this->assertEquals($bill->total, OperationalReports::query($pharmacy, 'sales', '2026-11-01', '2026-11-30')->sum('amount'));
    }

    public function test_combined_payment_rolls_back_services_when_medicine_stock_changes(): void
    {
        [$owner,$pharmacy,,,$receipt] = $this->workspace();
        $this->postedInvoice($pharmacy, $owner, $receipt);
        $product = $pharmacy->products()->first();
        $product->update(['requires_prescription' => true]);
        $visit = $this->visit($pharmacy, $owner);
        $tariff = $this->tariff($pharmacy, $owner);
        $records = app(PrescriptionRecords::class);
        $records->save($pharmacy, $owner->id, $visit, 0, ['valid_until' => now()->addDays(30)->toDateString(), 'items' => [['product_id' => $product->id, 'quantity' => 5, 'dose' => '1 tablet', 'frequency' => '2 kali', 'instructions' => 'Aturan simulasi']]]);
        $records->finalize($pharmacy, $owner->id, $visit, 1);
        $rx = DB::table('prescriptions')->where('visit_id', $visit)->first();
        $sale = app(PrescriptionDispensing::class)->create($pharmacy, $owner->id, $rx->id, ['request_key' => (string) Str::uuid(), 'verified' => 1, 'recipient_type' => 'self', 'items' => [['product_id' => $product->id, 'quantity' => 5]]]);
        $bill = $this->bill($pharmacy, $visit, $tariff, $sale);
        $return = $this->supplierReturn($pharmacy, $receipt, '1');
        $this->postReturn($pharmacy, $return);
        $this->post(route('billing.action', [$pharmacy, $bill->id, 'pay']), ['revision' => 1, 'confirmed' => 1, 'payment_method' => 'cash', 'amount_received' => $bill->total])->assertSessionHasErrors();
        $this->assertDatabaseHas('service_bills', ['id' => $bill->id, 'status' => 'DRAFT']);
        $this->assertDatabaseHas('sales', ['id' => $sale, 'status' => 'DRAFT']);
        $this->assertDatabaseCount('cash_entries', 0);
        $this->assertEquals(4, DB::table('stock_movements')->sum('quantity'));
    }

    public function test_sales_report_accounts_for_refund_and_cost_without_double_counting(): void
    {
        [$owner,$pharmacy,,,$receipt] = $this->workspace();
        $this->postedInvoice($pharmacy, $owner, $receipt);
        $product = $pharmacy->products()->first();
        $sale = app(PointOfSale::class)->save($pharmacy, $owner->id, ['request_key' => (string) Str::uuid(), 'items' => [['product_id' => $product->id, 'quantity' => 2, 'unit_type' => 'base']]]);
        app(PointOfSale::class)->pay($pharmacy, $owner->id, $sale, ['revision' => 1, 'payment_method' => 'cash', 'amount_received' => 400]);
        $query = OperationalReports::query($pharmacy, 'sales', '2026-11-01', '2026-11-30');
        $this->assertSame(20000, OperationalReports::margin($pharmacy, clone $query)['margin']);
        $movement = DB::table('stock_movements')->where('type', 'SALE')->first();
        $returns = app(SaleReturns::class);
        $id = $returns->create($pharmacy, $owner->id, $sale, ['request_key' => (string) Str::uuid(), 'reason' => 'Dikembalikan', 'condition_checked' => 1, 'items' => [['sale_movement_id' => $movement->id, 'quantity' => '1', 'disposition' => 'RESTOCK']]]);
        $returns->approve($pharmacy, $owner->id, $id, 1);
        $returns->refund($pharmacy, $owner->id, $id, ['revision' => 2, 'refund_method' => 'cash', 'refund_confirmed' => 1]);
        $query = OperationalReports::query($pharmacy, 'sales', '2026-11-01', '2026-11-30');
        $this->assertEquals(200, $query->sum('amount'));
        $margin = OperationalReports::margin($pharmacy, $query);
        $this->assertSame(10000, $margin['margin']);
        $this->assertSame(10000, $margin['cost']);
        $this->get(route('reports.index', [$pharmacy, 'kind' => 'sales', 'sort' => 'amount', 'direction' => 'asc']))->assertOk()->assertSee('Estimasi margin obat setelah refund');
        $this->get(route('reports.index', [$pharmacy, 'kind' => 'finance', 'q' => 'SALE']))->assertOk();
        $this->get(route('reports.index', [$pharmacy, 'kind' => 'inventory', 'q' => $product->sku, 'sort' => 'available']))->assertOk()->assertSee($product->sku);
        $this->assertEquals(200, DB::table('cash_entries')->sum('amount'));
    }

    public function test_catalog_deactivation_invalid_requests_and_readonly_boundaries(): void
    {
        [$owner,$pharmacy,,,$receipt] = $this->workspace();
        $invoice = $this->postedInvoice($pharmacy, $owner, $receipt);
        $visit = $this->visit($pharmacy, $owner);
        $tariff = $this->tariff($pharmacy, $owner);
        $this->post(route('operations.catalog.save', [$pharmacy, 'tariffs']), ['id' => $tariff, 'name' => 'Konsultasi', 'price' => 12, 'active' => 0])->assertSessionHasNoErrors();
        $this->post(route('billing.store', $pharmacy), ['request_key' => (string) Str::uuid(), 'visit_id' => $visit, 'items' => [['tariff_id' => $tariff, 'quantity' => 1]]])->assertSessionHasErrors('operation');
        $this->post(route('operations.catalog.save', [$pharmacy, 'categories']), ['name' => '=SUM(1+1)', 'active' => 1])->assertSessionHasNoErrors();
        $category = DB::table('expense_categories')->where('pharmacy_id', $pharmacy->id)->first();
        $input = ['request_key' => (string) Str::uuid(), 'category_id' => $category->id, 'spent_on' => '2026-11-10', 'description' => 'Privat', 'amount' => 10, 'method' => 'transfer'];
        $this->post(route('expenses.store', $pharmacy), $input)->assertSessionHasErrors('reference');
        $this->post(route('expenses.store', $pharmacy), array_replace($input, ['reference' => 'REF']))->assertSessionHasErrors('operation');
        $shift = app(CashManagement::class)->open($pharmacy, $owner->id, ['request_key' => (string) Str::uuid(), 'opening_cash' => 100]);
        $this->post(route('shifts.open', $pharmacy), ['request_key' => (string) Str::uuid(), 'opening_cash' => 100])->assertSessionHasErrors('operation');
        $staff = User::factory()->create();
        $role = $pharmacy->roles()->create(['name' => 'Kasir pembaca', 'permissions' => ['shifts.view', 'shifts.close', 'expenses.view', 'expenses.create', 'supplier_returns.view'], 'is_owner' => false]);
        $member = $pharmacy->memberships()->create(['user_id' => $staff->id, 'active' => true]);
        app(AccessManager::class)->syncRoles($member, collect([$role]));
        $this->actingAs($staff)->get(route('shifts.show', [$pharmacy, $shift]))->assertForbidden();
        $this->post(route('shifts.action', [$pharmacy, $shift, 'close']), ['revision' => 1, 'counted_cash' => 100])->assertForbidden();
        $return = $this->actingAs($owner)->supplierReturn($pharmacy, $receipt, '1');
        $this->actingAs($staff)->post(route('supplier-returns.action', [$pharmacy, $return->id, 'post']), ['revision' => 1, 'accepted' => 1])->assertForbidden();
        $pharmacy->forceFill(['status' => 'INACTIVE'])->save();
        $this->actingAs($owner)->post(route('supplier-returns.action',[$pharmacy, $return->id, 'post']),['revision' => 1, 'accepted' => 1])->assertStatus(422);
        $this->assertDatabaseHas('supplier_returns',['id' => $return->id, 'status' => 'PENDING']);
    }
}
