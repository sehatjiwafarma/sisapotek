<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AccessManager;
use App\Services\SaleReturns;
use App\Services\StockLots;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SaleReturnTest extends TestCase
{
    use RefreshDatabase;

    private function workspace(): array
    {
        $this->travelTo(now()->setDate(2026, 11, 8)->setTime(12, 0));
        $owner = User::factory()->create();
        $pharmacy = app(AccessManager::class)->provision($owner->id, ['name' => 'Apotek POS']);
        $pharmacy->forceFill(['status' => 'ACTIVE'])->save();
        $supplier = $pharmacy->suppliers()->create(['code' => 'POS-SUP', 'name' => 'Supplier POS', 'active' => true]);
        $product = $pharmacy->products()->create(['sku' => 'POS-OBAT', 'name' => 'Produk Umum', 'brand' => 'Brand Umum', 'category' => 'Obat', 'barcode' => '123456789', 'base_unit' => 'Tablet', 'purchase_unit' => 'Box', 'purchase_conversion' => 10, 'selling_price' => '1250.25', 'minimum_stock' => 5, 'requires_prescription' => false, 'active' => true]);
        $this->actingAs($owner)->post(route('purchases.store', $pharmacy), ['supplier_id' => $supplier->id, 'ordered_on' => '2026-10-01', 'payment_type' => 'cash', 'payment_days' => 0,
            'items' => [['product_id' => $product->id, 'unit_type' => 'base', 'quantity' => 100, 'unit_price' => 500, 'discount' => 0, 'tax' => 0]]])->assertSessionHasNoErrors();
        $order = $pharmacy->purchaseOrders()->firstOrFail();
        $this->post(route('purchases.finalize', [$pharmacy, $order]))->assertSessionHasNoErrors();
        foreach ([['EXPIRED', '2026-11-08', 10], ['LATER', '2030-01-01', 8], ['EARLY', '2026-12-01', 3]] as [$batch,$expiry,$quantity]) {
            $this->post(route('receipts.store', [$pharmacy, $order]), ['received_on' => '2026-10-10', 'notes' => 'Penerimaan parsial',
                'items' => [['order_item_id' => $order->items()->firstOrFail()->id, 'quantity' => $quantity, 'unit_price' => 500, 'batch_number' => $batch, 'expires_on' => $expiry, 'location' => 'Rak A']]])->assertSessionHasNoErrors();
            $receipt = $order->receipts()->where('status', 'DRAFT')->firstOrFail();
            $this->post(route('receipts.post', [$pharmacy, $receipt]))->assertSessionHasNoErrors();
        }

        return [$owner, $pharmacy, $product];
    }

    private function draft($pharmacy, $product, string $quantity = '5', string $unit = 'base', string $discount = '0'): object
    {
        $response = $this->post(route('sales.store', $pharmacy), ['request_key' => (string) Str::uuid(), 'discount' => $discount,
            'items' => [['product_id' => $product->id, 'quantity' => $quantity, 'unit_type' => $unit]]])->assertSessionHasNoErrors()->assertRedirect();

        return DB::table('sales')->find(basename(parse_url($response->headers->get('Location'), PHP_URL_PATH)));
    }

    private function payment(object $sale, string $method = 'cash', ?string $amount = null): array
    {
        return ['revision' => $sale->revision, 'payment_method' => $method, 'amount_received' => $amount ?? $sale->total, 'payment_reference' => 'BUKTI-01'];
    }

    private function paidSale($pharmacy, $product): object
    {
        $sale = $this->draft($pharmacy, $product, '5', 'base', '1.25');
        $this->post(route('sales.pay', [$pharmacy, $sale->id]), $this->payment($sale))->assertSessionHasNoErrors();

        return DB::table('sales')->find($sale->id);
    }

    private function requestReturn($pharmacy, $sale, array $rows, array $extra = []): object
    {
        $response = $this->post(route('returns.store', [$pharmacy, $sale->id]), $extra + ['request_key' => (string) Str::uuid(), 'reason' => 'Barang dikembalikan', 'condition_checked' => 1, 'items' => $rows])->assertSessionHasNoErrors()->assertRedirect();

        return DB::table('sale_returns')->find(basename(parse_url($response->headers->get('Location'), PHP_URL_PATH)));
    }

    private function approveReturn($pharmacy, $record): void
    {
        $this->post(route('returns.approve', [$pharmacy, $record->id]), ['revision' => $record->revision])->assertSessionHasNoErrors();
    }

    private function refundReturn($pharmacy, $record): void
    {
        $revision = DB::table('sale_returns')->where('id', $record->id)->value('revision');
        $this->post(route('returns.refund', [$pharmacy, $record->id]), ['revision' => $revision, 'refund_method' => 'cash', 'refund_confirmed' => 1])->assertSessionHasNoErrors();
    }

    public function test_partial_refund_restores_original_batch_once_and_is_sellable_again(): void
    {
        [, $pharmacy,$product] = $this->workspace();
        $sale = $this->paidSale($pharmacy, $product);
        $movement = DB::table('stock_movements')->where('sale_item_id', DB::table('sale_items')->where('sale_id', $sale->id)->value('id'))->where('batch_number', 'EARLY')->first();
        // Older POS allocations link only to the source receipt item.
        DB::table('stock_movements')->where('id', $movement->id)->update(['source_movement_id' => null]);
        $before = DB::table('stock_movements')->sum('quantity');
        $record = $this->requestReturn($pharmacy, $sale, [['sale_movement_id' => $movement->id, 'quantity' => '2', 'disposition' => 'RESTOCK']], ['refund_amount' => 99999999]);
        $this->assertEquals(2500, $record->refund_amount);
        $this->assertEquals($before, DB::table('stock_movements')->sum('quantity'));
        $this->get(route('returns.index', $pharmacy))->assertOk()->assertSee($record->number);
        $this->get(route('returns.select', [$pharmacy, 'q' => $sale->number]))->assertOk()->assertSee($sale->number);
        $this->get(route('returns.create', [$pharmacy, $sale->id]))->assertOk()->assertSee('Karantina');
        $this->get(route('returns.show', [$pharmacy, $record->id]))->assertOk()->assertSee('Menunggu persetujuan');
        $this->get(route('returns.receipt', [$pharmacy, $record->id]))->assertStatus(422);
        $this->approveReturn($pharmacy, $record);
        $this->assertEquals($before, DB::table('stock_movements')->sum('quantity'));
        $this->refundReturn($pharmacy, $record);
        $this->refundReturn($pharmacy, $record);
        $this->assertSame(1, DB::table('stock_movements')->where('type', 'SALE_RETURN')->count());
        $restored = DB::table('stock_movements')->where('type', 'SALE_RETURN')->first();
        $this->assertSame($movement->source_movement_id, $restored->source_movement_id);
        $this->assertSame('EARLY', $restored->batch_number);
        $this->assertEquals(2, $restored->quantity);
        $this->assertSame(80000, app(StockLots::class)->available($pharmacy, [$product->id])[$product->id]);
        $this->assertSame(1, DB::table('audit_entries')->where('action', 'sale_return.refunded')->count());
        $this->get(route('returns.receipt', [$pharmacy, $record->id]))->assertOk()->assertSee('BUKTI REFUND')->assertSee('2.500,00');
        $this->get(route('sales.show', [$pharmacy, $sale->id]))->assertOk()->assertSee('Ajukan retur');
        $this->get(route('inventory.ledger', [$pharmacy, $product, 'from' => '2026-11-01', 'to' => '2026-11-08']))->assertOk()->assertSee('Retur penjualan')->assertSee($record->number);
        $new = $this->draft($pharmacy, $product, '2');
        $this->post(route('sales.pay', [$pharmacy, $new->id]), $this->payment($new))->assertSessionHasNoErrors();
        $this->assertEquals(-2, DB::table('stock_movements')->whereIn('sale_item_id', DB::table('sale_items')->where('sale_id', $new->id)->pluck('id'))->where('batch_number', 'EARLY')->sum('quantity'));
        $this->assertSame('PAID', DB::table('sales')->find($sale->id)->status);
    }

    public function test_full_refund_across_partial_returns_is_exact_and_quarantine_never_increases_stock(): void
    {
        [, $pharmacy,$product] = $this->workspace();
        $sale = $this->paidSale($pharmacy, $product);
        $movements = SaleReturns::allocations($pharmacy, $sale->id)->keyBy('batch_number');
        $first = $this->requestReturn($pharmacy, $sale, [['sale_movement_id' => $movements['EARLY']->id, 'quantity' => '1.0001', 'disposition' => 'QUARANTINE']]);
        $this->approveReturn($pharmacy, $first);
        $this->refundReturn($pharmacy, $first);
        $second = $this->requestReturn($pharmacy, $sale, [['sale_movement_id' => $movements['EARLY']->id, 'quantity' => '1.9999', 'disposition' => 'QUARANTINE'], ['sale_movement_id' => $movements['LATER']->id, 'quantity' => '2', 'disposition' => 'QUARANTINE']]);
        $this->approveReturn($pharmacy, $second);
        $this->refundReturn($pharmacy, $second);
        $this->assertEquals($sale->total, DB::table('sale_returns')->where('status', 'COMPLETED')->sum('refund_amount'));
        $this->assertSame(0, DB::table('stock_movements')->where('type', 'SALE_RETURN')->count());
        $this->assertSame(60000, app(StockLots::class)->available($pharmacy, [$product->id])[$product->id]);
        $this->post(route('returns.store', [$pharmacy, $sale->id]), ['request_key' => (string) Str::uuid(), 'reason' => 'Coba lagi', 'condition_checked' => 1, 'items' => [['sale_movement_id' => $movements['EARLY']->id, 'quantity' => 1, 'disposition' => 'QUARANTINE']]])->assertSessionHasErrors('return');
    }

    public function test_approval_rechecks_claims_cancel_releases_them_and_stale_actions_fail(): void
    {
        [, $pharmacy,$product] = $this->workspace();
        $sale = $this->paidSale($pharmacy, $product);
        $movement = SaleReturns::allocations($pharmacy, $sale->id)->first();
        $rows = [['sale_movement_id' => $movement->id, 'quantity' => (string) abs($movement->quantity), 'disposition' => 'QUARANTINE']];
        $first = $this->requestReturn($pharmacy, $sale, $rows);
        $second = $this->requestReturn($pharmacy, $sale, $rows);
        $this->post(route('returns.approve', [$pharmacy, $first->id]), ['revision' => 2])->assertSessionHasErrors('return');
        $this->approveReturn($pharmacy, $first);
        $this->post(route('returns.approve', [$pharmacy, $second->id]), ['revision' => 1])->assertSessionHasErrors('return');
        $this->post(route('returns.refund', [$pharmacy, $second->id]), ['revision' => 1, 'refund_method' => 'cash', 'refund_confirmed' => 1])->assertSessionHasErrors('return');
        $this->post(route('returns.cancel', [$pharmacy, $first->id]), ['revision' => 1, 'reason' => 'Revisi'])->assertSessionHasErrors('return');
        $this->post(route('returns.cancel', [$pharmacy, $first->id]), ['revision' => 2, 'reason' => 'Revisi'])->assertSessionHasNoErrors();
        $this->approveReturn($pharmacy, $second);
        $this->refundReturn($pharmacy, $second);
        $this->post(route('returns.cancel', [$pharmacy, $second->id]), ['revision' => 3, 'reason' => 'Terlambat'])->assertSessionHasErrors('return');
        $this->assertSame(0, DB::table('stock_movements')->where('type', 'SALE_RETURN')->count());
    }

    public function test_invalid_scope_duplicate_and_excess_quantities_and_unpaid_or_prescription_sales_are_rejected(): void
    {
        [, $pharmacy,$product] = $this->workspace();
        $sale = $this->paidSale($pharmacy, $product);
        $movement = SaleReturns::allocations($pharmacy, $sale->id)->first();
        $data = ['request_key' => (string) Str::uuid(), 'reason' => 'Uji', 'condition_checked' => 1, 'items' => [['sale_movement_id' => (string) Str::uuid(), 'quantity' => 1, 'disposition' => 'QUARANTINE']]];
        $url = route('returns.store', [$pharmacy, $sale->id]);
        $this->post($url, $data)->assertSessionHasErrors('return');
        $data['items'][0]['sale_movement_id'] = $movement->id;
        $data['items'][0]['quantity'] = 100;
        $this->post($url, $data)->assertSessionHasErrors('return');
        $data['items'][0]['quantity'] = 0;
        $this->post($url, $data)->assertSessionHasErrors('return');
        $data['items'][0]['quantity'] = '0.00001';
        $this->post($url, $data)->assertSessionHasErrors('items.0.quantity');
        $data['items'][0]['quantity'] = 1;
        $data['items'][] = $data['items'][0];
        $this->post($url, $data)->assertSessionHasErrors('items.0.sale_movement_id');
        array_pop($data['items']);
        $this->post($url, $data)->assertSessionHasNoErrors();
        $this->post($url, $data)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('sale_returns', 1);
        $draft = $this->draft($pharmacy, $product, '1');
        $this->get(route('returns.create', [$pharmacy, $draft->id]))->assertStatus(422);
        DB::table('sales')->where('id', $sale->id)->update(['kind' => 'PRESCRIPTION']);
        $this->get(route('returns.create', [$pharmacy, $sale->id]))->assertStatus(422);
    }

    public function test_expiry_and_stocktake_at_refund_roll_back_then_quarantine_is_available(): void
    {
        [, $pharmacy,$product] = $this->workspace();
        $sale = $this->paidSale($pharmacy, $product);
        $movement = SaleReturns::allocations($pharmacy, $sale->id)->first();
        $record = $this->requestReturn($pharmacy, $sale, [['sale_movement_id' => $movement->id, 'quantity' => 1, 'disposition' => 'RESTOCK']]);
        $this->approveReturn($pharmacy, $record);
        DB::table('stock_movements')->where('id', $movement->id)->update(['expires_on' => now()->toDateString()]);
        $data = ['revision' => 2, 'refund_method' => 'cash', 'refund_confirmed' => 1];
        $this->post(route('returns.refund', [$pharmacy, $record->id]), $data)->assertSessionHasErrors('return');
        $this->assertDatabaseHas('sale_returns', ['id' => $record->id, 'status' => 'APPROVED', 'refunded_at' => null]);
        $this->assertSame(0, DB::table('stock_movements')->where('type', 'SALE_RETURN')->count());
        $this->post(route('returns.cancel', [$pharmacy, $record->id]), ['revision' => 2, 'reason' => 'Karantina'])->assertSessionHasNoErrors();
        $replacement = $this->requestReturn($pharmacy, $sale, [['sale_movement_id' => $movement->id, 'quantity' => 1, 'disposition' => 'QUARANTINE']]);
        $this->approveReturn($pharmacy, $replacement);
        $this->post(route('returns.refund', [$pharmacy, $replacement->id]), ['revision' => 2, 'refund_method' => 'transfer', 'refund_confirmed' => 1])->assertSessionHasErrors('refund_reference');
        $this->post(route('returns.refund', [$pharmacy, $replacement->id]), ['revision' => 2, 'refund_method' => 'transfer', 'refund_reference' => 'REFUND-001', 'refund_confirmed' => 1])->assertSessionHasNoErrors();
    }

    public function test_tenant_and_read_only_permissions_cannot_mutate_refunds(): void
    {
        [$owner,$pharmacy,$product] = $this->workspace();
        $sale = $this->paidSale($pharmacy, $product);
        $movement = SaleReturns::allocations($pharmacy, $sale->id)->first();
        $record = $this->requestReturn($pharmacy, $sale, [['sale_movement_id' => $movement->id, 'quantity' => 1, 'disposition' => 'QUARANTINE']]);
        $foreign = app(AccessManager::class)->provision($owner->id, ['name' => 'Apotek lain']);
        $foreign->forceFill(['status' => 'ACTIVE'])->save();
        $this->get(route('returns.show', [$foreign, $record->id]))->assertNotFound();
        $this->get(route('returns.create', [$foreign, $sale->id]))->assertNotFound();
        $staff = User::factory()->create();
        $role = $pharmacy->roles()->create(['name' => 'Baca retur', 'permissions' => ['returns.view'], 'is_owner' => false]);
        $member = $pharmacy->memberships()->create(['user_id' => $staff->id, 'active' => true]);
        app(AccessManager::class)->syncRoles($member, collect([$role]));
        $this->actingAs($staff)->get(route('returns.show', [$pharmacy, $record->id]))->assertOk()->assertDontSee('data-confirm-title="Setujui retur"', false);
        $this->get(route('returns.select', $pharmacy))->assertForbidden();
        $this->post(route('returns.approve', [$pharmacy, $record->id]), ['revision' => 1])->assertForbidden();
        $this->post(route('returns.refund', [$pharmacy, $record->id]), ['revision' => 1, 'refund_method' => 'cash', 'refund_confirmed' => 1])->assertForbidden();
        $this->post(route('returns.cancel', [$pharmacy, $record->id]), ['revision' => 1, 'reason' => 'Coba'])->assertForbidden();
        $this->get(route('returns.receipt', [$pharmacy, $record->id]))->assertForbidden();
    }

    public function test_proportion_handles_large_amounts_without_overflow_and_rounds_cents(): void
    {
        $this->assertSame(500000000000000, SaleReturns::proportion(1000000000000000, 500000000000, 1000000000000));
        $this->assertSame(1, SaleReturns::proportion(1, 1, 2));
        $this->assertSame(333, SaleReturns::proportion(1000, 1, 3));
    }

    public function test_original_discount_tax_and_multiple_product_rounding_are_preserved(): void
    {
        [, $pharmacy,$product] = $this->workspace();
        $other = $pharmacy->products()->create(['sku' => 'OTHER', 'name' => 'Produk Kedua', 'brand' => 'Brand', 'category' => 'Obat', 'base_unit' => 'Tablet', 'purchase_unit' => 'Tablet', 'purchase_conversion' => 1, 'selling_price' => '99.99', 'minimum_stock' => 0, 'requires_prescription' => false, 'active' => true]);
        DB::table('stock_movements')->insert(['id' => (string) Str::uuid(), 'pharmacy_id' => $pharmacy->id, 'product_id' => $other->id, 'type' => 'STOCKTAKE_IN', 'quantity' => 2, 'base_unit' => 'Tablet', 'occurred_on' => now()->toDateString(), 'created_at' => now()]);
        foreach (['discount' => '10', 'tax' => '11'] as $kind => $value) {
            $this->post(route('pricing.store', [$pharmacy, $kind]), ['code' => $kind, 'name' => $kind, 'type' => 'percentage', 'value' => $value, 'tax_mode' => $kind === 'tax' ? 'exclusive' : null, 'is_default' => 0])->assertSessionHasNoErrors();
        }
        $rules = DB::table('pos_pricing_rules')->pluck('id', 'kind');
        $response = $this->post(route('sales.store', $pharmacy), ['request_key' => (string) Str::uuid(), 'discount_rule_id' => $rules['discount'], 'tax_rule_id' => $rules['tax'], 'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_type' => 'base'], ['product_id' => $other->id, 'quantity' => 1, 'unit_type' => 'base']]])->assertSessionHasNoErrors();
        $sale = DB::table('sales')->find(basename(parse_url($response->headers->get('Location'), PHP_URL_PATH)));
        $this->post(route('sales.pay', [$pharmacy, $sale->id]), $this->payment($sale))->assertSessionHasNoErrors();
        DB::table('pos_pricing_rules')->update(['active' => false, 'value' => 50]);
        $product->update(['selling_price' => 9999]);
        $rows = SaleReturns::allocations($pharmacy, $sale->id)->map(fn ($row) => ['sale_movement_id' => $row->id, 'quantity' => (string) abs($row->quantity), 'disposition' => 'QUARANTINE'])->all();
        $record = $this->requestReturn($pharmacy, $sale, $rows);
        $this->assertEquals($sale->total, $record->refund_amount);
        $this->assertEquals($sale->total, DB::table('sale_return_items')->where('sale_return_id', $record->id)->sum('refund_amount'));
        $this->approveReturn($pharmacy, $record);
        $this->refundReturn($pharmacy, $record);
    }

    public function test_active_stocktake_blocks_restock_refund_atomically(): void
    {
        [, $pharmacy,$product] = $this->workspace();
        $sale = $this->paidSale($pharmacy, $product);
        $movement = SaleReturns::allocations($pharmacy, $sale->id)->first();
        $record = $this->requestReturn($pharmacy, $sale, [['sale_movement_id' => $movement->id, 'quantity' => 1, 'disposition' => 'RESTOCK']]);
        $this->approveReturn($pharmacy, $record);
        $this->post(route('stocktakes.store', $pharmacy), ['request_key' => (string) Str::uuid(), 'product_id' => $product->id])->assertSessionHasNoErrors();
        $this->post(route('returns.refund', [$pharmacy, $record->id]), ['revision' => 2, 'refund_method' => 'cash', 'refund_confirmed' => 1])->assertSessionHasErrors('stocktake');
        $this->assertDatabaseHas('sale_returns', ['id' => $record->id, 'status' => 'APPROVED', 'refunded_at' => null]);
        $this->assertSame(0, DB::table('stock_movements')->where('type', 'SALE_RETURN')->count());
        $session = DB::table('stocktakes')->first();
        $this->post(route('stocktakes.cancel', [$pharmacy, $session->id]), ['revision' => 1, 'reason' => 'Selesai pemeriksaan'])->assertSessionHasNoErrors();
        $this->refundReturn($pharmacy, $record);
    }
}
