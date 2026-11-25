<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AccessManager;
use App\Services\ClinicalMaster;
use App\Services\ExaminationRecords;
use App\Services\PharmacyMaster;
use App\Services\PrescriptionRecords;
use App\Services\SoapReminders;
use App\Services\VisitRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SoapRemindersTest extends TestCase
{
    use RefreshDatabase;

    private function setupVisit(): array
    {
        $owner = User::factory()->create();
        $pharmacy = app(AccessManager::class)->provision($owner->id, ['name' => 'Apotek SOAP']);
        $pharmacy->forceFill(['status' => 'ACTIVE'])->save();
        $master = app(ClinicalMaster::class);
        $patient = $master->savePatient($pharmacy, $owner->id, ['request_key' => (string) Str::uuid(), 'name' => 'Pasien Privat', 'birth_date' => '1990-01-01', 'sex' => 'female']);
        $doctor = $master->saveDoctor($pharmacy, $owner->id, ['request_key' => (string) Str::uuid(), 'name' => 'Dokter Uji']);
        $visit = app(VisitRegistration::class)->save($pharmacy, $owner->id, ['request_key' => (string) Str::uuid(), 'patient_id' => $patient, 'doctor_id' => $doctor, 'type' => 'new', 'complaint' => 'Keluhan']);
        \Illuminate\Support\Facades\DB::table('visits')->where('id', $visit)->update(['status' => 'IN_PROGRESS', 'started_at' => now(), 'revision' => 2]); // Historical visit fixture.
        $product = $pharmacy->products()->create(['sku' => 'SOAP-RX', 'name' => 'Obat Uji', 'brand' => 'Generik', 'category' => 'Obat', 'base_unit' => 'Tablet', 'purchase_unit' => 'Tablet', 'purchase_conversion' => 1, 'selling_price' => 1000, 'minimum_stock' => 0, 'requires_prescription' => true, 'active' => true]);
        DB::table('stock_movements')->insert(['id' => (string) Str::uuid(), 'pharmacy_id' => $pharmacy->id, 'product_id' => $product->id, 'type' => 'STOCKTAKE_IN', 'quantity' => 100, 'base_unit' => 'Tablet', 'occurred_on' => now()->toDateString(), 'created_at' => now()]);

        return [$owner, $pharmacy, $doctor, $visit, $product];
    }

    private function prescription($owner, $pharmacy, $visit, $product, bool $final = true): string
    {
        $service = app(PrescriptionRecords::class);
        $id = $service->save($pharmacy, $owner->id, $visit, 0, ['valid_until' => now()->addDays(30)->toDateString(), 'prescription' => 'new', 'rx_request_key' => (string) Str::uuid(), 'items' => [['product_id' => $product->id, 'quantity' => 1, 'dose' => '1 tablet', 'frequency' => '1 kali sehari', 'instructions' => 'Sesudah makan']]]);
        if ($final) {
            $service->finalize($pharmacy, $owner->id, $visit, 1, $id);
        }

        return $id;
    }

    public function test_task_uses_first_saved_prescription_and_resolves_when_examination_is_saved(): void
    {
        $this->travelTo(now()->startOfSecond());
        [$owner, $pharmacy, $doctor, $visit, $product] = $this->setupVisit();
        $member = $pharmacy->memberships()->with('roles')->where('user_id', $owner->id)->first();
        $draft = $this->prescription($owner, $pharmacy, $visit, $product, false);
        $this->assertSame(1, SoapReminders::summary($pharmacy, $member)['total']);
        DB::table('prescriptions')->where('id', $draft)->update(['ready_at' => now()->subHours(25)]);
        $this->prescription($owner, $pharmacy, $visit, $product);
        $summary = SoapReminders::summary($pharmacy, $member);
        $this->assertSame(1, $summary['total']);
        $this->assertSame(1, $summary['overdue']);
        $this->assertTrue(SoapReminders::overdue($pharmacy, $summary['items'][0]->first_prescription_at));
        $this->actingAs($owner)->get(route('soap-tasks.index', $pharmacy))->assertOk()->assertSee('SOAP belum diisi')->assertSee('SOAP terlambat');
        $this->get(route('soap-tasks.index', $pharmacy))->assertViewHas('tasks', fn ($tasks) => $tasks->total() === 1);
        $this->get(route('pharmacy.home', $pharmacy))->assertOk()->assertSee('TUGAS DOKUMENTASI')->assertHeader('Cache-Control', 'max-age=0, no-store, private');
        app(ExaminationRecords::class)->draft($pharmacy, $owner->id, $visit, 0, ['subjective' => 'S', 'assessment' => 'A', 'plan' => 'P']);
        $this->get(route('examinations.index', $pharmacy))->assertOk()->assertSee('Tersimpan')->assertDontSee('SOAP terlambat');
        $this->assertSame(0, SoapReminders::summary($pharmacy, $member)['total']);
        $this->get(route('pharmacy.home', $pharmacy))->assertOk()->assertDontSee('TUGAS DOKUMENTASI');
    }

    public function test_only_assigned_doctor_and_owner_receive_tasks_even_when_other_doctor_can_view_all(): void
    {
        [$owner, $pharmacy, $doctor, $visit, $product] = $this->setupVisit();
        $this->prescription($owner, $pharmacy, $visit, $product);
        $role = $pharmacy->roles()->create(['name' => 'Dokter', 'permissions' => ['examinations.view', 'examinations.view_all'], 'is_owner' => false]);
        $users = [];
        foreach ([1, 2] as $index) {
            $user = User::factory()->create();
            $member = $pharmacy->memberships()->create(['user_id' => $user->id, 'active' => true]);
            app(AccessManager::class)->syncRoles($member, collect([$role]));
            $id = $index === 1 ? $doctor : app(ClinicalMaster::class)->saveDoctor($pharmacy, $owner->id, ['request_key' => (string) Str::uuid(), 'name' => 'Dokter Lain']);
            DB::table('doctors')->where('id', $id)->update(['user_id' => $user->id]);
            $this->assertSame($index === 1 ? 1 : 0, SoapReminders::summary($pharmacy, $member)['total']);
            $this->actingAs($user)->get(route('soap-tasks.index', $pharmacy))->assertOk()->assertViewHas('tasks', fn ($tasks) => $tasks->total() === ($index === 1 ? 1 : 0));
            $users[] = $user;
        }
        $this->actingAs($users[1])->get(route('pharmacy.home', $pharmacy))->assertOk()->assertDontSee('TUGAS DOKUMENTASI');
        $outsider = User::factory()->create();
        $this->actingAs($outsider)->get(route('soap-tasks.index', $pharmacy))->assertForbidden();
        $other = app(AccessManager::class)->provision($owner->id, ['name' => 'Apotek Lain']);
        $this->actingAs($owner)->get(route('soap-tasks.index', $other))->assertOk()->assertViewHas('tasks', fn ($tasks) => $tasks->isEmpty());
        DB::table('visits')->where('id', $visit)->update(['status' => 'CANCELLED']);
        $member = $pharmacy->memberships()->with('roles')->where('user_id', $owner->id)->first();
        $this->assertSame(0, SoapReminders::summary($pharmacy, $member)['total']);
    }

    public function test_deadline_is_configurable_and_exact_boundary_is_overdue(): void
    {
        $this->travelTo(now()->startOfSecond());
        [$owner, $pharmacy, $doctor, $visit, $product] = $this->setupVisit();
        $id = $this->prescription($owner, $pharmacy, $visit, $product);
        DB::table('prescriptions')->where('id', $id)->update(['finalized_at' => now()->subHours(2)]);
        $member = $pharmacy->memberships()->with('roles')->where('user_id', $owner->id)->first();
        $this->assertSame(0, SoapReminders::summary($pharmacy, $member)['overdue']);
        app(PharmacyMaster::class)->saveSettings($pharmacy, $owner, array_replace($pharmacy->settings->toArray(), ['soap_reminder_hours' => 2]));
        $pharmacy->unsetRelation('settings');
        $this->assertSame(1, SoapReminders::summary($pharmacy, $member)['overdue']);
        $this->actingAs($owner)->get(route('soap-tasks.index', [$pharmacy, 'status' => 'overdue']))->assertOk()->assertViewHas('tasks', fn ($tasks) => $tasks->total() === 1);
        $this->patch(route('master.settings.save', $pharmacy), array_replace($pharmacy->settings->toArray(), ['soap_reminder_hours' => 0]))->assertSessionHasErrors('soap_reminder_hours');
    }
}
