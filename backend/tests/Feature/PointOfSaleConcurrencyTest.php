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

class PointOfSaleConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    protected function tearDown(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }

    public function test_two_cashiers_competing_for_the_last_stock_cannot_oversell(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Penguncian transaksi diuji dengan PostgreSQL apotek_test.');
        }
        $owner = User::factory()->create();
        $pharmacy = app(AccessManager::class)->provision($owner->id, ['name' => 'Apotek Kasir Bersamaan']);
        $pharmacy->forceFill(['status' => 'ACTIVE'])->save();
        $supplier = $pharmacy->suppliers()->create(['code' => 'SUP', 'name' => 'Supplier', 'active' => true]);
        $product = $pharmacy->products()->create(['sku' => 'LAST', 'name' => 'Stok Terakhir', 'brand' => 'Umum', 'category' => 'Obat', 'base_unit' => 'Tablet', 'purchase_unit' => 'Tablet', 'purchase_conversion' => 1, 'selling_price' => 1000, 'minimum_stock' => 0, 'active' => true, 'requires_prescription' => false]);
        $procurement = app(Procurement::class);
        $today = now($pharmacy->timezone ?: 'Asia/Jakarta')->toDateString();
        $order = $procurement->saveOrder($pharmacy, $owner->id, ['supplier_id' => $supplier->id, 'ordered_on' => $today, 'payment_type' => 'cash', 'payment_days' => 0,
            'items' => [['product_id' => $product->id, 'unit_type' => 'base', 'quantity' => 1, 'unit_price' => 500]]]);
        $procurement->finalize($pharmacy, $owner->id, $order->id);
        $receipt = $procurement->receive($pharmacy, $owner->id, $order->id, ['received_on' => $today,
            'items' => [['order_item_id' => $order->items()->firstOrFail()->id, 'quantity' => 1, 'unit_price' => 500, 'batch_number' => 'LAST-BATCH', 'expires_on' => now()->addYear()->toDateString()]]]);
        $procurement->post($pharmacy, $owner->id, $receipt->id);
        $pos = app(PointOfSale::class);
        $ids = [];
        for ($i = 0; $i < 2; $i++) {
            $ids[] = $pos->save($pharmacy, $owner->id, ['request_key' => (string) Str::uuid(), 'items' => [['product_id' => $product->id, 'unit_type' => 'base', 'quantity' => 1]]]);
        }
        $worker = <<<'PHP'
        require 'vendor/autoload.php';
        $app = require 'bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        if (!$app->environment('testing') || Illuminate\Support\Facades\DB::connection()->getDatabaseName() !== 'apotek_test') {
            throw new RuntimeException('Database worker pengujian tidak aman.');
        }
        fwrite(STDOUT, "ready\n");
        fflush(STDOUT);
        try {
            $pharmacy = App\Models\Pharmacy::findOrFail($argv[1]);
            $app->make(App\Services\PointOfSale::class)->pay($pharmacy,(int)$argv[2],$argv[3],['revision'=>1,'payment_method'=>'cash','amount_received'=>'1000']);
            fwrite(STDOUT,"paid\n");
        } catch (Illuminate\Validation\ValidationException $exception) {
            fwrite(STDOUT,"unavailable\n");
        }
        PHP;
        DB::beginTransaction();
        $processes = [];
        try {
            DB::table('pharmacies')->where('id', $pharmacy->id)->lockForUpdate()->first();
            foreach ($ids as $id) {
                $process = new Process([PHP_BINARY, '-r', $worker, $pharmacy->id, (string) $owner->id, $id], base_path(), ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => 'apotek_test']);
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
        $output = implode('', array_map(fn ($process) => $process->getOutput(), $processes));
        $this->assertSame(1, substr_count($output, "paid\n"));
        $this->assertSame(1, substr_count($output, "unavailable\n"));
        $this->assertSame(1, DB::table('sales')->where('status', 'PAID')->count());
        $this->assertSame(1, DB::table('sales')->where('status', 'DRAFT')->count());
        $this->assertEquals(0, DB::table('stock_movements')->sum('quantity'));
        $this->assertSame(1,DB::table('stock_movements')->where('type','SALE')->count());
    }
}
