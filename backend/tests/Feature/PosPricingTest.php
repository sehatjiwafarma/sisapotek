<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AccessManager;
use App\Services\PosPricing;
use App\Services\Procurement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PosPricingTest extends TestCase
{
    use RefreshDatabase;

    private function workspace(): array
    {
        $this->travelTo(now()->setDate(2026, 11, 8)->setTime(12, 0));
        $owner = User::factory()->create();
        $pharmacy = app(AccessManager::class)->provision($owner->id, ['name' => 'Apotek Harga']);
        $pharmacy->forceFill(['status' => 'ACTIVE'])->save();
        $product = $pharmacy->products()->create(['sku' => 'HARGA', 'name' => 'Produk Harga', 'brand' => 'Brand', 'category' => 'Obat', 'base_unit' => 'Tablet', 'purchase_unit' => 'Tablet', 'purchase_conversion' => 1, 'selling_price' => 1000, 'minimum_stock' => 0, 'active' => true, 'requires_prescription' => false]);
        $supplier = $pharmacy->suppliers()->create(['code' => 'SUP', 'name' => 'Supplier', 'active' => true]);
        $procurement = app(Procurement::class);
        $order = $procurement->saveOrder($pharmacy, $owner->id, ['supplier_id' => $supplier->id, 'ordered_on' => '2026-11-01', 'payment_type' => 'cash', 'payment_days' => 0,
            'items' => [['product_id' => $product->id, 'unit_type' => 'base', 'quantity' => 100, 'unit_price' => 500]]]);
        $procurement->finalize($pharmacy, $owner->id, $order->id);
        $receipt = $procurement->receive($pharmacy, $owner->id, $order->id, ['received_on' => '2026-11-01', 'items' => [['order_item_id' => $order->items()->first()->id, 'quantity' => 100, 'unit_price' => 500, 'batch_number' => 'BATCH', 'expires_on' => '2030-01-01']]]);
        $procurement->post($pharmacy, $owner->id, $receipt->id);
        $this->actingAs($owner);

        return [$owner, $pharmacy, $product];
    }

    private function rule($pharmacy, string $kind, string $code, string $value, array $extra = []): object
    {
        $this->post(route('pricing.store', [$pharmacy, $kind]), $extra + ['code' => $code, 'name' => 'Aturan '.$code, 'type' => 'percentage', 'value' => $value, 'tax_mode' => $kind === 'tax' ? 'exclusive' : null, 'is_default' => 0])->assertSessionHasNoErrors();

        return DB::table('pos_pricing_rules')->where('pharmacy_id', $pharmacy->id)->where('code', $code)->first();
    }

    private function draft($pharmacy, $product, $discount, $tax): object
    {
        $response = $this->post(route('sales.store', $pharmacy), ['request_key' => (string) Str::uuid(), 'discount_rule_id' => $discount?->id, 'tax_rule_id' => $tax?->id,
            'discount' => 900, 'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_type' => 'base']]])->assertSessionHasNoErrors();

        return DB::table('sales')->find(basename(parse_url($response->headers->get('Location'), PHP_URL_PATH)));
    }

    public function test_master_crud_default_status_validation_and_audit(): void
    {
        [, $pharmacy] = $this->workspace();
        $first = $this->rule($pharmacy, 'discount', 'DISC-A', '10', ['is_default' => 1]);
        $second = $this->rule($pharmacy, 'discount', 'DISC-B', '15', ['is_default' => 1]);
        $this->assertEquals(0, DB::table('pos_pricing_rules')->find($first->id)->is_default);
        $this->assertEquals(1, DB::table('pos_pricing_rules')->find($second->id)->is_default);
        $this->get(route('pricing.index', [$pharmacy, 'discount', 'sort' => 'value', 'direction' => 'desc']))->assertOk()->assertSee('DISC-B');
        $this->get(route('pricing.create', [$pharmacy, 'tax']))->assertOk();
        $this->get(route('pricing.edit', [$pharmacy, 'discount', $first->id]))->assertOk();
        $data = ['code' => 'DISC-A', 'name' => 'Nama baru', 'type' => 'nominal', 'value' => '1500', 'is_default' => 0];
        $this->put(route('pricing.update', [$pharmacy, 'discount', $first->id]), $data)->assertSessionHasNoErrors();
        $this->post(route('pricing.store', [$pharmacy, 'discount']), $data)->assertSessionHasErrors('code');
        $this->post(route('pricing.store', [$pharmacy, 'tax']), ['code' => 'INVALID', 'name' => 'PPN', 'type' => 'percentage', 'value' => '100.01', 'tax_mode' => 'exclusive', 'is_default' => 0])->assertSessionHasErrors('value');
        $this->patch(route('pricing.status', [$pharmacy, 'discount', $second->id]), ['active' => 0])->assertSessionHasNoErrors();
        $this->assertEquals(0, DB::table('pos_pricing_rules')->find($second->id)->is_default);
        $this->assertDatabaseHas('audit_entries', ['action' => 'pricing.updated']);
        $this->assertDatabaseHas('audit_entries', ['action' => 'pricing.status_changed']);
    }

    public function test_exclusive_tax_after_discount_payment_and_historical_receipt(): void
    {
        [, $pharmacy,$product] = $this->workspace();
        $discount = $this->rule($pharmacy, 'discount', 'DISC', '10');
        $tax = $this->rule($pharmacy, 'tax', 'VAT', '11');
        $sale = $this->draft($pharmacy, $product, $discount, $tax);
        $this->assertEquals(100, $sale->discount);
        $this->assertEquals(900, $sale->taxable_base);
        $this->assertEquals(99, $sale->tax_amount);
        $this->assertEquals(999, $sale->total);
        $this->get(route('sales.edit', [$pharmacy, $sale->id]))->assertOk()->assertSee('Aturan VAT');
        $this->post(route('sales.pay', [$pharmacy, $sale->id]), ['revision' => 1, 'payment_method' => 'cash', 'amount_received' => 1000])->assertSessionHasNoErrors();
        $this->put(route('pricing.update', [$pharmacy, 'tax', $tax->id]), ['code' => 'VAT', 'name' => 'Tarif baru', 'type' => 'percentage', 'value' => 12, 'tax_mode' => 'inclusive', 'is_default' => 0])->assertSessionHasNoErrors();
        $this->get(route('sales.receipt', [$pharmacy, $sale->id]))->assertOk()->assertSee('Aturan VAT')->assertSee('11,00%')->assertDontSee('Tarif baru');
        $this->get(route('sales.show', [$pharmacy, $sale->id]))->assertOk()->assertSee('Rincian diskon');
        $this->assertEquals(1, DB::table('sales')->find($sale->id)->change_amount);
    }

    public function test_inclusive_tax_nominal_discount_and_exact_rounding(): void
    {
        [, $pharmacy] = $this->workspace();
        $discount = $this->rule($pharmacy, 'discount', 'NOMINAL', '100', ['type' => 'nominal']);
        $tax = $this->rule($pharmacy, 'tax', 'INCLUSIVE', '11', ['tax_mode' => 'inclusive']);
        $pricing = app(PosPricing::class);
        $result = $pricing->calculate($pharmacy, 100000, ['discount_rule_id' => $discount->id, 'tax_rule_id' => $tax->id]);
        $this->assertSame('900.00', $result['total']);
        $this->assertSame('89.19', $result['tax_amount']);
        $this->assertSame('810.81', $result['taxable_base']);
        $percent = $this->rule($pharmacy, 'discount', 'ROUND', '10');
        $result = $pricing->calculate($pharmacy, 15, ['discount_rule_id' => $percent->id, 'tax_rule_id' => $tax->id]);
        $this->assertSame('0.02', $result['discount']);
        $this->assertSame('0.01', $result['tax_amount']);
        $this->assertSame('0.13', $result['total']);
        $hundred = $this->rule($pharmacy, 'tax', 'MAX', '100');
        $result = $pricing->calculate($pharmacy, 1000000000000000, ['tax_rule_id' => $hundred->id]);
        $this->assertSame('20000000000000.00', $result['total']);
    }

    public function test_changed_or_inactive_master_requires_draft_refresh(): void
    {
        [, $pharmacy,$product] = $this->workspace();
        $discount = $this->rule($pharmacy, 'discount', 'DISC', '10');
        $tax = $this->rule($pharmacy, 'tax', 'PPN', '11');
        $sale = $this->draft($pharmacy, $product, $discount, $tax);
        $this->put(route('pricing.update', [$pharmacy, 'tax', $tax->id]), ['code' => 'PPN', 'name' => 'Aturan PPN', 'type' => 'percentage', 'value' => 12, 'tax_mode' => 'exclusive', 'is_default' => 0])->assertSessionHasNoErrors();
        $payment = ['revision' => 1, 'payment_method' => 'cash', 'amount_received' => 2000];
        $this->post(route('sales.pay', [$pharmacy, $sale->id]), $payment)->assertSessionHasErrors('items');
        $this->assertEquals(0, DB::table('stock_movements')->where('type', 'SALE')->count());
        $this->put(route('sales.update', [$pharmacy, $sale->id]), ['revision' => 1, 'discount_rule_id' => $discount->id, 'tax_rule_id' => $tax->id, 'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_type' => 'base']]])->assertSessionHasNoErrors();
        $this->assertEquals(1008, DB::table('sales')->find($sale->id)->total);
        $this->patch(route('pricing.status', [$pharmacy, 'discount', $discount->id]), ['active' => 0])->assertSessionHasNoErrors();
        $payment['revision'] = 2;
        $this->post(route('sales.pay', [$pharmacy, $sale->id]), $payment)->assertSessionHasErrors('discount_rule_id');
    }

    public function test_tenant_permissions_rule_kinds_and_validity_are_enforced(): void
    {
        [$owner,$pharmacy,$product] = $this->workspace();
        $discount = $this->rule($pharmacy, 'discount', 'DISC', '10');
        $future = $this->rule($pharmacy, 'tax', 'FUTURE', '11', ['starts_on' => '2026-11-09']);
        $other = app(AccessManager::class)->provision($owner->id, ['name' => 'Apotek Lain']);
        $this->get(route('pricing.edit', [$other, 'discount', $discount->id]))->assertNotFound();
        $this->get(route('pricing.edit', [$pharmacy, 'tax', $discount->id]))->assertNotFound();
        $payload = ['request_key' => (string) Str::uuid(), 'tax_rule_id' => $future->id, 'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_type' => 'base']]];
        $this->post(route('sales.store', $pharmacy), $payload)->assertSessionHasErrors('tax_rule_id');
        $foreign = $this->rule($other, 'tax', 'FOREIGN', '11');
        $payload['tax_rule_id'] = $foreign->id;
        $this->post(route('sales.store', $pharmacy), $payload)->assertSessionHasErrors('tax_rule_id');
        $viewer = User::factory()->create();
        $member = $pharmacy->memberships()->create(['user_id' => $viewer->id, 'active' => true]);
        $role = $pharmacy->roles()->create(['name' => 'Kasir', 'permissions' => ['sales.view', 'sales.create', 'sales.pay']]);
        app(AccessManager::class)->syncRoles($member,collect([$role]));
        $this->actingAs($viewer)->get(route('pricing.index',[$pharmacy, 'discount']))->assertForbidden();
        $this->get(route('sales.create',$pharmacy))->assertOk();
        $payload['tax_rule_id'] = null;
        $payload['discount_rule_id'] = $discount->id;
        $this->post(route('sales.store',$pharmacy),$payload)->assertForbidden();
        $this->post(route('pricing.store',[$pharmacy, 'discount']),[])->assertForbidden();
        $this->assertDatabaseCount('sales',0);
    }
}
