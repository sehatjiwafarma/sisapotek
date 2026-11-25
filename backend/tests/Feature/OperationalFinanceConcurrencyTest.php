<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AccessManager;
use App\Services\CashManagement;
use App\Services\ClinicalMaster;
use App\Services\OperatingExpenses;
use App\Services\Procurement;
use App\Services\ServiceBilling;
use App\Services\VisitRegistration;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class OperationalFinanceConcurrencyTest extends TestCase
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

    private function supplierReturn($pharmacy, $receipt, string $quantity): object
    {
        $line = DB::table('purchase_receipt_items')->where('purchase_receipt_id', $receipt->id)->first();
        $response = $this->post(route('supplier-returns.store', $pharmacy), ['request_key' => (string) Str::uuid(), 'receipt_id' => $receipt->id, 'reason' => 'Rusak', 'items' => [['id' => $line->id, 'quantity' => $quantity]]])->assertSessionHasNoErrors();

        return DB::table('supplier_returns')->find(basename(parse_url($response->headers->get('Location'), PHP_URL_PATH)));
    }

    private function postReturn($pharmacy, $record): void
    {
        $this->post(route('supplier-returns.action', [$pharmacy, $record->id, 'post']), ['revision' => 1, 'accepted' => 1])->assertSessionHasNoErrors();
    }

    private function visit($pharmacy, $owner): string
    {
        $master = app(ClinicalMaster::class);
        $patient = $master->savePatient($pharmacy, $owner->id, ['request_key' => (string) Str::uuid(), 'name' => 'Pasien Layanan', 'birth_date' => '1990-01-01', 'sex' => 'female']);
        $doctor = $master->saveDoctor($pharmacy, $owner->id, ['request_key' => (string) Str::uuid(), 'name' => 'Dokter Layanan']);
        $service = app(VisitRegistration::class);
        $id = $service->save($pharmacy, $owner->id, ['request_key' => (string) Str::uuid(), 'patient_id' => $patient, 'doctor_id' => $doctor, 'type' => 'new', 'complaint' => 'Keluhan privat']);
        \Illuminate\Support\Facades\DB::table('visits')->where('id', $id)->update(['status' => 'IN_PROGRESS', 'started_at' => now(), 'revision' => 2]); // Historical visit fixture.

        return $id;
    }

    private function tariff($pharmacy, $owner): string
    {
        return app(ServiceBilling::class)->tariff($pharmacy, $owner->id, ['name' => 'Konsultasi', 'price' => '50000.25', 'active' => 1]);
    }

    private function bill($pharmacy, string $visit, string $tariff, ?string $sale = null): object
    {
        $response = $this->post(route('billing.store', $pharmacy), ['request_key' => (string) Str::uuid(), 'visit_id' => $visit, 'sale_id' => $sale, 'items' => [['tariff_id' => $tariff, 'quantity' => 1]]])->assertSessionHasNoErrors();

        return DB::table('service_bills')->find(basename(parse_url($response->headers->get('Location'), PHP_URL_PATH)));
    }

    private function expense($pharmacy, $owner, string $amount = '25'): object
    {
        $category = DB::table('expense_categories')->where('pharmacy_id', $pharmacy->id)->where('name', 'Listrik')->value('id') ?? app(OperatingExpenses::class)->category($pharmacy, $owner->id, ['name' => 'Listrik', 'active' => 1]);
        $response = $this->post(route('expenses.store', $pharmacy), ['request_key' => (string) Str::uuid(), 'category_id' => $category, 'spent_on' => '2026-11-09', 'description' => 'Biaya listrik', 'amount' => $amount, 'method' => 'cash'])->assertSessionHasNoErrors();

        return DB::table('operating_expenses')->find(basename(parse_url($response->headers->get('Location'), PHP_URL_PATH)));
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
            if ($argv[4] === 'return') {
                $app->make(App\Services\SupplierReturns::class)->post($pharmacy,(int)$argv[2],$argv[3],['revision'=>1,'accepted'=>1]);
            } elseif ($argv[4] === 'bill') {
                $app->make(App\Services\ServiceBilling::class)->pay($pharmacy,(int)$argv[2],$argv[3],['revision'=>1,'payment_method'=>'cash','amount_received'=>'50000.25','confirmed'=>1]);
            } elseif ($argv[3] === $argv[5]) {
                $app->make(App\Services\CashManagement::class)->close($pharmacy,(int)$argv[2],$argv[3],['revision'=>1,'counted_cash'=>'100','reason'=>'Rekonsiliasi bersamaan']);
            } else {
                $app->make(App\Services\OperatingExpenses::class)->transition($pharmacy,(int)$argv[2],$argv[3],'post',['revision'=>2,'confirmed'=>1]);
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
                $process = new Process([PHP_BINARY, '-r', $worker, $pharmacy->id, (string) $owner->id, $id, $action, $ids[0]], base_path(), ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => 'apotek_test']);
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

    public function test_competing_returns_cannot_reduce_stock_or_invoice_twice(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Penguncian transaksi diuji di PostgreSQL apotek_test.');
        }
        [$owner,$pharmacy,,,$receipt] = $this->workspace();
        $invoice = $this->postedInvoice($pharmacy, $owner, $receipt);
        $first = $this->supplierReturn($pharmacy, $receipt, '4');
        $second = $this->supplierReturn($pharmacy, $receipt, '4');
        $output = $this->workers($pharmacy, $owner, [$first->id, $second->id], 'return');
        $this->assertSame(1, substr_count($output, "done\n"));
        $this->assertSame(1, substr_count($output, "unavailable\n"));
        $this->assertEquals(1, DB::table('stock_movements')->sum('quantity'));
        $this->assertEquals(444, DB::table('supplier_invoices')->find($invoice->id)->credit_amount);
        $this->assertSame(1, DB::table('supplier_returns')->where('status', 'POSTED')->count());
    }

    public function test_duplicate_bill_payment_records_one_receipt_and_one_cash_entry(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Penguncian transaksi diuji di PostgreSQL apotek_test.');
        }
        [$owner,$pharmacy] = $this->workspace();
        $visit = $this->visit($pharmacy, $owner);
        $tariff = $this->tariff($pharmacy, $owner);
        $bill = $this->bill($pharmacy, $visit, $tariff);
        $output = $this->workers($pharmacy, $owner, [$bill->id, $bill->id], 'bill');
        $this->assertSame(2, substr_count($output, "done\n"));
        $this->assertDatabaseCount('cash_entries', 1);
        $this->assertSame(1, DB::table('audit_entries')->where('action', 'service_bill.paid')->count());
    }

    public function test_closing_shift_and_posting_expense_are_serialized_consistently(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Penguncian transaksi diuji di PostgreSQL apotek_test.');
        }
        [$owner,$pharmacy] = $this->workspace();
        $expense = $this->expense($pharmacy, $owner, '50');
        app(OperatingExpenses::class)->transition($pharmacy, $owner->id, $expense->id, 'approve', ['revision' => 1]);
        $shift = app(CashManagement::class)->open($pharmacy, $owner->id, ['request_key' => (string) Str::uuid(), 'opening_cash' => 100]);
        $this->workers($pharmacy, $owner, [$shift, $expense->id], 'race');
        $record = DB::table('cash_shifts')->find($shift);
        $this->assertSame('CLOSED', $record->status);
        $this->assertEquals(CashManagement::amount(CashManagement::expected($record)), $record->expected_cash);
        $posted = DB::table('operating_expenses')->find($expense->id)->status === 'POSTED';
        $this->assertSame($posted ? 1 : 0,DB::table('cash_entries')->count());
        $this->assertEquals($posted ? 50 : 100,$record->expected_cash);
    }
}
