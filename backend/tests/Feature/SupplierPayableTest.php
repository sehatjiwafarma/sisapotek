<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AccessManager;
use App\Services\Procurement;
use App\Services\SupplierPayables;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class SupplierPayableTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_only_posted_credit_receipts_issue_one_invoice_with_actual_total_and_snapshot_terms(): void
    {
        [$owner,$pharmacy,$supplier,$order,$receipt] = $this->workspace();
        $this->assertDatabaseCount('supplier_invoices', 0);
        $invoice = $this->postedInvoice($pharmacy, $owner, $receipt);
        $this->assertEquals(555, $invoice->amount);
        $this->assertSame('2026-12-04', $invoice->due_on);
        $this->assertNotNull($invoice->reconciled_at);
        $this->post(route('receipts.post', [$pharmacy, $receipt->id]))->assertSessionHasErrors();
        $this->assertDatabaseCount('supplier_invoices', 1);
        $supplier->update(['payment_days' => 3, 'name' => 'Supplier Baru']);
        $this->assertSame('Supplier Utang', DB::table('supplier_invoices')->find($invoice->id)->supplier_name);
        $this->assertSame('2026-12-04', DB::table('supplier_invoices')->find($invoice->id)->due_on);
        $other = app(Procurement::class)->receive($pharmacy, $owner->id, $order->id, ['received_on' => '2026-11-05', 'items' => [['order_item_id' => $order->items()->first()->id, 'quantity' => 5, 'unit_price' => 120, 'batch_number' => 'L2', 'expires_on' => '2030-01-01']]]);
        $this->postedInvoice($pharmacy, $owner, $other);
        $this->assertDatabaseCount('supplier_invoices', 2);
        $this->assertEquals(1155, DB::table('supplier_invoices')->sum('amount'));
        $this->get(route('receipts.show', [$pharmacy, $receipt->id]))->assertOk()->assertSee('Tagihan & pembayaran supplier', false);
        $this->get(route('payables.index', [$pharmacy, 'supplier' => $supplier->id, 'sort' => 'outstanding']))->assertOk()->assertSee($invoice->number)->assertSee('Supplier Utang');
        $this->get(route('payables.show', [$pharmacy, $invoice->id]))->assertOk()->assertSee('555,00');
    }

    public function test_cash_receipt_does_not_become_a_payable(): void
    {
        [$owner,$pharmacy,,,$receipt] = $this->workspace('cash');
        app(Procurement::class)->post($pharmacy, $owner->id, $receipt->id);
        $this->assertDatabaseCount('supplier_invoices', 0);
    }

    public function test_partial_and_final_payments_are_exact_idempotent_and_leave_stock_unchanged(): void
    {
        [$owner,$pharmacy,,,$receipt] = $this->workspace();
        $invoice = $this->postedInvoice($pharmacy, $owner, $receipt);
        $stock = DB::table('stock_movements')->get()->toJson();
        $first = $this->payment($pharmacy, $invoice);
        $this->assertSame(55500, SupplierPayables::balances($pharmacy, $invoice)['outstanding']);
        $this->get(route('payables.receipt', [$pharmacy, $first->id]))->assertStatus(422);
        $this->finish($pharmacy, $first);
        $this->post(route('payables.post', [$pharmacy, $first->id]), ['revision' => 2, 'paid_confirmed' => 1])->assertSessionHasNoErrors();
        $this->assertSame(45475, SupplierPayables::balances($pharmacy, $invoice)['outstanding']);
        $this->assertSame('PARTIAL', SupplierPayables::query($pharmacy)->where('id', $invoice->id)->value('balance_status'));
        $second = $this->payment($pharmacy, $invoice, '454.75');
        $this->finish($pharmacy, $second);
        $this->assertSame(0, SupplierPayables::balances($pharmacy, $invoice)['outstanding']);
        $this->assertSame('PAID', SupplierPayables::query($pharmacy)->where('id', $invoice->id)->value('balance_status'));
        $this->assertSame($stock, DB::table('stock_movements')->get()->toJson());
        $this->assertSame(2, DB::table('audit_entries')->where('action', 'supplier_payment.posted')->count());
        $this->get(route('payables.receipt', [$pharmacy, $first->id]))->assertOk()->assertSee('100,25')->assertSee('Supplier Utang');
        $this->post(route('payables.cancel', [$pharmacy, $first->id]), ['revision' => 3, 'reason' => 'Terlambat'])->assertSessionHasErrors('payable');
    }

    public function test_approval_rechecks_balance_reserves_amount_and_cancellation_releases_allocation(): void
    {
        [$owner,$pharmacy,,,$receipt] = $this->workspace();
        $invoice = $this->postedInvoice($pharmacy, $owner, $receipt);
        $first = $this->payment($pharmacy, $invoice, '400');
        $second = $this->payment($pharmacy, $invoice, '400');
        $this->post(route('payables.approve', [$pharmacy, $first->id]), ['revision' => 2])->assertSessionHasErrors('payable');
        $this->post(route('payables.approve', [$pharmacy, $first->id]), ['revision' => 1])->assertSessionHasNoErrors();
        $this->post(route('payables.approve', [$pharmacy, $second->id]), ['revision' => 1])->assertSessionHasErrors('payable');
        $this->post(route('payables.post', [$pharmacy, $second->id]), ['revision' => 1, 'paid_confirmed' => 1])->assertSessionHasErrors('payable');
        $this->assertSame(15500, SupplierPayables::balances($pharmacy, $invoice)['available']);
        $this->post(route('payables.cancel', [$pharmacy, $first->id]), ['revision' => 1, 'reason' => 'Revisi'])->assertSessionHasErrors('payable');
        $this->post(route('payables.cancel', [$pharmacy, $first->id]), ['revision' => 2, 'reason' => 'Revisi'])->assertSessionHasNoErrors();
        $this->finish($pharmacy, $second);
        $this->assertSame(15500, SupplierPayables::balances($pharmacy, $invoice)['outstanding']);
    }

    public function test_proof_is_private_encrypted_and_can_be_attached_after_approval(): void
    {
        [$owner,$pharmacy,,,$receipt] = $this->workspace();
        $invoice = $this->postedInvoice($pharmacy, $owner, $receipt);
        $image = $this->image();
        $bytes = $image->getContent();
        $payment = $this->payment($pharmacy, $invoice, '50', ['proof' => $image]);
        $this->assertNotSame($bytes, Storage::disk('local')->get($payment->proof_path));
        $this->assertSame($bytes, Crypt::decryptString(Storage::disk('local')->get($payment->proof_path)));
        $this->get(route('payables.proof', [$pharmacy, $payment->id]))->assertOk()->assertContent($bytes)->assertHeader('Content-Type', 'image/png');
        $this->get(route('payables.payment', [$pharmacy, $payment->id]))->assertOk()->assertDontSee($payment->proof_path);
        $this->finish($pharmacy, $payment);
        $second = $this->payment($pharmacy, $invoice, '25');
        $this->post(route('payables.approve', [$pharmacy, $second->id]), ['revision' => 1])->assertSessionHasNoErrors();
        $this->post(route('payables.post', [$pharmacy, $second->id]), ['revision' => 2, 'paid_confirmed' => 1, 'proof' => $this->image()])->assertSessionHasNoErrors();
        $this->assertNotNull(DB::table('supplier_payments')->find($second->id)->proof_path);
        $this->assertStringNotContainsString('REF-PRIVAT', DB::table('audit_entries')->pluck('details')->implode(' '));
    }

    public function test_invalid_amount_date_reference_files_and_missing_proof_do_not_reduce_debt(): void
    {
        [$owner,$pharmacy,,,$receipt] = $this->workspace();
        $invoice = $this->postedInvoice($pharmacy, $owner, $receipt);
        $url = route('payables.store', [$pharmacy, $invoice->id]);
        $this->post($url, $this->data('555.01'))->assertSessionHasErrors('payable');
        $this->post($url, $this->data('0'))->assertSessionHasErrors('amount');
        $this->post($url, $this->data('0.001'))->assertSessionHasErrors('amount');
        $this->post($url, array_replace($this->data(), ['reference' => '']))->assertSessionHasErrors('reference');
        $this->post($url, array_replace($this->data(), ['paid_on' => '2026-11-03']))->assertSessionHasErrors('payable');
        $this->post($url, array_replace($this->data(), ['paid_on' => '2026-11-10']))->assertSessionHasErrors('payable');
        $this->post($url, $this->data() + ['proof' => UploadedFile::fake()->create('bad.html', 1, 'text/html')])->assertSessionHasErrors('proof');
        $data = $this->data();
        $this->post($url, $data)->assertSessionHasNoErrors();
        $this->post($url, $data)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('supplier_payments', 1);
        $payment = $this->payment($pharmacy, $invoice, '20', ['proof' => $this->image()]);
        $this->post(route('payables.approve', [$pharmacy, $payment->id]), ['revision' => 1])->assertSessionHasNoErrors();
        Storage::disk('local')->delete($payment->proof_path);
        $this->post(route('payables.post', [$pharmacy, $payment->id]), ['revision' => 2, 'paid_confirmed' => 1])->assertSessionHasErrors('payable');
        $this->assertSame(55500, SupplierPayables::balances($pharmacy, $invoice)['outstanding']);
    }

    public function test_historical_receipts_are_backfilled_and_require_one_time_opening_balance_confirmation(): void
    {
        [$owner,$pharmacy,,,$receipt] = $this->workspace();
        $this->postedInvoice($pharmacy, $owner, $receipt);
        Schema::drop('supplier_payments');
        Schema::drop('supplier_invoices');
        $migration = require database_path('migrations/2026_11_09_134413_create_supplier_payables_tables.php');
        $migration->up();
        Schema::table('supplier_invoices', fn (Blueprint $table) => $table->decimal('credit_amount', 18, 2)->default(0));
        $invoice = DB::table('supplier_invoices')->where('purchase_receipt_id', $receipt->id)->first();
        $this->assertNull($invoice->reconciled_at);
        $this->post(route('payables.store', [$pharmacy, $invoice->id]), $this->data())->assertSessionHasErrors('payable');
        $this->post(route('payables.reconcile', [$pharmacy, $invoice->id]), ['revision' => 1, 'outstanding' => 556, 'reason' => 'Kartu utang lama'])->assertSessionHasErrors('payable');
        $this->post(route('payables.reconcile', [$pharmacy, $invoice->id]), ['revision' => 1, 'outstanding' => 100, 'reason' => 'Kartu utang lama'])->assertSessionHasNoErrors();
        $invoice = DB::table('supplier_invoices')->find($invoice->id);
        $this->assertEquals(455, $invoice->opening_paid_amount);
        $this->assertSame(10000, SupplierPayables::balances($pharmacy, $invoice)['outstanding']);
        $payment = $this->payment($pharmacy, $invoice, '100');
        $this->finish($pharmacy, $payment);
        $this->assertSame(0, SupplierPayables::balances($pharmacy, $invoice)['outstanding']);
        $this->post(route('payables.reconcile', [$pharmacy, $invoice->id]), ['revision' => 3, 'outstanding' => 555, 'reason' => 'Coba lagi'])->assertSessionHasErrors('payable');
    }

    public function test_tenant_read_only_access_and_proof_permission_are_enforced(): void
    {
        [$owner,$pharmacy,,,$receipt] = $this->workspace();
        $invoice = $this->postedInvoice($pharmacy, $owner, $receipt);
        $payment = $this->payment($pharmacy, $invoice, '10', ['proof' => $this->image()]);
        $foreign = app(AccessManager::class)->provision($owner->id, ['name' => 'Apotek lain']);
        $this->get(route('payables.show', [$foreign, $invoice->id]))->assertNotFound();
        $this->get(route('payables.proof', [$foreign, $payment->id]))->assertNotFound();
        $staff = User::factory()->create();
        $role = $pharmacy->roles()->create(['name' => 'Pembaca utang', 'permissions' => ['payables.view'], 'is_owner' => false]);
        $member = $pharmacy->memberships()->create(['user_id' => $staff->id, 'active' => true]);
        app(AccessManager::class)->syncRoles($member, collect([$role]));
        $this->actingAs($staff)->get(route('payables.payment', [$pharmacy, $payment->id]))->assertOk();
        $this->get(route('payables.proof', [$pharmacy, $payment->id]))->assertForbidden();
        $this->post(route('payables.store', [$pharmacy, $invoice->id]), $this->data())->assertForbidden();
        $this->post(route('payables.approve', [$pharmacy, $payment->id]), ['revision' => 1])->assertForbidden();
        $this->post(route('payables.post', [$pharmacy, $payment->id]), ['revision' => 1, 'paid_confirmed' => 1])->assertForbidden();
        $this->post(route('payables.cancel', [$pharmacy, $payment->id]), ['revision' => 1, 'reason' => 'Coba'])->assertForbidden();
        $this->assertSame(55500, SupplierPayables::balances($pharmacy, $invoice)['outstanding']);
    }
}
