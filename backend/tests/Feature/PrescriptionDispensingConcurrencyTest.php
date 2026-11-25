<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AccessManager;
use App\Services\ClinicalMaster;
use App\Services\PrescriptionDispensing;
use App\Services\PrescriptionRecords;
use App\Services\Procurement;
use App\Services\VisitRegistration;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PrescriptionDispensingConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    protected function tearDown(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }

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

    public function test_simultaneous_payments_cannot_exceed_the_remaining_prescription(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Penguncian transaksi diuji dengan PostgreSQL apotek_test.');
        }
        [$owner,$pharmacy,$rx,$product] = $this->workspace();
        $ids = [];
        for ($i = 0; $i < 2; $i++) {
            $ids[] = app(PrescriptionDispensing::class)->create($pharmacy, $owner->id, $rx->id, ['request_key' => (string) Str::uuid(), 'verified' => 1, 'recipient_type' => 'self', 'items' => [['product_id' => $product->id, 'quantity' => '6']]]);
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
            $app->make(App\Services\PointOfSale::class)->pay($pharmacy,(int)$argv[2],$argv[3],['revision'=>1,'payment_method'=>'cash','amount_received'=>'6000']);
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
        $this->assertEquals(-6, DB::table('stock_movements')->where('type', 'SALE')->sum('quantity'));
        $this->assertDatabaseHas('prescriptions', ['id' => $rx->id, 'dispensing_status' => 'PARTIAL']);
        $this->assertSame(40000, PrescriptionDispensing::remaining($pharmacy, DB::table('prescriptions')->find($rx->id))[0]['remaining']);
    }
}
