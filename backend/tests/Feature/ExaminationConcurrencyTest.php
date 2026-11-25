<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AccessManager;
use App\Services\ClinicalMaster;
use App\Services\ExaminationRecords;
use App\Services\PrescriptionRecords;
use App\Services\VisitRegistration;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ExaminationConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    protected function tearDown(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }

    public function test_racing_finalization_and_amendment_keep_one_version_per_revision(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Konkurensi pemeriksaan diuji pada PostgreSQL apotek_test.');
        }
        $owner = User::factory()->create();
        $pharmacy = app(AccessManager::class)->provision($owner->id, ['name' => 'Apotek SOAP Bersamaan']);
        $pharmacy->forceFill(['status' => 'ACTIVE'])->save();
        $master = app(ClinicalMaster::class);
        $patient = $master->savePatient($pharmacy, $owner->id, ['request_key' => (string) Str::uuid(), 'name' => 'Pasien Uji', 'birth_date' => '1990-01-01', 'sex' => 'unspecified']);
        $doctor = $master->saveDoctor($pharmacy, $owner->id, ['request_key' => (string) Str::uuid(), 'name' => 'Dokter Uji']);
        $master->linkDoctor($pharmacy, $owner->id, $doctor, 1, $owner->id);
        $visits = app(VisitRegistration::class);
        $visit = $visits->save($pharmacy, $owner->id, ['request_key' => (string) Str::uuid(), 'patient_id' => $patient, 'doctor_id' => $doctor, 'type' => 'new', 'complaint' => 'Uji']);
        \Illuminate\Support\Facades\DB::table('visits')->where('id', $visit)->update(['status' => 'IN_PROGRESS', 'started_at' => now(), 'revision' => 2]); // Historical visit fixture.
        app(ExaminationRecords::class)->draft($pharmacy, $owner->id, $visit, 0, ['subjective' => 'S', 'assessment' => 'A', 'plan' => 'P']);
        $product = $pharmacy->products()->create(['sku' => 'RX-RACE', 'name' => 'Obat Simulasi', 'brand' => 'Dummy', 'category' => 'Obat', 'base_unit' => 'Tablet', 'purchase_unit' => 'Tablet', 'purchase_conversion' => 1, 'selling_price' => 1000, 'minimum_stock' => 0, 'active' => true, 'requires_prescription' => true]);
        DB::table('stock_movements')->insert(['id' => (string) Str::uuid(), 'pharmacy_id' => $pharmacy->id, 'product_id' => $product->id, 'type' => 'STOCKTAKE_IN', 'quantity' => '20.0000', 'base_unit' => 'Tablet', 'occurred_on' => now()->toDateString(), 'expires_on' => now()->addYear()->toDateString(), 'created_at' => now()]);
        app(PrescriptionRecords::class)->save($pharmacy, $owner->id, $visit, 0, ['valid_until' => now()->addDays(30)->toDateString(), 'items' => [['product_id' => $product->id, 'quantity' => 1, 'dose' => 'Dosis uji', 'frequency' => 'Frekuensi uji', 'instructions' => 'Aturan uji']]]);
        $worker = <<<'PHP'
        require 'vendor/autoload.php';
        $app = require 'bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        if (!$app->environment('testing') || Illuminate\Support\Facades\DB::connection()->getDatabaseName() !== 'apotek_test') {
            throw new RuntimeException('Database worker tidak aman.');
        }
        fwrite(STDOUT,"ready\n"); fflush(STDOUT);
        $pharmacy = App\Models\Pharmacy::findOrFail($argv[1]);
        $records = $app->make(App\Services\ExaminationRecords::class);
        try {
            if ($argv[4] === 'prescription') {
                $app->make(App\Services\PrescriptionRecords::class)->finalize($pharmacy,(int)$argv[2],$argv[3],1);
            } elseif ($argv[4] === 'finalize') {
                $records->finalize($pharmacy,(int)$argv[2],$argv[3],1);
            } else {
                $records->amend($pharmacy,(int)$argv[2],$argv[3],2,['subjective'=>'S koreksi','assessment'=>'A koreksi','plan'=>'P koreksi'],'Alasan koreksi');
            }
            fwrite(STDOUT,"saved\n");
        } catch (Illuminate\Validation\ValidationException $exception) {
            fwrite(STDOUT,"blocked\n");
        }
        PHP;
        foreach (['prescription', 'finalize', 'amend'] as $action) {
            $processes = [];
            DB::beginTransaction();
            try {
                DB::table('pharmacies')->where('id', $pharmacy->id)->lockForUpdate()->first();
                foreach (range(1, 2) as $attempt) {
                    $process = new Process([PHP_BINARY, '-r', $worker, $pharmacy->id, (string) $owner->id, $visit, $action], base_path(), ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => 'apotek_test']);
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
        }
        $this->assertDatabaseCount('examinations', 1);
        $this->assertDatabaseCount('examination_versions', 2);
        $this->assertSame([1, 2], DB::table('examination_versions')->orderBy('version')->pluck('version')->all());
        $this->assertDatabaseHas('examinations', ['visit_id' => $visit, 'status' => 'FINAL', 'revision' => 3, 'current_version' => 2]);
        $this->assertDatabaseHas('visits', ['id' => $visit, 'status' => 'IN_PROGRESS', 'revision' => 2, 'open_patient_key' => $patient]);
        $this->assertDatabaseHas('prescriptions', ['visit_id' => $visit, 'status' => 'FINAL', 'revision' => 2]);
        $this->assertSame('DRAFT', ExaminationRecords::payload(DB::table('prescriptions')->first())['soap_status_at_issue']);
    }
}
