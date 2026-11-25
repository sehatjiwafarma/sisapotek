<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AccessManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PointOfSaleTest extends TestCase
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

    public function test_custom_unit_price_and_stock_conversion_are_independent(): void
    {
        [, $pharmacy, $product] = $this->workspace();
        $product->update(['selling_units' => [['unit' => 'Strip', 'conversion' => 5, 'price' => 4000]]]);
        $sale = $this->draft($pharmacy, $product, '2', 's0');
        $this->assertEquals(8000, $sale->total);
        $item = DB::table('sale_items')->where('sale_id', $sale->id)->first();
        $this->assertSame('Strip', $item->unit);
        $this->assertEquals(10, $item->base_quantity);
        $payload = $product->only(['sku', 'brand', 'category', 'base_unit', 'purchase_unit', 'purchase_conversion', 'selling_price', 'minimum_stock']);
        $payload['requires_prescription'] = 0;
        $payload['selling_units'] = [['unit' => 'Strip', 'conversion' => 6, 'price' => 4000]];
        $this->put(route('products.update', [$pharmacy, $product]), $payload)->assertSessionHasErrors('selling_units');
        $this->assertEquals(5, $product->fresh()->selling_units[0]['conversion']);
        $this->getJson(route('sales.catalog', $pharmacy))->assertOk()->assertSee('selling_units');
        $this->get(route('sales.edit', [$pharmacy, $sale->id]))->assertOk()->assertSee('Strip');
        $this->post(route('sales.pay', [$pharmacy, $sale->id]), $this->payment($sale, 'cash', '8000'))->assertSessionHasNoErrors();
        $this->assertEquals(-10, DB::table('stock_movements')->where('type', 'SALE')->sum('quantity'));
        $product->update(['selling_units' => [['unit' => 'Strip', 'conversion' => 5, 'price' => 4500]]]);
        $this->assertEquals(4000, DB::table('sale_items')->where('id', $item->id)->value('unit_price'));
    }

    public function test_missing_custom_unit_and_changed_draft_price_are_rejected(): void
    {
        [, $pharmacy, $product] = $this->workspace();
        $this->post(route('sales.store', $pharmacy), ['request_key' => (string) Str::uuid(), 'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_type' => 's9']]])->assertSessionHasErrors('items');
        $product->update(['selling_units' => [['unit' => 'Strip', 'conversion' => 5, 'price' => 4000]]]);
        $sale = $this->draft($pharmacy, $product, '1', 's0');
        $product->update(['selling_units' => [['unit' => 'Strip', 'conversion' => 5, 'price' => 4500]]]);
        $this->post(route('sales.pay', [$pharmacy, $sale->id]), $this->payment($sale, 'cash', '4000'))->assertSessionHasErrors('items');
        $this->assertDatabaseHas('sales', ['id' => $sale->id, 'status' => 'DRAFT']);
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

    public function test_general_sale_fefo_payment_receipt_and_stock_card_are_integrated(): void
    {
        [, $pharmacy,$product] = $this->workspace();
        $this->get(route('sales.create', $pharmacy))->assertOk()->assertSee('Keranjang penjualan');
        $this->getJson(route('sales.catalog', [$pharmacy, 'q' => '123456789']))->assertOk()->assertJsonPath('data.0.id', $product->id)->assertJsonPath('total', 1);
        $sale = $this->draft($pharmacy, $product, '5', 'base', '1.25');
        $this->assertSame(3, DB::table('stock_movements')->count());
        $this->assertSame('6250.00', number_format($sale->total, 2, '.', ''));
        $this->get(route('sales.show', [$pharmacy, $sale->id]))->assertOk();
        $this->get(route('sales.receipt', [$pharmacy, $sale->id]))->assertStatus(409);
        $this->post(route('sales.pay', [$pharmacy, $sale->id]), $this->payment($sale, 'cash', '10000'))->assertSessionHasNoErrors();
        $paid = DB::table('sales')->find($sale->id);
        $this->assertSame('PAID', $paid->status);
        $this->assertEquals(3750, $paid->change_amount);
        $this->assertEquals(-3, DB::table('stock_movements')->where('type', 'SALE')->where('batch_number', 'EARLY')->sum('quantity'));
        $this->assertEquals(-2, DB::table('stock_movements')->where('type', 'SALE')->where('batch_number', 'LATER')->sum('quantity'));
        $this->assertFalse(DB::table('stock_movements')->where('type', 'SALE')->where('batch_number', 'EXPIRED')->exists());
        $this->post(route('sales.pay', [$pharmacy, $sale->id]), $this->payment($sale))->assertSessionHasNoErrors();
        $this->assertSame(2, DB::table('stock_movements')->where('type', 'SALE')->count());
        $this->assertSame(1, DB::table('audit_entries')->where('action', 'sale.paid')->count());
        $this->get(route('sales.receipt', [$pharmacy, $sale->id]))->assertOk()->assertSee('LUNAS')->assertSee('Produk Umum');
        $this->get(route('sales.index', [$pharmacy, 'status' => 'PAID', 'sort' => 'total', 'direction' => 'desc']))->assertOk()->assertSee($sale->number);
        $this->get(route('inventory.ledger', [$pharmacy, $product, 'from' => '2026-10-01', 'to' => '2026-11-08']))->assertOk()->assertSee('Penjualan umum')->assertSee(route('sales.show', [$pharmacy, $sale->id]), false)
            ->assertViewHas('closing', fn ($value) => (float) $value === 16.0);
    }

    public function test_insufficient_stock_rolls_back_and_second_checkout_cannot_oversell(): void
    {
        [, $pharmacy,$product] = $this->workspace();
        $sale = $this->draft($pharmacy, $product, '12');
        $this->post(route('sales.pay', [$pharmacy, $sale->id]), $this->payment($sale))->assertSessionHasErrors('items');
        $this->assertSame(0, DB::table('stock_movements')->where('type', 'SALE')->count());
        $this->assertDatabaseHas('sales', ['id' => $sale->id, 'status' => 'DRAFT', 'paid_at' => null]);
        $sale1 = $this->draft($pharmacy, $product, '11');
        $this->travel(1)->seconds();
        $sale2 = $this->draft($pharmacy, $product, '1');
        $this->post(route('sales.pay', [$pharmacy, $sale1->id]), $this->payment($sale1))->assertSessionHasNoErrors();
        $this->post(route('sales.pay', [$pharmacy, $sale2->id]), $this->payment($sale2))->assertSessionHasErrors('items');
        $this->assertEquals(-11, DB::table('stock_movements')->where('type', 'SALE')->sum('quantity'));
    }

    public function test_server_prices_conversion_stale_revision_and_validation(): void
    {
        [, $pharmacy,$product] = $this->workspace();
        $sale = $this->draft($pharmacy, $product, '0.5', 'purchase');
        $this->get(route('sales.edit', [$pharmacy, $sale->id]))->assertOk()->assertViewHas('cart', fn ($cart) => $cart->count() === 1 && $cart->first()['product']->id === $product->id);
        $line = DB::table('sale_items')->where('sale_id', $sale->id)->first();
        $this->assertEquals(5, $line->base_quantity);
        $this->assertEquals(12502.5, $line->unit_price);
        $this->assertEquals(6251.25, $sale->total);
        $this->post(route('sales.pay', [$pharmacy, $sale->id]), $this->payment($sale, 'transfer', '7000'))->assertSessionHasErrors('amount_received');
        $this->post(route('sales.pay', [$pharmacy, $sale->id]), $this->payment($sale, 'cash', '1'))->assertSessionHasErrors('amount_received');
        $product->update(['selling_price' => 1300]);
        $this->post(route('sales.pay', [$pharmacy, $sale->id]), $this->payment($sale))->assertSessionHasErrors('items');
        $this->put(route('sales.update', [$pharmacy, $sale->id]), ['revision' => $sale->revision, 'items' => [['product_id' => $product->id, 'unit_type' => 'base', 'quantity' => 1]]])->assertSessionHasNoErrors();
        $this->post(route('sales.pay', [$pharmacy, $sale->id]), $this->payment($sale))->assertSessionHasErrors('items');
        $fresh = DB::table('sales')->find($sale->id);
        $this->post(route('sales.pay', [$pharmacy, $sale->id]), $this->payment($fresh, 'transfer'))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('sales', ['id' => $sale->id, 'payment_method' => 'transfer', 'change_amount' => 0]);
    }

    public function test_prescription_inactive_and_foreign_products_are_rejected(): void
    {
        [$owner,$pharmacy,$product] = $this->workspace();
        $sale = $this->draft($pharmacy, $product);
        $product->update(['requires_prescription' => true]);
        $this->getJson(route('sales.catalog', $pharmacy))->assertJsonPath('total', 0);
        $this->post(route('sales.pay', [$pharmacy, $sale->id]), $this->payment($sale))->assertSessionHasErrors('items');
        $this->post(route('sales.store', $pharmacy), ['request_key' => (string) Str::uuid(), 'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_type' => 'base']]])->assertSessionHasErrors('items');
        $product->update(['requires_prescription' => false, 'active' => false]);
        $this->post(route('sales.pay', [$pharmacy, $sale->id]), $this->payment($sale))->assertSessionHasErrors('items');
        $other = app(AccessManager::class)->provision($owner->id, ['name' => 'Apotek Lain']);
        $other->forceFill(['status' => 'ACTIVE'])->save();
        $this->get(route('sales.show', [$other, $sale->id]))->assertNotFound();
        $this->get(route('sales.receipt', [$other, $sale->id]))->assertNotFound();
        $this->post(route('sales.pay', [$other, $sale->id]), $this->payment($sale))->assertNotFound();
        $this->post(route('sales.store', $other), ['request_key' => (string) Str::uuid(), 'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_type' => 'base']]])->assertSessionHasErrors('items');
        $this->assertSame(0, DB::table('stock_movements')->where('type', 'SALE')->count());
    }

    public function test_permissions_discount_and_draft_cancellation(): void
    {
        [$owner,$pharmacy,$product] = $this->workspace();
        $sale = $this->draft($pharmacy, $product, '1', 'base', '1');
        $viewer = User::factory()->create();
        $member = $pharmacy->memberships()->create(['user_id' => $viewer->id, 'active' => true]);
        $role = $pharmacy->roles()->create(['name' => 'Kasir terbatas', 'permissions' => ['sales.view', 'sales.create', 'sales.pay']]);
        app(AccessManager::class)->syncRoles($member, collect([$role]));
        $this->actingAs($viewer)->get(route('sales.index', $pharmacy))->assertOk();
        $this->get(route('sales.receipt', [$pharmacy, $sale->id]))->assertForbidden();
        $this->post(route('sales.pay', [$pharmacy, $sale->id]), $this->payment($sale))->assertForbidden();
        $this->post(route('sales.store', $pharmacy), ['request_key' => (string) Str::uuid(), 'discount' => 1, 'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_type' => 'base']]])->assertForbidden();
        $this->post(route('sales.cancel', [$pharmacy, $sale->id]), ['reason' => 'Salah input'])->assertForbidden();
        $this->actingAs($owner)->post(route('sales.cancel', [$pharmacy, $sale->id]), ['reason' => 'Pelanggan batal'])->assertSessionHasNoErrors();
        $this->post(route('sales.pay', [$pharmacy, $sale->id]), $this->payment($sale))->assertSessionHasErrors('items');
        $this->get(route('sales.edit', [$pharmacy, $sale->id]))->assertStatus(409);
        $this->assertDatabaseHas('sales', ['id' => $sale->id, 'status' => 'CANCELLED']);
        $pharmacy->forceFill(['status' => 'INACTIVE'])->save();
        $this->get(route('sales.create', $pharmacy))->assertStatus(422);
    }

    public function test_replayed_creation_and_invalid_cart_do_not_duplicate_transactions(): void
    {
        [, $pharmacy,$product] = $this->workspace();
        $data = ['request_key' => (string) Str::uuid(), 'items' => [['product_id' => $product->id, 'quantity' => '1.001', 'unit_type' => 'base']]];
        $this->post(route('sales.store', $pharmacy), $data)->assertSessionHasNoErrors();
        $this->post(route('sales.store', $pharmacy), $data)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('sales', 1);
        foreach (['0', '-1', '0.0001', '10001'] as $quantity) {
            $invalid = $data;
            $invalid['request_key'] = (string) Str::uuid();
            $invalid['items'][0]['quantity'] = $quantity;
            $this->post(route('sales.store', $pharmacy), $invalid)->assertSessionHasErrors();
        }
        $invalid = $data;
        $invalid['items'][] = $invalid['items'][0];
        $this->post(route('sales.store', $pharmacy), $invalid)->assertSessionHasErrors();
        $invalid = $data;
        $invalid['request_key'] = (string) Str::uuid();
        $invalid['discount'] = 999999;
        $this->post(route('sales.store', $pharmacy), $invalid)->assertSessionHasErrors('discount');
        $invalid = $data;
        $invalid['items'][0]['unit_price'] = 1;
        $this->post(route('sales.store', $pharmacy), $invalid)->assertSessionHasErrors();
        $this->assertDatabaseCount('sales', 1);
    }
}
