<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AccessManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProcurementTest extends TestCase
{
    use RefreshDatabase;

    private function workspace(): array
    {
        $owner = User::factory()->create();
        $pharmacy = app(AccessManager::class)->provision($owner->id, ['name' => 'Apotek Pembelian']);
        $pharmacy->forceFill(['status' => 'ACTIVE'])->save();
        $pharmacy->settings()->update(['track_batch' => true, 'track_expiry' => true]);
        $supplier = $pharmacy->suppliers()->create(['code' => 'SUP-TEST', 'name' => 'Supplier Uji', 'active' => true, 'payment_days' => 30]);
        $product = $pharmacy->products()->create(['sku' => 'TEST-OBAT', 'name' => 'Obat Uji', 'brand' => 'Brand Uji', 'category' => 'Obat', 'base_unit' => 'Tablet', 'purchase_unit' => 'Box', 'purchase_conversion' => 100, 'purchase_price' => 100.10, 'selling_price' => 2, 'minimum_stock' => 0, 'requires_prescription' => true, 'active' => true]);

        return [$owner, $pharmacy, $supplier, $product];
    }

    private function payload($supplier, $product): array
    {
        return ['supplier_id' => $supplier->id, 'ordered_on' => '2026-11-01', 'expected_on' => '2026-11-05', 'payment_type' => 'credit', 'payment_days' => 30,
            'items' => [['product_id' => $product->id, 'unit_type' => 'purchase', 'quantity' => '2.500', 'unit_price' => '100.10', 'discount' => '0.25', 'tax' => '1.00']]];
    }

    public function test_purchase_letter_exports_pdf_with_order_data_and_enforces_access(): void
    {
        [$owner, $pharmacy, $supplier, $product] = $this->workspace();
        $this->actingAs($owner)->post(route('purchases.store', $pharmacy), $this->payload($supplier, $product))->assertSessionHasNoErrors();
        $order = $pharmacy->purchaseOrders()->firstOrFail();
        $item = $order->items()->firstOrFail();
        $data = ['kind' => 'psikotropika', 'signer' => 'apt. Apoteker Uji', 'signer_address' => 'Alamat apotek uji',
            'signer_phone' => '081234567890', 'sip' => 'SIPA-UJI', 'supplier_address' => 'Alamat PBF uji', 'city' => 'Cianjur',
            'ingredients' => [$item->id => 'Zat aktif uji'], 'strengths' => [$item->id => '2 mg']];
        $this->get(route('purchases.show', [$pharmacy, $order]))->assertOk()->assertSee('Ekspor surat pesanan PDF');
        $this->get(route('purchases.letter', [$pharmacy, $order]))->assertOk()->assertSee($order->number)->assertSee('DRAFT');
        $response = $this->post(route('purchases.letter.export', [$pharmacy, $order]), $data)
            ->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $this->assertDatabaseHas('audit_entries', ['action' => 'purchase.letter_exported', 'pharmacy_id' => $pharmacy->id]);
        $context = app(\App\Services\PurchaseLetter::class)->context($pharmacy, $order);
        $html = view('procurement.letter-pdf', $context + ['data' => $data, 'logo' => null])->render();
        $this->assertStringContainsString('PSIKOTROPIKA', $html);
        $this->assertStringContainsString('2,5 (dua koma lima)', $html);
        $this->assertStringContainsString('SIPA-UJI', $html);
        $this->assertStringNotContainsString('100.10', $html);
        $this->post(route('purchases.letter.export', [$pharmacy, $order]), array_replace($data, ['kind' => 'invalid']))->assertSessionHasErrors('kind');
        $other = app(AccessManager::class)->provision($owner->id, ['name' => 'Apotek lain']);
        $this->get(route('purchases.letter', [$other, $order]))->assertNotFound();
        $this->post(route('purchases.letter.export', [$other, $order]), $data)->assertNotFound();
        $staff = User::factory()->create();
        $member = $pharmacy->memberships()->create(['user_id' => $staff->id, 'active' => true]);
        $role = $pharmacy->roles()->create(['name' => 'Tanpa pembelian', 'permissions' => ['dashboard.view'], 'is_owner' => false]);
        app(AccessManager::class)->syncRoles($member, collect([$role]));
        $this->actingAs($staff)->get(route('purchases.letter', [$pharmacy, $order]))->assertForbidden();
        $this->post(route('purchases.letter.export', [$pharmacy, $order]), $data)->assertForbidden();
        $order->update(['status' => 'CANCELLED']);
        $this->actingAs($owner)->post(route('purchases.letter.export', [$pharmacy, $order]), $data)->assertStatus(422);
    }

    private function receipt($item, string $qty = '1.250'): array
    {
        return ['received_on' => '2026-11-04', 'invoice_number' => 'INV-TEST', 'notes' => 'Sisa barang menyusul.',
            'items' => [['order_item_id' => $item->id, 'quantity' => $qty, 'batch_number' => 'BATCH-01', 'expires_on' => '2030-12-31', 'unit_price' => '100.20', 'location' => 'Rak A']]];
    }

    public function test_receipt_ppn_percentage_nominal_validation_and_posted_lock(): void
    {
        [$owner, $pharmacy, $supplier, $product] = $this->workspace();
        $this->actingAs($owner)->post(route('purchases.store', $pharmacy), $this->payload($supplier, $product))->assertSessionHasNoErrors();
        $order = $pharmacy->purchaseOrders()->firstOrFail();
        $this->post(route('purchases.finalize', [$pharmacy, $order]));
        $data = $this->receipt($order->items()->firstOrFail());
        $data['ppn_type'] = 'percentage';
        $data['ppn_value'] = '11';
        $this->post(route('receipts.store', [$pharmacy, $order]), $data)->assertSessionHasNoErrors();
        $receipt = $order->receipts()->firstOrFail();
        $this->assertSame('125.25', $receipt->subtotal);
        $this->assertSame('13.78', $receipt->ppn_amount);
        $this->assertSame('139.03', $receipt->total);
        $this->get(route('receipts.edit', [$pharmacy, $receipt]))->assertOk()->assertSee('PPN penerimaan');
        foreach ([['percentage', '100.01'], ['nominal', '-1'], ['invalid', '11']] as [$type, $value]) {
            $invalid = array_replace($data, ['ppn_type' => $type, 'ppn_value' => $value]);
            $this->put(route('receipts.update', [$pharmacy, $receipt]), $invalid)->assertSessionHasErrors();
        }
        $data['ppn_type'] = 'nominal';
        $data['ppn_value'] = '25.50';
        $this->put(route('receipts.update', [$pharmacy, $receipt]), $data)->assertSessionHasNoErrors();
        $this->assertSame('150.75', $receipt->fresh()->total);
        $this->post(route('receipts.post', [$pharmacy, $receipt]))->assertSessionHasNoErrors();
        $data['ppn_value'] = '0';
        $this->put(route('receipts.update', [$pharmacy, $receipt]), $data)->assertSessionHasErrors();
        $this->assertSame('25.50', $receipt->fresh()->ppn_amount);
        $this->get(route('receipts.show', [$pharmacy, $receipt]))->assertOk()->assertSee('150,75');
    }

    public function test_receipt_ppn_can_round_per_product_or_total(): void
    {
        [$owner, $pharmacy, $supplier, $product] = $this->workspace();
        $other = $pharmacy->products()->create(['sku' => 'SECOND', 'name' => 'Obat lain', 'brand' => 'Lain', 'category' => 'Obat', 'base_unit' => 'Tablet', 'purchase_unit' => 'Tablet', 'purchase_conversion' => 1, 'selling_price' => 1, 'minimum_stock' => 0, 'requires_prescription' => true, 'active' => true]);
        $payload = $this->payload($supplier, $product);
        $payload['items'] = [
            ['product_id' => $product->id, 'unit_type' => 'base', 'quantity' => 1, 'unit_price' => '0.05', 'discount' => 0, 'tax' => 0],
            ['product_id' => $other->id, 'unit_type' => 'base', 'quantity' => 1, 'unit_price' => '0.05', 'discount' => 0, 'tax' => 0],
        ];
        $this->actingAs($owner)->post(route('purchases.store', $pharmacy), $payload)->assertSessionHasNoErrors();
        $order = $pharmacy->purchaseOrders()->firstOrFail();
        $this->post(route('purchases.finalize', [$pharmacy, $order]))->assertSessionHasNoErrors();
        $items = $order->items()->get()->map(fn ($item) => ['order_item_id' => $item->id, 'quantity' => 1, 'unit_price' => '0.05', 'batch_number' => 'B', 'expires_on' => '2030-12-31'])->all();
        $input = ['received_on' => '2026-11-04', 'ppn_type' => 'percentage', 'ppn_value' => '10', 'ppn_scope' => 'product', 'items' => $items];
        $this->post(route('receipts.store', [$pharmacy, $order]), $input)->assertSessionHasNoErrors();
        $receipt = $order->receipts()->firstOrFail();
        $this->assertSame('0.02', $receipt->ppn_amount);
        $this->assertSame('0.12', $receipt->total);
        $input['ppn_scope'] = 'total';
        $this->put(route('receipts.update', [$pharmacy, $receipt]), $input)->assertSessionHasNoErrors();
        $this->assertSame('0.01', $receipt->fresh()->ppn_amount);
        $this->assertSame('0.11', $receipt->fresh()->total);
    }
    public function test_supplier_pages_crud_sorting_and_status(): void
    {
        [$owner, $pharmacy, $supplier] = $this->workspace();
        $this->actingAs($owner)->get(route('suppliers.index', [$pharmacy, 'sort' => 'terms', 'direction' => 'desc']))->assertOk()->assertSee('Supplier Uji');
        $this->get(route('suppliers.create', $pharmacy))->assertOk();
        $this->get(route('suppliers.edit', [$pharmacy, $supplier]))->assertOk();
        $data = ['name' => 'Supplier Baru', 'contact_name' => 'Kontak', 'phone' => '081234', 'email' => 'supplier@example.invalid', 'payment_days' => 14];
        $this->post(route('suppliers.store', $pharmacy), $data)->assertSessionHasNoErrors()->assertRedirect();
        $this->put(route('suppliers.update', [$pharmacy, $supplier]), $data)->assertSessionHasNoErrors();
        $this->patch(route('suppliers.status', [$pharmacy, $supplier]), ['active' => 0])->assertRedirect();
        $this->assertFalse($supplier->fresh()->active);
        $this->assertDatabaseHas('audit_entries', ['action' => 'supplier.created']);
    }

    public function test_full_purchase_flow_partial_receipts_exact_totals_and_no_double_post(): void
    {
        [$owner, $pharmacy, $supplier, $product] = $this->workspace();
        $this->actingAs($owner)->get(route('purchases.index', $pharmacy))->assertOk();
        $this->get(route('purchases.create', $pharmacy))->assertOk()->assertSee('Brand Uji');
        $this->post(route('purchases.store', $pharmacy), $this->payload($supplier, $product))->assertSessionHasNoErrors();
        $order = $pharmacy->purchaseOrders()->firstOrFail();
        $this->assertSame('251.00', $order->total);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->get(route('purchases.show', [$pharmacy, $order]))->assertOk();
        $this->get(route('purchases.edit', [$pharmacy, $order]))->assertOk();
        $this->post(route('purchases.finalize', [$pharmacy, $order]))->assertSessionHasNoErrors();
        $this->assertSame('ORDERED', $order->fresh()->status);
        $this->put(route('purchases.update', [$pharmacy, $order]), $this->payload($supplier, $product))->assertSessionHasErrors();
        $this->get(route('receipts.create', [$pharmacy, $order]))->assertOk();
        $item = $order->items()->firstOrFail();
        $this->post(route('receipts.store', [$pharmacy, $order]), $this->receipt($item))->assertSessionHasNoErrors();
        $receipt = $order->receipts()->firstOrFail();
        $this->get(route('receipts.show', [$pharmacy, $receipt]))->assertOk();
        $this->get(route('receipts.edit', [$pharmacy, $receipt]))->assertOk();
        $this->put(route('receipts.update', [$pharmacy, $receipt]), $this->receipt($item))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('purchase_receipts', 1);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->post(route('receipts.post', [$pharmacy, $receipt]))->assertSessionHasNoErrors();
        $this->assertSame('PARTIAL', $order->fresh()->status);
        $this->assertSame('100.20', $product->fresh()->purchase_price);
        $this->assertEquals(125, DB::table('stock_movements')->sum('quantity'));
        $this->assertTrue($pharmacy->fresh()->has_transactions);
        $this->post(route('receipts.post', [$pharmacy, $receipt]))->assertSessionHasErrors();
        $this->assertDatabaseCount('stock_movements', 1);
        $this->put(route('receipts.update', [$pharmacy, $receipt]), $this->receipt($item))->assertSessionHasErrors();
        $lowerPrice = $this->receipt($item);
        $lowerPrice['items'][0]['unit_price'] = '80.00';
        $this->post(route('receipts.store', [$pharmacy, $order]), $lowerPrice)->assertSessionHasNoErrors();
        $second = $order->receipts()->where('id', '!=', $receipt->id)->firstOrFail();
        $this->post(route('receipts.post', [$pharmacy, $second]))->assertSessionHasNoErrors();
        $this->assertSame('100.20', $product->fresh()->purchase_price);
        $this->assertSame('RECEIVED', $order->fresh()->status);
        $this->assertEquals(250, DB::table('stock_movements')->sum('quantity'));
        $this->post(route('purchases.cancel', [$pharmacy, $order]), ['reason' => 'Batal setelah diterima'])->assertSessionHasErrors();
    }

    public function test_over_receipt_and_stale_draft_posting_cannot_overfill_po(): void
    {
        [$owner, $pharmacy, $supplier, $product] = $this->workspace();
        $this->actingAs($owner)->post(route('purchases.store', $pharmacy), $this->payload($supplier, $product));
        $order = $pharmacy->purchaseOrders()->firstOrFail();
        $this->post(route('purchases.finalize', [$pharmacy, $order]));
        $item = $order->items()->firstOrFail();
        $this->post(route('receipts.store', [$pharmacy, $order]), $this->receipt($item, '3'))->assertSessionHasErrors();
        $this->post(route('receipts.store', [$pharmacy, $order]), $this->receipt($item, '2'))->assertSessionHasNoErrors();
        $first = $order->receipts()->firstOrFail();
        $this->post(route('receipts.store', [$pharmacy, $order]), $this->receipt($item, '2'))->assertSessionHasNoErrors();
        $second = $order->receipts()->where('id', '!=', $first->id)->firstOrFail();
        $this->post(route('receipts.post', [$pharmacy, $first]))->assertSessionHasNoErrors();
        $this->post(route('receipts.post', [$pharmacy, $second]))->assertSessionHasErrors();
        $this->assertSame('DRAFT', $second->fresh()->status);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertEquals(200, DB::table('stock_movements')->sum('quantity'));
    }

    public function test_validation_rejects_cross_tenant_inactive_supplier_and_missing_batch_expiry(): void
    {
        [$owner, $pharmacy, $supplier, $product] = $this->workspace();
        [, $other, $foreignSupplier, $foreignProduct] = $this->workspace();
        $this->actingAs($owner)->post(route('purchases.store', $pharmacy), $this->payload($foreignSupplier, $product))->assertSessionHasErrors();
        $this->post(route('purchases.store', $pharmacy), $this->payload($supplier, $foreignProduct))->assertSessionHasErrors();
        $supplier->update(['active' => false]);
        $this->post(route('purchases.store', $pharmacy), $this->payload($supplier, $product))->assertSessionHasErrors();
        $supplier->update(['active' => true]);
        $this->post(route('purchases.store', $pharmacy), $this->payload($supplier, $product));
        $order = $pharmacy->purchaseOrders()->firstOrFail();
        $this->post(route('purchases.finalize', [$pharmacy, $order]));
        $data = $this->receipt($order->items()->firstOrFail());
        $data['items'][0]['batch_number'] = null;
        $this->post(route('receipts.store', [$pharmacy, $order]), $data)->assertSessionHasErrors();
        $data['items'][0]['batch_number'] = 'VALID';
        $data['items'][0]['expires_on'] = $data['received_on'];
        $this->post(route('receipts.store', [$pharmacy, $order]), $data)->assertSessionHasErrors();
        $this->get(route('purchases.show', [$other, $order]))->assertForbidden();
        $this->assertDatabaseCount('purchase_receipts', 0);
    }

    public function test_inactive_pharmacy_and_view_only_member_cannot_mutate_transactions(): void
    {
        [$owner, $pharmacy, $supplier, $product] = $this->workspace();
        $this->actingAs($owner);
        $pharmacy->forceFill(['status' => 'INACTIVE'])->save();
        $this->post(route('purchases.store', $pharmacy), $this->payload($supplier, $product))->assertUnprocessable();
        $pharmacy->forceFill(['status' => 'ACTIVE'])->save();
        $viewer = User::factory()->create();
        $member = $pharmacy->memberships()->create(['user_id' => $viewer->id, 'active' => true]);
        $role = $pharmacy->roles()->create(['name' => 'Pembaca', 'permissions' => ['suppliers.view', 'purchases.view']]);
        app(AccessManager::class)->syncRoles($member, collect([$role]));
        $this->actingAs($viewer)->get(route('purchases.index', $pharmacy))->assertOk();
        $this->get(route('suppliers.index', $pharmacy))->assertOk();
        $this->post(route('purchases.store', $pharmacy), $this->payload($supplier, $product))->assertForbidden();
        $this->post(route('suppliers.store', $pharmacy), ['name' => 'Tidak boleh', 'payment_days' => 0])->assertForbidden();
        $this->assertDatabaseCount('purchase_orders', 0);
    }

    public function test_cancelled_order_cannot_be_received_and_snapshot_is_preserved(): void
    {
        [$owner, $pharmacy, $supplier, $product] = $this->workspace();
        $this->actingAs($owner)->post(route('purchases.store', $pharmacy), $this->payload($supplier, $product));
        $order = $pharmacy->purchaseOrders()->firstOrFail();
        $this->post(route('purchases.finalize', [$pharmacy, $order]));
        $product->update(['name' => 'Nama baru', 'purchase_price' => 999]);
        $this->assertSame('Obat Uji', $order->items()->first()->product_name);
        $this->post(route('purchases.cancel', [$pharmacy, $order]), ['reason' => 'Supplier tidak tersedia'])->assertSessionHasNoErrors();
        $this->assertSame('CANCELLED', $order->fresh()->status);
        $this->post(route('receipts.store', [$pharmacy, $order]), $this->receipt($order->items()->firstOrFail()))->assertSessionHasErrors();
        $this->assertDatabaseCount('stock_movements', 0);
    }
}
