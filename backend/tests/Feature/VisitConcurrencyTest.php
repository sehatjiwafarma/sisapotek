<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AccessManager;
use App\Services\ClinicalMaster;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class VisitConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    protected function tearDown(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }

    public function test_concurrent_registrations_allocate_unique_queues_and_block_duplicate_patients(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Konkurensi antrean diuji pada PostgreSQL apotek_test.');
        }
        $owner = User::factory()->create();
        $pharmacy = app(AccessManager::class)->provision($owner->id, ['name' => 'Apotek Antrean Bersamaan']);
        $pharmacy->forceFill(['status' => 'ACTIVE'])->save();
        $master = app(ClinicalMaster::class);
        $doctor = $master->saveDoctor($pharmacy, $owner->id, ['request_key' => (string) Str::uuid(), 'name' => 'Dokter Uji']);
        $patients = [];
        foreach (range(1, 3) as $index) {
            $patients[] = $master->savePatient($pharmacy, $owner->id, ['request_key' => (string) Str::uuid(), 'name' => 'Pasien '.$index, 'birth_date' => '1990-01-01', 'sex' => 'unspecified']);
        }
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
            $app->make(App\Services\VisitRegistration::class)->save($pharmacy,(int)$argv[2],[
                'patient_id'=>$argv[3], 'doctor_id'=>$argv[4], 'request_key'=>(string)Illuminate\Support\Str::uuid(),
                'type'=>'new', 'complaint'=>'Keluhan uji']);
            fwrite(STDOUT,"saved\n");
        } catch (Illuminate\Validation\ValidationException $exception) {
            fwrite(STDOUT,"blocked\n");
        }
        PHP;
        // Different patients race for daily numbering; the same patient then races for one open slot.
        foreach ([[$patients[0], $patients[1]], [$patients[2], $patients[2]]] as $pair) {
            $processes = [];
            DB::beginTransaction();
            try {
                DB::table('pharmacies')->where('id', $pharmacy->id)->lockForUpdate()->first();
                foreach ($pair as $patient) {
                    $process = new Process([PHP_BINARY, '-r', $worker, $pharmacy->id, (string) $owner->id, $patient, $doctor], base_path(), ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => 'apotek_test']);
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
        $this->assertDatabaseCount('visits', 3);
        $this->assertSame([1, 2, 3], DB::table('visits')->orderBy('queue_number')->pluck('queue_number')->all());
        $this->assertSame(1, DB::table('visits')->where('patient_id', $patients[2])->count());
    }
}
