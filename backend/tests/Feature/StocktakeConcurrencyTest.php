<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AccessManager;
use App\Services\PointOfSale;
use App\Services\Procurement;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class StocktakeConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    protected function tearDown(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }

    public function test_snapshot_and_payment_racing_preserve_an_unchanged_counting_balance(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Konkurensi opname diuji pada PostgreSQL apotek_test.');
        }
        $owner = User::factory()->create();
        $pharmacy = app(AccessManager::class)->provision($owner->id, ['name' => 'Apotek Snapshot Bersamaan']);
        $pharmacy->forceFill(['status' => 'ACTIVE'])->save();
        $product = $pharmacy->products()->create(['sku' => 'RACE', 'name' => 'Produk', 'brand' => 'Brand', 'category' => 'Obat', 'base_unit' => 'Tablet', 'purchase_unit' => 'Tablet', 'purchase_conversion' => 1, 'selling_price' => 1000, 'minimum_stock' => 0, 'active' => true, 'requires_prescription' => false]);
        $supplier = $pharmacy->suppliers()->create(['code' => 'SUP', 'name' => 'Supplier', 'active' => true]);
        $today = now($pharmacy->timezone ?: 'Asia/Jakarta')->toDateString();
        $procurement = app(Procurement::class);
        $order = $procurement->saveOrder($pharmacy, $owner->id, ['supplier_id' => $supplier->id, 'ordered_on' => $today, 'payment_type' => 'cash', 'payment_days' => 0,
            'items' => [['product_id' => $product->id, 'unit_type' => 'base', 'quantity' => 1, 'unit_price' => 500]]]);
        $procurement->finalize($pharmacy, $owner->id, $order->id);
        $receipt = $procurement->receive($pharmacy, $owner->id, $order->id, ['received_on' => $today,
            'items' => [['order_item_id' => $order->items()->first()->id, 'quantity' => 1, 'unit_price' => 500, 'batch_number' => 'B', 'expires_on' => now()->addYear()->toDateString()]]]);
        $procurement->post($pharmacy, $owner->id, $receipt->id);
        $sale = app(PointOfSale::class)->save($pharmacy, $owner->id, ['request_key' => (string) Str::uuid(), 'items' => [['product_id' => $product->id, 'unit_type' => 'base', 'quantity' => 1]]]);
        $worker = <<<'PHP'
        require 'vendor/autoload.php';
        $app = require 'bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        if (!$app->environment('testing') || Illuminate\Support\Facades\DB::connection()->getDatabaseName() !== 'apotek_test') {
            throw new RuntimeException('Database worker tidak aman.');
        }
        fwrite(STDOUT,"ready\n"); fflush(STDOUT);
        $pharmacy = App\Models\Pharmacy::findOrFail($argv[1]);
        try {
            if ($argv[4] === 'start') {
                $app->make(App\Services\Stocktaking::class)->start($pharmacy,(int)$argv[2],['request_key'=>(string)Illuminate\Support\Str::uuid(),'product_id'=>$argv[5]]);
            } else {
                $app->make(App\Services\PointOfSale::class)->pay($pharmacy,(int)$argv[2],$argv[3],['revision'=>1,'payment_method'=>'cash','amount_received'=>1000]);
            }
        } catch (Illuminate\Validation\ValidationException $exception) {
            fwrite(STDOUT,"blocked\n");
        }
        PHP;
        $processes = [];
        DB::beginTransaction();
        try {
            DB::table('pharmacies')->where('id', $pharmacy->id)->lockForUpdate()->first();
            foreach (['start', 'pay'] as $action) {
                $process = new Process([PHP_BINARY, '-r', $worker, $pharmacy->id, (string) $owner->id, $sale, $action, $product->id], base_path(), ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => 'apotek_test']);
                $process->setTimeout(30);
                $process->start();
                $processes[] = $process;
            }
            foreach ($processes as $process) {
                $this->assertTrue($process->waitUntil(fn ($type, $output) => str_contains($output, 'ready')), $process->getErrorOutput());
            }
        } finally {
            DB::commit();
        }
        foreach ($processes as $process) {
            $this->assertSame(0, $process->wait(), $process->getErrorOutput());
        }
        $this->assertDatabaseCount('stocktakes', 1);
        $snapshot = DB::table('stocktake_lines')->sum('system_quantity');
        $current = DB::table('stock_movements')->sum('quantity');
        $this->assertEquals($snapshot, $current);
        $this->assertContains(DB::table('sales')->find($sale)->status, ['PAID', 'DRAFT']);
        $this->assertSame('COUNTING', DB::table('stocktakes')->value('status'));
        $this->assertEquals(DB::table('sales')->find($sale)->status === 'PAID' ? 0 : 1, $snapshot);
    }
}
