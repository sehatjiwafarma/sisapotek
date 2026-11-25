<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AccessManager;
use App\Services\ClinicalMaster;
use App\Services\ExaminationRecords;
use App\Services\PrescriptionDispensing;
use App\Services\PrescriptionRecords;
use App\Services\VisitRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class MultiplePrescriptionsTest extends TestCase
{
    use RefreshDatabase;

    private function workspace(): array
    {
        $owner = User::factory()->create();
        $pharmacy = app(AccessManager::class)->provision($owner->id, ['name' => 'Apotek Multi Resep']);
        $pharmacy->forceFill(['status' => 'ACTIVE'])->save();
        $master = app(ClinicalMaster::class);
        $patient = $master->savePatient($pharmacy, $owner->id, ['request_key' => (string) Str::uuid(), 'name' => 'Pasien Beberapa Resep', 'birth_date' => '1990-01-01', 'sex' => 'female']);
        $doctor = $master->saveDoctor($pharmacy, $owner->id, ['request_key' => (string) Str::uuid(), 'name' => 'Dokter Multi', 'sip_number' => 'SIP-MULTI']);
        $visit = app(VisitRegistration::class)->save($pharmacy, $owner->id, ['request_key' => (string) Str::uuid(), 'patient_id' => $patient, 'doctor_id' => $doctor, 'type' => 'new', 'complaint' => 'Keluhan pasien']);
        \Illuminate\Support\Facades\DB::table('visits')->where('id', $visit)->update(['status' => 'IN_PROGRESS', 'started_at' => now(), 'revision' => 2]); // Historical visit fixture.
        $products = [];
        foreach (['Pertama', 'Kedua'] as $name) {
            $product = $pharmacy->products()->create(['name' => 'Obat '.$name, 'sku' => 'RX-'.$name, 'brand' => 'Generik', 'category' => 'Obat', 'base_unit' => 'Tablet', 'purchase_unit' => 'Tablet', 'purchase_conversion' => 1, 'selling_price' => 1000, 'minimum_stock' => 0, 'active' => true, 'requires_prescription' => true]);
            DB::table('stock_movements')->insert(['id' => (string) Str::uuid(), 'pharmacy_id' => $pharmacy->id, 'product_id' => $product->id, 'type' => 'STOCKTAKE_IN', 'quantity' => '100.0000', 'base_unit' => 'Tablet', 'occurred_on' => now()->toDateString(), 'expires_on' => now()->addYear()->toDateString(), 'created_at' => now()]);
            $products[] = $product;
        }
        $this->actingAs($owner);

        return [$owner, $pharmacy, $visit, $products];
    }

    private function data($product): array
    {
        return ['valid_until' => now()->addDays(30)->toDateString(), 'prescription' => 'new', 'rx_request_key' => (string) Str::uuid(), 'revision' => 0, 'items' => [['product_id' => $product->id, 'quantity' => 2, 'dose' => '1 tablet', 'frequency' => '2 kali sehari', 'instructions' => 'Sesudah makan']]];
    }

    public function test_one_examination_has_multiple_independent_prescriptions_and_prints(): void
    {
        [$owner, $pharmacy, $visit, $products] = $this->workspace();
        app(ExaminationRecords::class)->draft($pharmacy, $owner->id, $visit, 0, ['subjective' => 'S', 'assessment' => 'A', 'plan' => 'P']);
        app(ExaminationRecords::class)->finalize($pharmacy, $owner->id, $visit, 1);
        $save = route('examinations.encounter.save', [$pharmacy, $visit]);
        foreach ($products as $product) {
            $data = $this->data($product);
            $data['rx_revision'] = 0;
            $this->put($save, $data)->assertSessionHasNoErrors();
            $this->put($save, $data)->assertSessionHasNoErrors(); // Retries must not create another prescription.
        }
        $records = PrescriptionRecords::query($pharmacy)->where('visit_id', $visit)->orderBy('created_at')->orderBy('id')->get();
        $this->assertCount(2, $records);
        $this->assertNotSame($records[0]->number, $records[1]->number);
        $this->get(route('examinations.show', [$pharmacy, $visit]))->assertOk()->assertSee($records[0]->number)->assertSee($records[1]->number)->assertSee('Buat resep baru');
        foreach ($records as $index => $rx) {
            $url = route('prescriptions.finalize', [$pharmacy, $visit, 'prescription' => $rx->id]);
            $this->post($url, ['revision' => 1])->assertSessionHasNoErrors();
            $payload = ExaminationRecords::payload(PrescriptionRecords::query($pharmacy)->where('id', $rx->id)->first());
            $ownName = $payload['items'][0]['name'];
            $otherName = $ownName === 'Obat Pertama' ? 'Obat Kedua' : 'Obat Pertama';
            $this->get(route('prescriptions.print', [$pharmacy, $visit, 'prescription' => $rx->id]))->assertOk()->assertSee($rx->number)->assertSee($ownName)->assertDontSee($otherName);
            $this->get(route('dispensing.show', [$pharmacy, $rx->id]))->assertOk()->assertSee($rx->number);
            $this->post($url, ['revision' => 1])->assertSessionHasErrors('record');
        }
        $this->assertDatabaseCount('examinations', 1);
        $this->assertDatabaseCount('examination_versions', 1);
        $this->get(route('visits.index', $pharmacy))->assertOk()->assertViewHas('visits', fn ($rows) => $rows->total() === 1 && $rows->first()->prescription_status === 'FINAL');
        $this->get(route('visits.show', [$pharmacy, $visit]))->assertOk();
        DB::table('prescriptions')->where('id', $records[0]->id)->update(['finalized_at' => now()->subMinutes(5)]);
        DB::table('prescriptions')->where('id', $records[1]->id)->update(['finalized_at' => now()]);
        $this->get(route('dispensing.index', $pharmacy))->assertOk()->assertSeeInOrder([$records[1]->number, $records[0]->number]);
        $this->get(route('dispensing.index', [$pharmacy, 'sort' => 'date', 'direction' => 'asc']))->assertOk()->assertSeeInOrder([$records[0]->number, $records[1]->number]);
    }

    public function test_selected_draft_permissions_scope_and_revision_are_enforced(): void
    {
        [$owner, $pharmacy, $visit, $products] = $this->workspace();
        $service = app(PrescriptionRecords::class);
        $first = $service->save($pharmacy, $owner->id, $visit, 0, $this->data($products[0]));
        $second = $service->save($pharmacy, $owner->id, $visit, 0, $this->data($products[1]));
        $service->finalize($pharmacy, $owner->id, $visit, 1, $first);
        $this->get(route('visits.index', $pharmacy))->assertOk()->assertViewHas('visits', fn ($rows) => $rows->first()->prescription_status === 'DRAFT');
        $before = PrescriptionRecords::query($pharmacy)->where('id', $first)->first()->payload_encrypted;
        $this->put(route('prescriptions.save', [$pharmacy, $visit]), ['valid_until' => now()->addDays(30)->toDateString(), 'prescription' => $second, 'revision' => 1, 'items' => $this->data($products[1])['items'], 'notes' => 'Catatan resep kedua'])->assertSessionHasNoErrors();
        $this->assertSame($before, PrescriptionRecords::query($pharmacy)->where('id', $first)->first()->payload_encrypted);
        $this->post(route('prescriptions.finalize', [$pharmacy, $visit]), ['prescription' => $second, 'revision' => 1])->assertSessionHasErrors('record');
        $otherPatient = app(ClinicalMaster::class)->savePatient($pharmacy, $owner->id, ['request_key' => (string) Str::uuid(), 'name' => 'Pasien lain', 'birth_date' => '1990-01-01', 'sex' => 'male']);
        $otherVisit = app(VisitRegistration::class)->save($pharmacy, $owner->id, ['request_key' => (string) Str::uuid(), 'patient_id' => $otherPatient, 'doctor_id' => DB::table('visits')->find($visit)->doctor_id, 'type' => 'followup', 'complaint' => 'Kontrol']);
        \Illuminate\Support\Facades\DB::table('visits')->where('id', $otherVisit)->update(['status' => 'IN_PROGRESS', 'started_at' => now(), 'revision' => 2]); // Historical visit fixture.
        $this->get(route('prescriptions.print', [$pharmacy, $otherVisit, 'prescription' => $first]))->assertNotFound();
        $this->put(route('prescriptions.save', [$pharmacy, $otherVisit]), ['prescription' => $second, 'revision' => 2, 'items' => $this->data($products[0])['items']])->assertNotFound();
        $staff = User::factory()->create();
        $role = $pharmacy->roles()->create(['name' => 'Pembaca resep', 'permissions' => ['prescriptions.view', 'prescriptions.view_all'], 'is_owner' => false]);
        $member = $pharmacy->memberships()->create(['user_id' => $staff->id, 'active' => true]);
        app(AccessManager::class)->syncRoles($member, collect([$role]));
        $this->actingAs($staff)->get(route('prescriptions.show', [$pharmacy, $visit, 'prescription' => $second]))->assertOk()->assertSee('Catatan resep kedua');
        $this->put(route('prescriptions.save', [$pharmacy, $visit]), $this->data($products[0]))->assertForbidden();
    }

    public function test_redemption_of_one_prescription_does_not_reduce_the_other_prescription(): void
    {
        [$owner, $pharmacy, $visit, $products] = $this->workspace();
        $service = app(PrescriptionRecords::class);
        $ids = [];
        foreach ([2, 3] as $quantity) {
            $data = $this->data($products[0]);
            $data['items'][0]['quantity'] = $quantity;
            $id = $service->save($pharmacy, $owner->id, $visit, 0, $data);
            $service->finalize($pharmacy, $owner->id, $visit, 1, $id);
            $ids[] = $id;
        }
        $this->post(route('dispensing.store', [$pharmacy, $ids[0]]), ['request_key' => (string) Str::uuid(), 'verified' => 1, 'recipient_type' => 'self', 'items' => [['product_id' => $products[0]->id, 'quantity' => 2]]])->assertSessionHasNoErrors();
        $sale = DB::table('sales')->where('prescription_id', $ids[0])->first();
        $this->post(route('sales.pay', [$pharmacy, $sale->id]), ['revision' => $sale->revision, 'payment_method' => 'cash', 'amount_received' => $sale->total])->assertSessionHasNoErrors();
        $first = PrescriptionRecords::query($pharmacy)->where('id', $ids[0])->first();
        $second = PrescriptionRecords::query($pharmacy)->where('id', $ids[1])->first();
        $this->assertSame(0, PrescriptionDispensing::remaining($pharmacy, $first)[0]['remaining']);
        $this->assertSame(30000, PrescriptionDispensing::remaining($pharmacy, $second)[0]['remaining']);
        $this->get(route('examinations.show', [$pharmacy, $visit, 'prescription' => $ids[1]]))->assertOk()->assertViewHas('prescription', fn ($rx) => $rx->id === $ids[1]);
    }

    public function test_custom_permission_restricts_all_prescriptions_and_is_rechecked_at_payment_and_handover(): void
    {
        [$owner, $pharmacy, $visit, $products] = $this->workspace();
        $records = app(PrescriptionRecords::class);
        $first = $records->save($pharmacy, $owner->id, $visit, 0, $this->data($products[0]));
        $records->finalize($pharmacy, $owner->id, $visit, 1, $first);
        $staff = User::factory()->create();
        $permissions = ['dispensing.view', 'dispensing.verify', 'dispensing.prepare', 'dispensing.handover', 'sales.create', 'sales.view', 'sales.pay'];
        $role = $pharmacy->roles()->create(['name' => 'Farmasi Custom', 'permissions' => $permissions, 'is_owner' => false]);
        $member = $pharmacy->memberships()->create(['user_id' => $staff->id, 'active' => true]);
        app(AccessManager::class)->syncRoles($member, collect([$role]));
        $input = ['request_key' => (string) Str::uuid(), 'verified' => 1, 'recipient_type' => 'self', 'items' => [['product_id' => $products[0]->id, 'quantity' => 2]]];
        $this->actingAs($staff)->post(route('dispensing.store', [$pharmacy, $first]), $input)->assertSessionHasNoErrors();
        $sale = DB::table('sales')->where('prescription_id', $first)->first();
        $this->assertNotNull($sale);
        // A second draft already makes the examination a multiple-prescription examination.
        $second = $records->save($pharmacy, $owner->id, $visit, 0, $this->data($products[0]));
        $this->get(route('dispensing.show', [$pharmacy, $first]))->assertOk()->assertViewHas('multipleRestricted', true)->assertViewHas('canPrepare', false);
        $payment = ['revision' => $sale->revision, 'payment_method' => 'cash', 'amount_received' => $sale->total];
        $this->post(route('sales.pay', [$pharmacy, $sale->id]), $payment)->assertForbidden();
        $this->get(route('sales.show', [$pharmacy, $sale->id]))->assertOk()
            ->assertSee('Pembayaran penebusan memerlukan izin')
            ->assertDontSee('data-payment-form', false);
        $records->finalize($pharmacy, $owner->id, $visit, 1, $second);
        foreach ([$first, $second] as $id) {
            $this->post(route('dispensing.store', [$pharmacy, $id]), array_replace($input, ['request_key' => (string) Str::uuid()]))->assertForbidden();
        }
        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseHas('sales', ['id' => $sale->id, 'status' => 'DRAFT']);
        $role->update(['permissions' => [...$permissions, 'dispensing.multiple_prescriptions']]);
        $this->get(route('dispensing.show', [$pharmacy, $first]))->assertOk()->assertViewHas('multipleRestricted', false)->assertViewHas('canPrepare', true);
        $this->post(route('sales.pay', [$pharmacy, $sale->id]), $payment)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('sales', ['id' => $sale->id, 'status' => 'PAID']);
        $role->update(['permissions' => $permissions]);
        $this->post(route('dispensing.handover', [$pharmacy, $sale->id]))->assertForbidden();
        $this->assertDatabaseHas('sales', ['id' => $sale->id, 'handed_over_at' => null]);
        $role->update(['permissions' => [...$permissions, 'dispensing.multiple_prescriptions']]);
        $this->post(route('dispensing.handover', [$pharmacy, $sale->id]))->assertSessionHasNoErrors();
        $this->assertNotNull(DB::table('sales')->where('id', $sale->id)->value('handed_over_at'));
        $this->post(route('dispensing.store', [$pharmacy, $second]), array_replace($input, ['request_key' => (string) Str::uuid()]))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('sales', 2);
    }
}
