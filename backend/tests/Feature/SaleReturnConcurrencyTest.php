<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AccessManager;
use App\Services\SaleReturns;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class SaleReturnConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

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

    protected function tearDown(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }

    private function workers($pharmacy, $owner, array $ids, string $action): string
    {
        $worker = <<<'PHP'
        require 'vendor/autoload.php';
        $app=require 'bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        if (!$app->environment('testing') || Illuminate\Support\Facades\DB::connection()->getDatabaseName() !== 'apotek_test') {
            throw new RuntimeException('Database worker tidak aman.');
        }
        fwrite(STDOUT,"ready\n");fflush(STDOUT);
        try {
            $pharmacy=App\Models\Pharmacy::findOrFail($argv[1]);
            $returns=$app->make(App\Services\SaleReturns::class);
            if ($argv[4] === 'approve') {
                $returns->approve($pharmacy,(int)$argv[2],$argv[3],1);
            } else {
                $returns->refund($pharmacy,(int)$argv[2],$argv[3],['revision'=>2,'refund_method'=>'cash','refund_confirmed'=>1]);
            }
            fwrite(STDOUT,"done\n");
        } catch (Illuminate\Validation\ValidationException $exception) {
            fwrite(STDOUT,"unavailable\n");
        }
        PHP;
        DB::beginTransaction();
        $processes = [];
        try {
            DB::table('pharmacies')->where('id', $pharmacy->id)->lockForUpdate()->first();
            foreach ($ids as $id) {
                $process = new Process([PHP_BINARY, '-r', $worker, $pharmacy->id, (string) $owner->id, $id, $action], base_path(), ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => 'apotek_test']);
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

        return implode('', array_map(fn ($process) => $process->getOutput(), $processes));
    }

    public function test_competing_approvals_and_duplicate_refunds_do_not_exceed_sale_or_restore_stock_twice(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Penguncian transaksi diuji pada PostgreSQL apotek_test.');
        }
        [$owner,$pharmacy,$product] = $this->workspace();
        $sale = $this->paidSale($pharmacy, $product);
        $movement = SaleReturns::allocations($pharmacy, $sale->id)->first();
        $rows = [['sale_movement_id' => $movement->id, 'quantity' => (string) abs($movement->quantity), 'disposition' => 'RESTOCK']];
        $first = $this->requestReturn($pharmacy, $sale, $rows);
        $second = $this->requestReturn($pharmacy, $sale, $rows);
        $output = $this->workers($pharmacy, $owner, [$first->id, $second->id], 'approve');
        $this->assertSame(1, substr_count($output, "done\n"));
        $this->assertSame(1, substr_count($output, "unavailable\n"));
        $approved = DB::table('sale_returns')->where('status', 'APPROVED')->first();
        $this->assertNotNull($approved);
        $output = $this->workers($pharmacy, $owner, [$approved->id, $approved->id], 'refund');
        $this->assertSame(2, substr_count($output, "done\n"));
        $this->assertSame(1, DB::table('stock_movements')->where('type', 'SALE_RETURN')->count());
        $this->assertEquals(abs($movement->quantity), DB::table('stock_movements')->where('type', 'SALE_RETURN')->sum('quantity'));
        $this->assertSame(1,DB::table('sale_returns')->where('status','COMPLETED')->count());
        $this->assertSame(1,DB::table('audit_entries')->where('action','sale_return.refunded')->count());
    }
}
