<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AccessManager;
use App\Services\ClinicalMaster;
use App\Services\PointOfSale;
use App\Services\PrescriptionDispensing;
use App\Services\PrescriptionRecords;
use App\Services\Procurement;
use App\Services\SaleReturns;
use App\Services\VisitRegistration;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PrescriptionReturnConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    private function workspace(): array
    {
        $owner = User::factory()->create();
        $pharmacy = app(AccessManager::class)->provision($owner->id, ['name' => 'Apotek Penebusan']);
        $pharmacy->forceFill(['status' => 'ACTIVE'])->save();
        $master = app(ClinicalMaster::class);
        $patient = $master->savePatient($pharmacy, $owner->id, ['request_key' => (string) Str::uuid(), 'name' => 'Pasien Resep', 'birth_date' => '1990-01-01', 'sex' => 'female']);
        $doctor = $master->saveDoctor($pharmacy, $owner->id, ['request_key' => (string) Str::uuid(), 'name' => 'Dokter Resep']);
        $visits = app(VisitRegistration::class);
        $visit = $visits->save($pharmacy, $owner->id, ['request_key' => (string) Str::uuid(), 'patient_id' => $patient, 'doctor_id' => $doctor, 'type' => 'new', 'complaint' => 'Keluhan']);
        \Illuminate\Support\Facades\DB::table('visits')->where('id', $visit)->update(['status' => 'IN_PROGRESS', 'started_at' => now(), 'revision' => 2]); // Historical visit fixture.
        $product = $pharmacy->products()->create(['sku' => 'RX', 'name' => 'Obat Resep', 'brand' => 'Brand', 'category' => 'Obat', 'base_unit' => 'Tablet', 'purchase_unit' => 'Tablet', 'purchase_conversion' => 1, 'selling_price' => 1000, 'minimum_stock' => 0, 'active' => true, 'requires_prescription' => true]);
        $other = $pharmacy->products()->create(['sku' => 'RX2', 'name' => 'Obat Kedua', 'brand' => 'Brand', 'category' => 'Obat', 'base_unit' => 'Tablet', 'purchase_unit' => 'Tablet', 'purchase_conversion' => 1, 'selling_price' => 2000, 'minimum_stock' => 0, 'active' => true, 'requires_prescription' => true]);
        $records = app(PrescriptionRecords::class);
        $records->save($pharmacy, $owner->id, $visit, 0, ['valid_until' => now()->addDays(30)->toDateString(), 'items' => [
            ['product_id' => $product->id, 'quantity' => 10, 'dose' => '1 tablet', 'frequency' => '2 kali sehari', 'instructions' => 'Sesudah makan'],
            ['product_id' => $other->id, 'quantity' => '2.1234', 'dose' => 'Sesuai petunjuk', 'frequency' => 'Sesuai petunjuk', 'instructions' => 'Aturan kedua'],
        ]]);

        $rx = DB::table('prescriptions')->first();
        $supplier = $pharmacy->suppliers()->create(['code' => 'SUP', 'name' => 'Pemasok', 'active' => true]);
        $procurement = app(Procurement::class);
        $today = now()->toDateString();
        $order = $procurement->saveOrder($pharmacy, $owner->id, ['supplier_id' => $supplier->id, 'ordered_on' => $today, 'payment_type' => 'cash', 'payment_days' => 0, 'items' => [
            ['product_id' => $product->id, 'unit_type' => 'base', 'quantity' => 30, 'unit_price' => 500],
            ['product_id' => $other->id, 'unit_type' => 'base', 'quantity' => 30, 'unit_price' => 500],
        ]]);
        $procurement->finalize($pharmacy, $owner->id, $order->id);
        foreach ([['EARLY', now()->addMonth()->toDateString(), 4], ['LATE', now()->addYear()->toDateString(), 10]] as [$batch,$expires,$quantity]) {
            $receipt = $procurement->receive($pharmacy, $owner->id, $order->id, ['received_on' => $today, 'notes' => 'Penerimaan bertahap', 'items' => $order->items()->get()->map(fn ($item) => ['order_item_id' => $item->id, 'quantity' => $quantity, 'unit_price' => 500, 'batch_number' => $batch, 'expires_on' => $expires])->all()]);
            $procurement->post($pharmacy, $owner->id, $receipt->id);
        }
        $this->actingAs($owner);

        $records->finalize($pharmacy, $owner->id, $visit, 1);
        $rx = DB::table('prescriptions')->find($rx->id);

        return [$owner, $pharmacy, $rx, $product, $other];
    }

    private function paidRedemption($pharmacy, $rx, $product, bool $handedOver = true): object
    {
        $id = app(PrescriptionDispensing::class)->create($pharmacy, auth()->id(), $rx->id, ['request_key' => (string) Str::uuid(), 'verified' => 1, 'recipient_type' => 'self', 'items' => [['product_id' => $product->id, 'quantity' => 4]]]);
        $sale = DB::table('sales')->find($id);
        app(PointOfSale::class)->pay($pharmacy, auth()->id(), $id, ['revision' => 1, 'payment_method' => 'cash', 'amount_received' => $sale->total]);
        if ($handedOver) {
            app(PrescriptionDispensing::class)->handover($pharmacy, auth()->id(), $id);
        }

        return DB::table('sales')->find($id);
    }

    private function returned($pharmacy, $sale, string $quantity = '1', array $extra = []): object
    {
        $movement = SaleReturns::allocations($pharmacy, $sale->id)->first();
        $response = $this->post(route('returns.store', [$pharmacy, $sale->id]), $extra + ['request_key' => (string) Str::uuid(), 'reason' => 'Alasan klinis privat', 'condition_checked' => 1, 'returner_type' => 'self', 'items' => [['sale_movement_id' => $movement->id, 'quantity' => $quantity, 'disposition' => 'QUARANTINE']]])->assertSessionHasNoErrors()->assertRedirect();

        return DB::table('sale_returns')->find(basename(parse_url($response->headers->get('Location'), PHP_URL_PATH)));
    }

    private function completeReturn($pharmacy, $record): void
    {
        $this->post(route('returns.approve', [$pharmacy, $record->id]), ['revision' => 1])->assertSessionHasNoErrors();
        $this->post(route('returns.refund', [$pharmacy, $record->id]), ['revision' => 2, 'refund_method' => 'cash', 'refund_confirmed' => 1])->assertSessionHasNoErrors();
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
            if ($argv[4] === 'restore') {
                $returns->restoreEntitlement($pharmacy,(int)$argv[2],$argv[3],3,'Persetujuan uji privat');
            } elseif ($argv[4] === 'pay') {
                $app->make(App\Services\PointOfSale::class)->pay($pharmacy,(int)$argv[2],$argv[3],['revision'=>1,'payment_method'=>'cash','amount_received'=>'6000']);
            } elseif ($argv[4] === 'approve') {
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

    public function test_duplicate_restoration_is_once_and_concurrent_redemptions_keep_the_prescription_limit(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Penguncian diuji pada PostgreSQL apotek_test.');
        }
        [$owner,$pharmacy,$rx,$product] = $this->workspace();
        $sale = $this->paidRedemption($pharmacy, $rx, $product);
        $record = $this->returned($pharmacy, $sale, '4');
        $this->completeReturn($pharmacy, $record);
        $output = $this->workers($pharmacy, $owner, [$record->id, $record->id], 'restore');
        $this->assertSame(2, substr_count($output, "done\n"));
        $this->assertSame(1, DB::table('audit_entries')->where('action', 'sale_return.entitlement_restored')->count());
        $this->assertSame(100000, PrescriptionDispensing::remaining($pharmacy, DB::table('prescriptions')->find($rx->id))[0]['remaining']);
        $ids = [];
        for ($i = 0; $i < 2; $i++) {
            $ids[] = app(PrescriptionDispensing::class)->create($pharmacy, $owner->id, $rx->id, ['request_key' => (string) Str::uuid(), 'verified' => 1, 'recipient_type' => 'self', 'items' => [['product_id' => $product->id, 'quantity' => 6]]]);
        }
        $output = $this->workers($pharmacy, $owner, $ids, 'pay');
        $this->assertSame(1, substr_count($output, "done\n"));
        $this->assertSame(1, substr_count($output, "unavailable\n"));
        $this->assertSame(40000, PrescriptionDispensing::remaining($pharmacy, DB::table('prescriptions')->find($rx->id))[0]['remaining']);
        $this->assertSame(0,DB::table('stock_movements')->where('type','SALE_RETURN')->count());
    }
}
