<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AccessManager;
use App\Services\Procurement;
use App\Services\SupplierPayables;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class SupplierPayableConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

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
            $returns=$app->make(App\Services\SupplierPayables::class);
            if ($argv[4] === 'approve') {
                $returns->approve($pharmacy,(int)$argv[2],$argv[3],1);
            } else {
                $returns->post($pharmacy,(int)$argv[2],$argv[3],2,true);
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

    public function test_competing_approvals_and_duplicate_posting_cannot_overpay(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Penguncian transaksi diuji pada PostgreSQL apotek_test.');
        }
        [$owner,$pharmacy,$supplier,$order,$receipt] = $this->workspace();
        $invoice = $this->postedInvoice($pharmacy, $owner, $receipt);
        $first = $this->payment($pharmacy, $invoice, '400');
        $second = $this->payment($pharmacy, $invoice, '400');
        $stock = DB::table('stock_movements')->count();
        $output = $this->workers($pharmacy, $owner, [$first->id, $second->id], 'approve');
        $this->assertSame(1, substr_count($output, "done\n"));
        $this->assertSame(1, substr_count($output, "unavailable\n"));
        $approved = DB::table('supplier_payments')->where('status', 'APPROVED')->first();
        $this->assertNotNull($approved);
        $balance = SupplierPayables::balances($pharmacy, $invoice);
        $this->assertSame(40000, $balance['reserved']);
        $this->assertSame(15500, $balance['available']);
        $output = $this->workers($pharmacy, $owner, [$approved->id, $approved->id], 'post');
        $this->assertSame(2, substr_count($output, "done\n"));
        $this->assertSame(1, DB::table('supplier_payments')->where('status', 'POSTED')->count());
        $this->assertSame(1, DB::table('audit_entries')->where('action','supplier_payment.posted')->count());
        $this->assertSame(15500,SupplierPayables::balances($pharmacy,$invoice)['outstanding']);
        $this->assertSame($stock,DB::table('stock_movements')->count());
    }
}
