<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AccessManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InventoryTest extends TestCase
{
    use RefreshDatabase;

    private function workspace(): array
    {
        $this->travelTo(now()->setDate(2026, 11, 8)->setTime(12, 0));
        $owner = User::factory()->create();
        $pharmacy = app(AccessManager::class)->provision($owner->id, ['name' => 'Apotek Persediaan']);
        $pharmacy->forceFill(['status' => 'ACTIVE'])->save();
        $supplier = $pharmacy->suppliers()->create(['code' => 'SUP-01', 'name' => 'Supplier Persediaan', 'active' => true]);
        $product = $pharmacy->products()->create(['sku' => 'INV-01', 'name' => 'Obat Persediaan', 'brand' => 'Brand Stok', 'category' => 'Obat', 'base_unit' => 'Tablet', 'purchase_unit' => 'Box', 'purchase_conversion' => 10, 'selling_price' => 2000, 'minimum_stock' => 20, 'active' => true]);
        $this->actingAs($owner)->post(route('purchases.store', $pharmacy), ['supplier_id' => $supplier->id, 'ordered_on' => '2026-10-01', 'payment_type' => 'cash', 'payment_days' => 0,
            'items' => [['product_id' => $product->id, 'unit_type' => 'purchase', 'quantity' => 100, 'unit_price' => 10000, 'discount' => 0, 'tax' => 0]]])->assertSessionHasNoErrors();
        $order = $pharmacy->purchaseOrders()->firstOrFail();
        $this->post(route('purchases.finalize', [$pharmacy, $order]))->assertSessionHasNoErrors();

        return [$owner, $pharmacy, $product, $order];
    }

    private function receive($pharmacy, $order, string $date, ?string $expiry, string $batch, bool $post = true): void
    {
        $this->post(route('receipts.store', [$pharmacy, $order]), ['received_on' => $date, 'notes' => 'Penerimaan parsial',
            'items' => [['order_item_id' => $order->items()->firstOrFail()->id, 'quantity' => '1.125', 'unit_price' => 10000, 'batch_number' => $batch, 'expires_on' => $expiry, 'location' => 'Rak A']]])->assertSessionHasNoErrors();
        $receipt = $order->receipts()->where('status', 'DRAFT')->firstOrFail();
        if ($post) {
            $this->post(route('receipts.post', [$pharmacy, $receipt]))->assertSessionHasNoErrors();
        }
    }

    public function test_posted_receipts_feed_stock_batches_expiry_filters_and_ledger(): void
    {
        [, $pharmacy, $product, $order] = $this->workspace();
        $this->receive($pharmacy, $order, '2026-10-10', '2026-11-08', 'EXPIRED');
        $this->receive($pharmacy, $order, '2026-11-02', '2026-12-01', 'NEAR');
        $this->receive($pharmacy, $order, '2026-11-03', '2030-01-01', 'DRAFT', false);
        $this->get(route('inventory.index', $pharmacy))->assertOk()->assertViewHas('products', function ($products) {
            $row = $products->first();

            return (float) $row->physical === 22.5 && (float) $row->available === 11.25 && (float) $row->expired === 11.25 && (float) $row->near_expiry === 11.25;
        });
        foreach (['low', 'near', 'expired'] as $status) {
            $this->get(route('inventory.index', [$pharmacy, 'status' => $status]))->assertOk()->assertSee('INV-01');
        }
        $this->get(route('inventory.index', [$pharmacy, 'status' => 'empty']))->assertOk()->assertDontSee('INV-01');
        $this->get(route('inventory.show', [$pharmacy, $product]))->assertOk()->assertSee('EXPIRED')->assertSee('NEAR')->assertDontSee('DRAFT');
        $this->get(route('inventory.ledger', [$pharmacy, $product, 'from' => '2026-11-01', 'to' => '2026-11-08']))->assertOk()
            ->assertViewHas('opening', fn ($value) => (float) $value === 11.25)
            ->assertViewHas('incoming', fn ($value) => (float) $value === 11.25)
            ->assertViewHas('closing', fn ($value) => (float) $value === 22.5)
            ->assertViewHas('movements', fn ($rows) => $rows->total() === 1 && (float) $rows->first()->balance === 22.5);
        $this->get(route('inventory.index', [$pharmacy, 'q' => 'Brand Stok', 'sort' => 'available', 'direction' => 'desc']))->assertOk()->assertSee('INV-01');
        $this->get(route('inventory.index', [$pharmacy, 'q' => 'tidakada']))->assertOk()->assertSee('Produk tidak ditemukan');
        $this->get(route('inventory.ledger', [$pharmacy, $product, 'from' => '2026-11-08', 'to' => '2026-11-01']))->assertSessionHasErrors('to');
    }

    public function test_ledger_running_balance_survives_pagination_and_reverse_sort(): void
    {
        [, $pharmacy, $product, $order] = $this->workspace();
        for ($i = 1; $i <= 21; $i++) {
            $this->receive($pharmacy, $order, '2026-10-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT), '2030-01-01', 'B-'.$i);
        }
        $this->get(route('inventory.ledger', [$pharmacy, $product, 'from' => '2026-10-01', 'to' => '2026-10-31', 'page' => 2]))->assertOk()
            ->assertViewHas('movements', fn ($rows) => $rows->total() === 21 && (float) $rows->first()->balance === 236.25);
        $this->get(route('inventory.ledger', [$pharmacy, $product, 'from' => '2026-10-01', 'to' => '2026-10-31', 'direction' => 'desc']))->assertOk()
            ->assertViewHas('movements', fn ($rows) => (float) $rows->first()->balance === 236.25);
    }

    public function test_zero_stock_and_optional_expiry_follow_pharmacy_settings(): void
    {
        [, $pharmacy, $product, $order] = $this->workspace();
        $this->get(route('inventory.index', [$pharmacy, 'status' => 'empty', 'sort' => 'untrusted-column']))->assertOk()
            ->assertViewHas('products', fn ($rows) => $rows->total() === 1 && (float) $rows->first()->physical === 0.0);
        $this->get(route('inventory.ledger', [$pharmacy, $product]))->assertOk()->assertSee('Tidak ada mutasi pada periode ini');
        $pharmacy->settings()->update(['track_expiry' => false, 'expiry_reminder_days' => 5]);
        $this->receive($pharmacy, $order, '2026-11-01', null, 'NO-EXPIRY');
        $this->receive($pharmacy, $order, '2026-11-02', '2026-11-14', 'OUTSIDE-REMINDER');
        $this->get(route('inventory.index', $pharmacy))->assertOk()
            ->assertViewHas('products', fn ($rows) => (float) $rows->first()->available === 22.5 && (float) $rows->first()->near_expiry === 0.0);
        $this->get(route('inventory.show', [$pharmacy, $product]))->assertOk()->assertSee('Tanpa tanggal kedaluwarsa');
    }

    public function test_inventory_permissions_and_tenant_isolation(): void
    {
        [$owner, $pharmacy, $product, $order] = $this->workspace();
        $this->receive($pharmacy, $order, '2026-11-01', '2030-01-01', 'PRIVATE-BATCH');
        $other = app(AccessManager::class)->provision($owner->id, ['name' => 'Apotek Lain']);
        $this->get(route('inventory.index', $other))->assertOk()->assertDontSee('INV-01');
        $this->get(route('inventory.show', [$other, $product]))->assertNotFound();
        $this->get(route('inventory.ledger', [$other, $product]))->assertNotFound();
        $viewer = User::factory()->create();
        $member = $pharmacy->memberships()->create(['user_id' => $viewer->id, 'active' => true]);
        $role = $pharmacy->roles()->create(['name' => 'Pembaca stok', 'permissions' => ['inventory.view']]);
        app(AccessManager::class)->syncRoles($member, collect([$role]));
        $receipt = $order->receipts()->firstOrFail();
        $this->actingAs($viewer)->get(route('inventory.ledger', [$pharmacy, $product]))->assertOk()->assertDontSee(route('receipts.show', [$pharmacy, $receipt]), false);
        $this->get(route('receipts.show', [$pharmacy, $receipt]))->assertForbidden();
        $role->update(['permissions' => []]);
        $this->get(route('inventory.index', $pharmacy))->assertForbidden();
        $this->get(route('inventory.show', [$pharmacy, $product]))->assertForbidden();
        $this->get(route('inventory.ledger', [$pharmacy, $product]))->assertForbidden();
        $this->assertSame(1, DB::table('stock_movements')->count());
    }
}
