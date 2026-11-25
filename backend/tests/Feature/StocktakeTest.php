<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AccessManager;
use App\Services\PointOfSale;
use App\Services\Procurement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class StocktakeTest extends TestCase
{
    use RefreshDatabase;

    private function workspace(): array
    {
        $this->travelTo(now()->setDate(2026, 11, 8)->setTime(12, 0));
        $owner = User::factory()->create();
        $pharmacy = app(AccessManager::class)->provision($owner->id, ['name' => 'Apotek Opname']);
        $pharmacy->forceFill(['status' => 'ACTIVE'])->save();
        $product = $this->product($pharmacy, 'OBAT');
        $supplier = $pharmacy->suppliers()->create(['code' => 'SUP', 'name' => 'Supplier', 'active' => true]);
        $procurement = app(Procurement::class);
        $order = $procurement->saveOrder($pharmacy, $owner->id, ['supplier_id' => $supplier->id, 'ordered_on' => '2026-10-01', 'payment_type' => 'cash', 'payment_days' => 0,
            'items' => [['product_id' => $product->id, 'unit_type' => 'base', 'quantity' => 100, 'unit_price' => 500]]]);
        $procurement->finalize($pharmacy, $owner->id, $order->id);
        foreach ([['A', '2030-01-01', 4], ['A', '2030-01-01', 6], ['EXPIRED', '2026-11-08', 2]] as [$batch,$expiry,$quantity]) {
            $receipt = $procurement->receive($pharmacy, $owner->id, $order->id, ['received_on' => '2026-10-01', 'notes' => 'Sebagian',
                'items' => [['order_item_id' => $order->items()->first()->id, 'quantity' => $quantity, 'unit_price' => 500, 'batch_number' => $batch, 'expires_on' => $expiry, 'location' => 'Rak A']]]);
            $procurement->post($pharmacy, $owner->id, $receipt->id);
        }
        $this->actingAs($owner);

        return [$owner, $pharmacy, $product, $order];
    }

    private function product($pharmacy, string $sku): object
    {
        return $pharmacy->products()->create(['sku' => $sku, 'name' => 'Produk '.$sku, 'brand' => 'Brand', 'category' => 'Obat', 'base_unit' => 'Tablet', 'purchase_unit' => 'Tablet',
            'purchase_conversion' => 1, 'selling_price' => 1000, 'minimum_stock' => 0, 'active' => true, 'requires_prescription' => false]);
    }

    private function openStocktake($pharmacy, $product = null): object
    {
        $response = $this->post(route('stocktakes.store', $pharmacy), ['request_key' => (string) Str::uuid(), 'product_id' => $product?->id])->assertSessionHasNoErrors();

        return DB::table('stocktakes')->find(basename(parse_url($response->headers->get('Location'), PHP_URL_PATH)));
    }

    private function counts($pharmacy, object $session, callable $physical): object
    {
        $lines = DB::table('stocktake_lines')->where('stocktake_id', $session->id)->get()->map(fn ($line) => ['id' => $line->id, 'physical_quantity' => $physical($line), 'reason' => 'Hasil hitung ulang fisik'])->all();
        $this->put(route('stocktakes.counts', [$pharmacy, $session->id]), ['revision' => $session->revision, 'lines' => $lines])->assertSessionHasNoErrors();

        return DB::table('stocktakes')->find($session->id);
    }

    private function finish($pharmacy, object $session): object
    {
        $this->post(route('stocktakes.approve', [$pharmacy, $session->id]), ['revision' => $session->revision])->assertSessionHasNoErrors();
        $session = DB::table('stocktakes')->find($session->id);
        $this->post(route('stocktakes.post', [$pharmacy, $session->id]), ['revision' => $session->revision])->assertSessionHasNoErrors();

        return DB::table('stocktakes')->find($session->id);
    }

    public function test_physical_stock_card_is_saved_separately_and_validated(): void
    {
        [, $pharmacy, $product] = $this->workspace();
        $session = $this->openStocktake($pharmacy, $product);
        $line = DB::table('stocktake_lines')->where('stocktake_id', $session->id)->first();
        $url = route('stocktakes.counts', [$pharmacy, $session->id]);
        $data = ['revision' => $session->revision, 'lines' => [['id' => $line->id, 'physical_quantity' => $line->system_quantity, 'physical_card_quantity' => '3.2500']]];
        $this->put($url, $data)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('stocktake_lines', ['id' => $line->id, 'physical_card_quantity' => '3.2500', 'physical_quantity' => $line->system_quantity]);
        $this->assertDatabaseCount('stock_movements', 3);
        $this->get(route('stocktakes.show', [$pharmacy, $session->id]))->assertOk()->assertSee('KARTU STOK FISIK')->assertSee('physical_card_quantity');
        $data['revision']++;
        $data['lines'][0]['physical_card_quantity'] = -1;
        $this->put($url, $data)->assertSessionHasErrors('lines.0.physical_card_quantity');
        $data['lines'][0]['physical_card_quantity'] = 0;
        $this->put($url, $data)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('stocktake_lines', ['id' => $line->id, 'physical_card_quantity' => 0]);
    }

    public function test_snapshot_counts_approval_posting_and_corrected_stock_can_be_sold(): void
    {
        [$owner,$pharmacy,$product] = $this->workspace();
        $this->get(route('stocktakes.create', $pharmacy))->assertOk();
        $session = $this->openStocktake($pharmacy, $product);
        $this->get(route('stocktakes.index', $pharmacy))->assertOk()->assertSee($session->number);
        $this->assertDatabaseCount('stocktake_lines', 2);
        $this->assertEquals(10, DB::table('stocktake_lines')->where('batch_number', 'A')->value('system_quantity'));
        $this->post(route('stocktakes.lines', [$pharmacy, $session->id]), ['revision' => 1, 'product_id' => $product->id, 'batch_number' => 'FOUND', 'expires_on' => '2031-01-01', 'location' => 'Rak B'])->assertSessionHasNoErrors();
        $session = DB::table('stocktakes')->find($session->id);
        $session = $this->counts($pharmacy, $session, fn ($line) => match ($line->batch_number) {
            'A' => 8,'EXPIRED' => 0,'FOUND' => '3.2500'
        });
        $this->get(route('stocktakes.show', [$pharmacy, $session->id]))->assertOk()->assertSee('Hasil penghitungan');
        $posted = $this->finish($pharmacy, $session);
        $this->assertSame('POSTED', $posted->status);
        $this->assertEquals(11.25, DB::table('stock_movements')->sum('quantity'));
        $movements = DB::table('stock_movements')->whereNotNull('stocktake_line_id')->count();
        $this->post(route('stocktakes.post', [$pharmacy, $posted->id]), ['revision' => $session->revision + 1])->assertSessionHasNoErrors();
        $this->assertSame($movements, DB::table('stock_movements')->whereNotNull('stocktake_line_id')->count());
        $this->get(route('inventory.ledger', [$pharmacy, $product, 'from' => '2026-10-01', 'to' => '2026-11-08']))->assertOk()->assertSee('Koreksi opname')->assertSee(route('stocktakes.show', [$pharmacy, $session->id]), false);
        $pos = app(PointOfSale::class);
        $sale = $pos->save($pharmacy, $owner->id, ['request_key' => (string) Str::uuid(), 'items' => [['product_id' => $product->id, 'unit_type' => 'base', 'quantity' => 9]]]);
        $pos->pay($pharmacy, $owner->id, $sale, ['revision' => 1, 'payment_method' => 'cash', 'amount_received' => 9000]);
        $this->assertEquals(2.25, DB::table('stock_movements')->sum('quantity'));
        $this->assertEquals(-1, DB::table('stock_movements')->where('type', 'SALE')->where('batch_number', 'FOUND')->sum('quantity'));
        $this->assertSame(1, DB::table('audit_entries')->where('action', 'stocktake.posted')->count());
        $this->post(route('stocktakes.cancel', [$pharmacy, $session->id]), ['revision' => $posted->revision, 'reason' => 'Tidak boleh'])->assertSessionHasErrors();
        $second = $this->openStocktake($pharmacy, $product);
        $second = $this->counts($pharmacy, $second, fn ($line) => $line->batch_number === 'FOUND' ? 1 : 0);
        $this->finish($pharmacy, $second);
        $sale = $pos->save($pharmacy, $owner->id, ['request_key' => (string) Str::uuid(), 'items' => [['product_id' => $product->id, 'unit_type' => 'base', 'quantity' => 1]]]);
        $pos->pay($pharmacy, $owner->id, $sale, ['revision' => 1, 'payment_method' => 'cash', 'amount_received' => 1000]);
        $this->assertEquals(0, DB::table('stock_movements')->sum('quantity'));
    }

    public function test_open_scope_blocks_payments_and_receipts_but_cancellation_releases_it(): void
    {
        [$owner,$pharmacy,$product,$order] = $this->workspace();
        $pos = app(PointOfSale::class);
        $sale = $pos->save($pharmacy, $owner->id, ['request_key' => (string) Str::uuid(), 'items' => [['product_id' => $product->id, 'unit_type' => 'base', 'quantity' => 1]]]);
        $session = $this->openStocktake($pharmacy, $product);
        $this->getJson(route('sales.catalog', [$pharmacy, 'q' => $product->sku]))->assertOk()->assertJsonPath('data.0.stocktake_number', $session->number);
        $this->post(route('sales.pay', [$pharmacy, $sale]), ['revision' => 1, 'payment_method' => 'cash', 'amount_received' => 1000])->assertSessionHasErrors('stocktake');
        $receipt = app(Procurement::class)->receive($pharmacy, $owner->id, $order->id, ['received_on' => '2026-11-01', 'notes' => 'Sebagian',
            'items' => [['order_item_id' => $order->items()->first()->id, 'quantity' => 1, 'unit_price' => 500, 'batch_number' => 'B', 'expires_on' => '2030-01-01']]]);
        $this->post(route('receipts.post', [$pharmacy, $receipt]))->assertSessionHasErrors('stocktake');
        $this->post(route('stocktakes.store', $pharmacy), ['request_key' => (string) Str::uuid(), 'product_id' => $product->id])->assertSessionHasErrors('stocktake');
        $other = $this->product($pharmacy, 'OTHER');
        $otherSession = $this->openStocktake($pharmacy, $other);
        $this->assertNotSame($session->id, $otherSession->id);
        $this->post(route('stocktakes.cancel', [$pharmacy, $session->id]), ['revision' => 1, 'reason' => 'Jadwal ulang'])->assertSessionHasNoErrors();
        $this->post(route('receipts.post', [$pharmacy, $receipt]))->assertSessionHasNoErrors();
        $this->post(route('sales.pay', [$pharmacy, $sale]), ['revision' => 1, 'payment_method' => 'cash', 'amount_received' => 1000])->assertSessionHasNoErrors();
    }

    public function test_incomplete_counts_reasons_revisions_and_approved_immutability(): void
    {
        [, $pharmacy,$product] = $this->workspace();
        $session = $this->openStocktake($pharmacy, $product);
        $line = DB::table('stocktake_lines')->where('stocktake_id', $session->id)->first();
        $this->post(route('stocktakes.approve', [$pharmacy, $session->id]), ['revision' => 1])->assertSessionHasErrors('stocktake');
        $this->post(route('stocktakes.post', [$pharmacy, $session->id]), ['revision' => 1])->assertSessionHasErrors('stocktake');
        $this->put(route('stocktakes.counts', [$pharmacy, $session->id]), ['revision' => 1, 'lines' => [['id' => $line->id, 'physical_quantity' => 0]]])->assertSessionHasErrors('stocktake');
        foreach (['-1', '0.00001', '10000000001'] as $value) {
            $this->put(route('stocktakes.counts', [$pharmacy, $session->id]), ['revision' => 1, 'lines' => [['id' => $line->id, 'physical_quantity' => $value, 'reason' => 'Uji']]])->assertSessionHasErrors();
        }
        $current = $this->counts($pharmacy, $session, fn ($line) => $line->system_quantity);
        $this->put(route('stocktakes.counts', [$pharmacy, $session->id]), ['revision' => 1, 'lines' => [['id' => $line->id, 'physical_quantity' => 0, 'reason' => 'Lama']]])->assertSessionHasErrors('stocktake');
        $posted = $this->finish($pharmacy, $current);
        $this->assertEquals(0, DB::table('stock_movements')->whereNotNull('stocktake_line_id')->count());
        $this->put(route('stocktakes.counts', [$pharmacy, $session->id]), ['revision' => $posted->revision, 'lines' => [['id' => $line->id, 'physical_quantity' => 0, 'reason' => 'Terkunci']]])->assertSessionHasErrors('stocktake');
    }

    public function test_legacy_sale_sources_are_not_counted_twice_after_correction(): void
    {
        [$owner,$pharmacy,$product] = $this->workspace();
        $pos = app(PointOfSale::class);
        $sale = $pos->save($pharmacy, $owner->id, ['request_key' => (string) Str::uuid(), 'items' => [['product_id' => $product->id, 'quantity' => 2, 'unit_type' => 'base']]]);
        $pos->pay($pharmacy, $owner->id, $sale, ['revision' => 1, 'payment_method' => 'cash', 'amount_received' => 2000]);
        DB::table('stock_movements')->where('type', 'SALE')->update(['source_movement_id' => null]);
        $session = $this->openStocktake($pharmacy, $product);
        $session = $this->counts($pharmacy, $session, fn ($line) => $line->batch_number === 'A' ? 7 : 2);
        $this->finish($pharmacy, $session);
        $sale = $pos->save($pharmacy, $owner->id, ['request_key' => (string) Str::uuid(), 'items' => [['product_id' => $product->id, 'quantity' => 7, 'unit_type' => 'base']]]);
        $pos->pay($pharmacy, $owner->id, $sale, ['revision' => 1, 'payment_method' => 'cash', 'amount_received' => 7000]);
        $this->assertEquals(2, DB::table('stock_movements')->sum('quantity'));
        $this->get(route('inventory.index', [$pharmacy, 'status' => 'empty']))->assertOk()->assertSee($product->sku);
    }

    public function test_permission_tenant_line_scope_and_inactive_cancellation(): void
    {
        [$owner,$pharmacy,$product] = $this->workspace();
        $session = $this->openStocktake($pharmacy, $product);
        $other = app(AccessManager::class)->provision($owner->id, ['name' => 'Apotek Lain']);
        $this->get(route('stocktakes.show', [$other, $session->id]))->assertNotFound();
        $viewer = User::factory()->create();
        $member = $pharmacy->memberships()->create(['user_id' => $viewer->id, 'active' => true]);
        $role = $pharmacy->roles()->create(['name' => 'Penghitung', 'permissions' => ['stocktakes.view', 'stocktakes.update']]);
        app(AccessManager::class)->syncRoles($member, collect([$role]));
        $this->actingAs($viewer)->get(route('stocktakes.show', [$pharmacy, $session->id]))->assertOk();
        $this->post(route('stocktakes.approve', [$pharmacy, $session->id]), ['revision' => 1])->assertForbidden();
        $this->post(route('stocktakes.post', [$pharmacy, $session->id]), ['revision' => 1])->assertForbidden();
        $this->post(route('stocktakes.cancel', [$pharmacy, $session->id]), ['revision' => 1, 'reason' => 'Tanpa izin'])->assertForbidden();
        $this->put(route('stocktakes.counts', [$pharmacy, $session->id]), ['revision' => 1, 'lines' => [['id' => (string) Str::uuid(), 'physical_quantity' => 0]]])->assertNotFound();
        $this->actingAs($owner);
        $pharmacy->forceFill(['status' => 'INACTIVE'])->save();
        $this->post(route('stocktakes.cancel', [$pharmacy, $session->id]), ['revision' => 1, 'reason' => 'Apotek dinonaktifkan'])->assertSessionHasNoErrors();
        $this->assertSame('CANCELLED', DB::table('stocktakes')->find($session->id)->status);
    }

    public function test_zero_stock_found_batch_validation_and_paginated_counts(): void
    {
        [, $pharmacy] = $this->workspace();
        $empty = $this->product($pharmacy, 'EMPTY');
        $session = $this->openStocktake($pharmacy, $empty);
        $line = DB::table('stocktake_lines')->where('stocktake_id', $session->id)->first();
        $this->put(route('stocktakes.counts', [$pharmacy, $session->id]), ['revision' => 1, 'lines' => [['id' => $line->id, 'physical_quantity' => 1, 'reason' => 'Ditemukan']]])->assertSessionHasErrors('stocktake');
        $this->post(route('stocktakes.lines', [$pharmacy, $session->id]), ['revision' => 1, 'product_id' => $empty->id, 'batch_number' => 'FOUND'])->assertSessionHasErrors('stocktake');
        $this->post(route('stocktakes.lines', [$pharmacy, $session->id]), ['revision' => 1, 'product_id' => $empty->id, 'batch_number' => 'FOUND', 'expires_on' => '2030-01-01'])->assertSessionHasNoErrors();
        $this->post(route('stocktakes.lines', [$pharmacy, $session->id]), ['revision' => 2, 'product_id' => $empty->id, 'batch_number' => 'FOUND', 'expires_on' => '2030-01-01'])->assertSessionHasErrors('stocktake');
        $session = DB::table('stocktakes')->find($session->id);
        $session = $this->counts($pharmacy, $session, fn ($line) => $line->batch_number === 'FOUND' ? '0.0001' : 0);
        $this->finish($pharmacy, $session);
        $this->assertEquals(0.0001, DB::table('stock_movements')->where('product_id', $empty->id)->sum('quantity'));
        foreach (range(1, 26) as $index) {
            $this->product($pharmacy, 'PAGE-'.$index);
        }
        $all = $this->openStocktake($pharmacy);
        $this->get(route('stocktakes.show', [$pharmacy, $all->id, 'page' => 2, 'sort' => 'name', 'direction' => 'desc']))->assertOk()->assertViewHas('lines', fn ($lines) => $lines->total() === 29 && $lines->count() === 4);
    }

    public function test_changed_snapshot_is_rejected_and_replayed_start_is_idempotent(): void
    {
        [, $pharmacy,$product] = $this->workspace();
        $key = (string) Str::uuid();
        $data = ['request_key' => $key, 'product_id' => $product->id];
        $this->post(route('stocktakes.store', $pharmacy), $data)->assertSessionHasNoErrors();
        $this->post(route('stocktakes.store', $pharmacy), $data)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('stocktakes', 1);
        $session = DB::table('stocktakes')->first();
        $session = $this->counts($pharmacy, $session, fn ($line) => $line->system_quantity);
        DB::table('stock_movements')->insert(['id' => (string) Str::uuid(), 'pharmacy_id' => $pharmacy->id, 'product_id' => $product->id, 'type' => 'EXTERNAL', 'quantity' => 1, 'base_unit' => 'Tablet', 'occurred_on' => '2026-11-08', 'created_at' => now()]);
        $this->post(route('stocktakes.approve', [$pharmacy, $session->id]), ['revision' => $session->revision])->assertSessionHasErrors('stocktake');
        $this->assertSame('COUNTING', DB::table('stocktakes')->find($session->id)->status);
    }
}
