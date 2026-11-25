<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AccessManager;
use App\Services\ClinicalMaster;
use App\Services\ServiceBilling;
use App\Services\VisitRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class VisitRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_service_payment_is_required_before_start_and_medicine_cannot_be_combined(): void
    {
        [$owner, $pharmacy, $patient, $doctor] = $this->workspace();
        $tariff = app(ServiceBilling::class)->tariff($pharmacy, $owner->id, ['name' => 'Konsultasi', 'price' => 50000, 'active' => 1]);
        $visit = $this->register($pharmacy, $this->data($patient, $doctor) + ['services_present' => 1, 'service_ids' => [$tariff]]);
        $start = ['revision' => 1, 'action' => 'start'];
        $this->post(route('visits.transition', [$pharmacy, $visit->id]), $start)->assertSessionHasErrors('record');
        $input = ['request_key' => (string) Str::uuid(), 'visit_id' => $visit->id, 'items' => [['tariff_id' => $tariff, 'quantity' => 1]]];
        $this->post(route('billing.store', $pharmacy), $input + ['sale_id' => (string) Str::uuid()])->assertSessionHasErrors('sale_id');
        $bill = DB::table('service_bills')->where('visit_id', $visit->id)->value('id');
        $this->assertNotNull($bill);
        $this->assertDatabaseHas('service_bills', ['id' => $bill, 'visit_id' => $visit->id, 'status' => 'DRAFT']);
        $this->get(route('billing.index', [$pharmacy, 'q' => 'Pasien Kunjungan']))
            ->assertOk()->assertSee('Pasien Kunjungan')->assertSee('Menunggu pembayaran')
            ->assertSee(route('billing.show', [$pharmacy, $bill]))->assertSee('Bayar');
        $this->get(route('visits.show', [$pharmacy, $visit->id]))->assertDontSee('Buat tagihan layanan');
        $this->get(route('cashier.index', $pharmacy))->assertOk()->assertSee('Layanan')->assertSee('Pasien Kunjungan');
        $this->post(route('visits.transition', [$pharmacy, $visit->id]), ['revision' => 1, 'action' => 'cancel', 'reason' => 'Batal'])->assertSessionHasErrors('record');
        $this->post(route('visits.transition', [$pharmacy, $visit->id]), $start)->assertSessionHasErrors('record');
        $this->post(route('billing.action', [$pharmacy, $bill, 'pay']), ['revision' => 1, 'confirmed' => 1, 'payment_method' => 'cash', 'amount_received' => 50000])->assertSessionHasNoErrors();
        $this->post(route('visits.transition', [$pharmacy, $visit->id]), $start)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('visits', ['id' => $visit->id, 'status' => 'IN_PROGRESS']);
    }

    private function workspace(): array
    {
        $this->travelTo(now()->setDate(2026, 11, 8)->setTime(12, 0));
        $owner = User::factory()->create();
        $pharmacy = app(AccessManager::class)->provision($owner->id, ['name' => 'Apotek Kunjungan']);
        $pharmacy->forceFill(['status' => 'ACTIVE'])->save();
        $this->actingAs($owner);
        $master = app(ClinicalMaster::class);
        $patient = $master->savePatient($pharmacy, $owner->id, ['request_key' => (string) Str::uuid(), 'name' => 'Pasien Kunjungan', 'birth_date' => '1990-01-01', 'sex' => 'female', 'allergies' => 'Alergi privat']);
        $doctor = $master->saveDoctor($pharmacy, $owner->id, ['request_key' => (string) Str::uuid(), 'name' => 'Dokter Kunjungan']);

        return [$owner, $pharmacy, $patient, $doctor];
    }

    private function data(string $patient, string $doctor): array
    {
        return ['request_key' => (string) Str::uuid(), 'patient_id' => $patient, 'doctor_id' => $doctor, 'type' => 'new', 'complaint' => 'Keluhan privat kunjungan'];
    }

    private function register($pharmacy, array $data): object
    {
        $response = $this->post(route('visits.store', $pharmacy), $data)->assertRedirect()->assertSessionHasNoErrors();

        return DB::table('visits')->find(basename(parse_url($response->headers->get('Location'), PHP_URL_PATH)));
    }

    public function test_service_selection_uses_master_tariff_and_preserves_price_for_billing(): void
    {
        [$owner, $pharmacy, $patient, $doctor] = $this->workspace();
        $id = app(ServiceBilling::class)->tariff($pharmacy, $owner->id, ['name' => 'Konsultasi umum', 'price' => '50000', 'active' => 1]);
        $visit = $this->register($pharmacy, $this->data($patient, $doctor) + ['services_present' => 1, 'service_ids' => [$id]]);
        $this->assertEquals(50000, VisitRegistration::services($visit)[0]['unit_price']);
        $this->get(route('visits.show', [$pharmacy, $visit->id]))->assertOk()->assertSee('Konsultasi umum')->assertSee('50.000,00');
        DB::table('service_tariffs')->where('id', $id)->update(['price' => 75000, 'active' => false]);
        $this->put(route('visits.update', [$pharmacy, $visit->id]), ['revision' => 1, 'doctor_id' => $doctor, 'type' => 'new', 'complaint' => 'Keluhan diperbarui', 'services_present' => 1, 'service_ids' => [$id]])->assertSessionHasNoErrors();
        $this->assertEquals(50000, VisitRegistration::services(DB::table('visits')->find($visit->id))[0]['unit_price']);
        $bill = DB::table('service_bills')->where('visit_id', $visit->id)->value('id');
        $this->assertDatabaseHas('service_bill_items', ['service_bill_id' => $bill, 'unit_price' => 50000, 'total' => 50000]);
    }

    public function test_registration_rejects_missing_inactive_foreign_and_duplicate_services(): void
    {
        [$owner, $pharmacy, $patient, $doctor] = $this->workspace();
        $id = app(ServiceBilling::class)->tariff($pharmacy, $owner->id, ['name' => 'Layanan nonaktif', 'price' => 10000, 'active' => 0]);
        $data = $this->data($patient, $doctor) + ['services_present' => 1];
        $this->post(route('visits.store', $pharmacy), $data)->assertSessionHasErrors('service_ids');
        $this->post(route('visits.store', $pharmacy), $data + ['service_ids' => [$id]])->assertSessionHasErrors('services');
        $this->post(route('visits.store', $pharmacy), $data + ['service_ids' => [$id, $id]])->assertSessionHasErrors('service_ids.0');
        $other = app(AccessManager::class)->provision($owner->id, ['name' => 'Apotek lain']);
        $other->forceFill(['status' => 'ACTIVE'])->save();
        $foreign = app(ServiceBilling::class)->tariff($other, $owner->id, ['name' => 'Layanan lain', 'price' => 20000, 'active' => 1]);
        $this->post(route('visits.store', $pharmacy), $data + ['service_ids' => [$foreign]])->assertSessionHasErrors('services');
        $this->assertDatabaseCount('visits', 0);
    }

    public function test_registration_stores_encrypted_initial_examination_and_validates_blood_pressure(): void
    {
        [, $pharmacy, $patient, $doctor] = $this->workspace();
        $data = $this->data($patient, $doctor) + ['intake_present' => 1, 'systolic' => 120, 'diastolic' => 80, 'temperature' => 36.5, 'weight' => 60, 'height' => 165, 'urine_test' => 'Hasil urine uji privat'];
        $invalid = $data;
        unset($invalid['diastolic']);
        $this->post(route('visits.store', $pharmacy), $invalid)->assertSessionHasErrors('systolic');
        $visit = $this->register($pharmacy, $data);
        $intake = VisitRegistration::intake($visit);
        $this->assertEquals(120, $intake['systolic']);
        $this->assertSame('Hasil urine uji privat', $intake['urine_test']);
        $this->assertStringNotContainsString('Hasil urine uji privat', $visit->intake_encrypted);
        $this->get(route('visits.edit', [$pharmacy, $visit->id]))->assertOk()->assertSee('Hasil urine uji privat');
        $this->get(route('visits.index', $pharmacy))->assertOk()->assertDontSee('Hasil urine uji privat');
    }

    public function test_registration_idempotency_encryption_queue_and_private_views(): void
    {
        [, $pharmacy,$patient,$doctor] = $this->workspace();
        $data = $this->data($patient, $doctor);
        $this->get(route('visits.create', $pharmacy))->assertOk()->assertSee('search-select.js')->assertSee('visits.js');
        $visit = $this->register($pharmacy, $data);
        $this->assertSame(1, $visit->queue_number);
        $this->assertSame('KJ-20261108-0001', $visit->number);
        $this->assertSame('Keluhan privat kunjungan', Crypt::decryptString($visit->complaint_encrypted));
        $this->register($pharmacy, $data);
        $this->assertDatabaseCount('visits', 1);
        $this->post(route('visits.store', $pharmacy), $this->data($patient, $doctor))->assertSessionHasErrors('patient_id');
        $this->get(route('visits.index', $pharmacy))->assertOk()->assertSee($visit->number)->assertDontSee('Keluhan privat kunjungan')->assertDontSee('Alergi privat');
        $response = $this->get(route('visits.show', [$pharmacy, $visit->id]))->assertOk()->assertSee('Keluhan privat kunjungan')->assertSee('Alergi privat');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->get(route('patients.show', [$pharmacy, $patient]))->assertOk()->assertSee($visit->number);
        $this->get(route('visits.patients', $pharmacy))->assertOk()->assertJsonFragment(['id' => $patient])->assertDontSee('Alergi privat');
        $audit = DB::table('audit_entries')->where('subject', $visit->id)->pluck('details')->implode(' ');
        $this->assertStringNotContainsString('Keluhan privat kunjungan', $audit);
        $this->assertStringNotContainsString('Alergi privat', $audit);
    }

    public function test_edit_start_revision_and_locked_lifecycle(): void
    {
        [$owner, $pharmacy,$patient,$doctor] = $this->workspace();
        $visit = $this->register($pharmacy, $this->data($patient, $doctor));
        $this->get(route('visits.edit', [$pharmacy, $visit->id]))->assertOk();
        $edit = ['revision' => 1, 'doctor_id' => $doctor, 'type' => 'followup', 'complaint' => 'Keluhan diperbarui'];
        $this->put(route('visits.update', [$pharmacy, $visit->id]), $edit)->assertSessionHasNoErrors();
        $this->put(route('visits.update', [$pharmacy, $visit->id]), $edit)->assertSessionHasErrors('record');
        $this->post(route('visits.transition', [$pharmacy, $visit->id]), ['revision' => 1, 'action' => 'start'])->assertSessionHasErrors('record');
        $tariff = app(ServiceBilling::class)->tariff($pharmacy, $owner->id, ['name' => 'Konsultasi', 'price' => 10000, 'active' => 1]);
        $bill = app(ServiceBilling::class)->create($pharmacy, $owner->id, ['request_key' => (string) Str::uuid(), 'visit_id' => $visit->id, 'items' => [['tariff_id' => $tariff, 'quantity' => 1]]]);
        app(ServiceBilling::class)->pay($pharmacy, $owner->id, $bill, ['revision' => 1, 'confirmed' => 1, 'payment_method' => 'cash', 'amount_received' => 10000]);
        $this->post(route('visits.transition', [$pharmacy, $visit->id]), ['revision' => 2, 'action' => 'start'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('visits', ['id' => $visit->id, 'status' => 'IN_PROGRESS', 'queue_number' => 1, 'revision' => 3]);
        $this->get(route('visits.edit', [$pharmacy, $visit->id]))->assertStatus(422);
        $this->put(route('visits.update', [$pharmacy, $visit->id]), array_replace($edit, ['revision' => 3]))->assertSessionHasErrors('record');
        $this->post(route('visits.transition', [$pharmacy, $visit->id]), ['revision' => 3, 'action' => 'cancel', 'reason' => 'Alasan'])->assertSessionHasErrors('record');
        $this->post(route('visits.transition', [$pharmacy, $visit->id]), ['revision' => 3, 'action' => 'complete'])->assertSessionHasErrors('action');
    }

    public function test_cancellation_requires_reason_releases_patient_without_recycling_queue(): void
    {
        [, $pharmacy,$patient,$doctor] = $this->workspace();
        $visit = $this->register($pharmacy, $this->data($patient, $doctor));
        $this->post(route('visits.transition', [$pharmacy, $visit->id]), ['revision' => 1, 'action' => 'cancel'])->assertSessionHasErrors('reason');
        $this->post(route('visits.transition', [$pharmacy, $visit->id]), ['revision' => 1, 'action' => 'cancel', 'reason' => 'Pasien batal privat'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('visits', ['id' => $visit->id, 'status' => 'CANCELLED', 'open_patient_key' => null]);
        $next = $this->register($pharmacy, $this->data($patient, $doctor));
        $this->assertSame(2, $next->queue_number);
        $this->get(route('visits.show', [$pharmacy, $visit->id]))->assertOk()->assertSee('Pasien batal privat');
        $this->assertStringNotContainsString('Pasien batal privat', DB::table('audit_entries')->pluck('details')->implode(' '));
    }

    public function test_tenant_boundaries_inactive_masters_and_pharmacy(): void
    {
        [$owner,$pharmacy,$patient,$doctor] = $this->workspace();
        $visit = $this->register($pharmacy, $this->data($patient, $doctor));
        $other = app(AccessManager::class)->provision($owner->id, ['name' => 'Apotek lain']);
        $other->forceFill(['status' => 'ACTIVE'])->save();
        $this->get(route('visits.show', [$other, $visit->id]))->assertNotFound();
        $this->get(route('visits.edit', [$other, $visit->id]))->assertNotFound();
        $this->post(route('visits.store', $other), $this->data($patient, $doctor))->assertSessionHasErrors('patient_id');
        $this->post(route('visits.transition', [$other, $visit->id]), ['revision' => 1, 'action' => 'start'])->assertNotFound();
        DB::table('doctors')->where('id', $doctor)->update(['active' => false]);
        $this->post(route('visits.transition', [$pharmacy, $visit->id]), ['revision' => 1, 'action' => 'start'])->assertSessionHasErrors('record');
        $this->post(route('visits.store', $pharmacy), $this->data($patient, $doctor))->assertSessionHasErrors('doctor_id');
        DB::table('doctors')->where('id', $doctor)->update(['active' => true]);
        DB::table('patients')->where('id', $patient)->update(['active' => false]);
        $this->post(route('visits.store', $pharmacy), $this->data($patient, $doctor))->assertSessionHasErrors('patient_id');
        $pharmacy->forceFill(['status' => 'INACTIVE'])->save();
        $this->get(route('visits.create', $pharmacy))->assertStatus(422);
        $this->post(route('visits.store', $pharmacy), $this->data($patient, $doctor))->assertStatus(422);
        $this->post(route('visits.transition', [$pharmacy, $visit->id]), ['revision' => 1, 'action' => 'cancel', 'reason' => 'Batal'])->assertSessionHasNoErrors();
    }

    public function test_read_only_permissions_hide_allergies_and_forbid_mutations(): void
    {
        [, $pharmacy,$patient,$doctor] = $this->workspace();
        $visit = $this->register($pharmacy, $this->data($patient, $doctor));
        $user = User::factory()->create();
        $membership = $pharmacy->memberships()->create(['user_id' => $user->id, 'active' => true]);
        $role = $pharmacy->roles()->create(['name' => 'Pembaca kunjungan', 'permissions' => ['visits.view']]);
        app(AccessManager::class)->syncRoles($membership, collect([$role]));
        $this->actingAs($user)->get(route('visits.show', [$pharmacy, $visit->id]))->assertOk()->assertDontSee('Alergi privat')->assertDontSee('Mulai layanan');
        $this->get(route('visits.create', $pharmacy))->assertForbidden();
        $this->get(route('visits.patients', $pharmacy))->assertForbidden();
        foreach (['start', 'cancel'] as $action) {
            $this->post(route('visits.transition', [$pharmacy, $visit->id]), ['revision' => 1, 'action' => $action, 'reason' => 'Batal'])->assertForbidden();
        }
        $this->post(route('visits.store', $pharmacy), $this->data($patient, $doctor))->assertForbidden();
        $this->put(route('visits.update', [$pharmacy, $visit->id]), ['revision' => 1, 'doctor_id' => $doctor, 'type' => 'new', 'complaint' => 'Ubah'])->assertForbidden();
    }

    public function test_daily_queue_uses_pharmacy_local_date_and_historical_names_are_stable(): void
    {
        [, $pharmacy,$patient,$doctor] = $this->workspace();
        $this->travelTo(Carbon::parse('2026-11-08 16:59:00', 'UTC'));
        $visit = $this->register($pharmacy, $this->data($patient, $doctor));
        $this->assertSame('2026-11-08', substr($visit->visit_date, 0, 10));
        DB::table('patients')->where('id', $patient)->update(['name' => 'Nama baru']);
        DB::table('doctors')->where('id', $doctor)->update(['name' => 'Dokter baru']);
        $this->get(route('visits.show', [$pharmacy, $visit->id]))->assertOk()->assertSee('Pasien Kunjungan')->assertSee('Dokter Kunjungan');
        $this->travelTo(Carbon::parse('2026-11-08 17:01:00', 'UTC'));
        $next = $this->register($pharmacy, $this->data($patient, $doctor));
        $this->assertSame('2026-11-09', substr($next->visit_date, 0, 10));
        $this->assertSame(1, $next->queue_number);
        $this->get(route('visits.index', $pharmacy))->assertOk()->assertSee($next->number)->assertDontSee($visit->number);
        $this->get(route('visits.index', [$pharmacy, 'date' => '2026-11-08']))->assertOk()->assertSee($visit->number)->assertDontSee($next->number);
    }

    public function test_sorting_filters_pagination_and_validation(): void
    {
        [$owner, $pharmacy, $patient, $doctor] = $this->workspace();
        $registration = app(VisitRegistration::class);
        foreach (range(1, 17) as $index) {
            $id = $registration->save($pharmacy, $owner->id, $this->data($patient, $doctor));
            $registration->transition($pharmacy, $owner->id, $id, ['revision' => 1, 'action' => 'cancel', 'reason' => 'Uji riwayat']);
        }
        $this->get(route('visits.index', [$pharmacy, 'sort' => 'queue', 'direction' => 'desc', 'status' => 'CANCELLED', 'doctor' => $doctor]))
            ->assertOk()->assertViewHas('visits', fn ($rows) => $rows->total() === 17 && $rows->count() === 15 && $rows->first()->queue_number === 17);
        $this->get(route('visits.index', [$pharmacy, 'sort' => 'queue', 'direction' => 'desc', 'page' => 2]))
            ->assertOk()->assertViewHas('visits', fn ($rows) => $rows->first()->queue_number === 2);
        $this->get(route('visits.index', [$pharmacy, 'q' => '0017']))->assertOk()->assertViewHas('visits', fn ($rows) => $rows->total() === 1);
        $this->get(route('patients.show', [$pharmacy, $patient, 'visits_sort' => 'number', 'visits_direction' => 'desc']))
            ->assertOk()->assertViewHas('visits', fn ($rows) => $rows->total() === 17 && $rows->count() === 10 && str_ends_with($rows->first()->number, '0017'));
        $this->post(route('visits.store', $pharmacy), array_replace($this->data($patient, $doctor), ['complaint' => ' ', 'type' => 'invalid']))->assertSessionHasErrors(['complaint', 'type']);
        $this->get(route('visits.index', [$pharmacy, 'date' => 'invalid']))->assertSessionHasErrors('date');
        $this->put(route('visits.update', [$pharmacy, $id]), ['revision' => 2, 'patient_id' => $patient, 'doctor_id' => $doctor, 'type' => 'new', 'complaint' => 'Uji'])->assertSessionHasErrors('patient_id');
    }

    public function test_timezone_is_read_from_pharmacy_settings(): void
    {
        [, $pharmacy,$patient,$doctor] = $this->workspace();
        $pharmacy->settings()->update(['timezone' => 'Asia/Jayapura']);
        $this->travelTo(Carbon::parse('2026-11-08 15:01:00', 'UTC'));
        $visit = $this->register($pharmacy, $this->data($patient, $doctor));
        $this->assertSame('2026-11-09', substr($visit->visit_date, 0, 10));
        $this->get(route('visits.index', $pharmacy))->assertOk()->assertViewHas('date', '2026-11-09')->assertSee($visit->number);
        $this->get(route('visits.show', [$pharmacy, $visit->id]))->assertOk()->assertSee('Asia/Jayapura')->assertSee('09/11/2026 00:01');
    }
}
