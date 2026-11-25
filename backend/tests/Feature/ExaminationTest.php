<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AccessManager;
use App\Services\ClinicalMaster;
use App\Services\ExaminationRecords;
use App\Services\VisitRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ExaminationTest extends TestCase
{
    use RefreshDatabase;

    private function workspace(): array
    {
        $this->travelTo(now()->setDate(2026, 11, 9)->setTime(8, 0));
        $owner = User::factory()->create();
        $pharmacy = app(AccessManager::class)->provision($owner->id, ['name' => 'Apotek SOAP']);
        $pharmacy->forceFill(['status' => 'ACTIVE'])->save();
        $master = app(ClinicalMaster::class);
        $patient = $master->savePatient($pharmacy, $owner->id, ['request_key' => (string) Str::uuid(), 'name' => 'Pasien SOAP', 'birth_date' => '1995-03-01', 'sex' => 'female', 'allergies' => 'Alergi privat SOAP', 'nik' => '1234567890123456', 'notes' => 'Catatan pribadi']);
        $doctor = $master->saveDoctor($pharmacy, $owner->id, ['request_key' => (string) Str::uuid(), 'name' => 'Dokter SOAP', 'sip_number' => 'SIP-SOAP', 'str_number' => 'STR-SOAP']);
        $master->linkDoctor($pharmacy, $owner->id, $doctor, 1, $owner->id);
        $visit = $this->visit($pharmacy, $owner, $patient, $doctor);
        $this->actingAs($owner);

        return [$owner, $pharmacy, $patient, $doctor, $visit];
    }

    private function visit($pharmacy, $actor, string $patient, string $doctor, bool $start = true): string
    {
        $registration = app(VisitRegistration::class);
        $id = $registration->save($pharmacy, $actor->id, ['request_key' => (string) Str::uuid(), 'patient_id' => $patient, 'doctor_id' => $doctor, 'type' => 'new', 'complaint' => 'Keluhan pendaftaran privat']);
        if ($start) {
            \Illuminate\Support\Facades\DB::table('visits')->where('id', $id)->update(['status' => 'IN_PROGRESS', 'started_at' => now(), 'revision' => 2]); // Historical visit fixture.
        }

        return $id;
    }

    private function payload(array $extra = []): array
    {
        return $extra + ['subjective' => 'Anamnesis privat SOAP', 'objective' => 'Temuan privat SOAP', 'assessment' => 'Diagnosis privat SOAP', 'plan' => 'Rencana privat SOAP', 'followup' => 'Kontrol privat SOAP', 'systolic' => 120, 'diastolic' => 80, 'pulse' => 72, 'temperature' => 36.5, 'weight' => 55.2, 'height' => 160];
    }

    private function draft($pharmacy, string $visit, int $revision = 0, array $payload = []): void
    {
        $this->put(route('examinations.save', [$pharmacy, $visit]), ['revision' => $revision] + ($payload ?: $this->payload()))->assertRedirect()->assertSessionHasNoErrors();
    }

    public function test_combined_page_saves_soap_and_prescription_atomically(): void
    {
        [, $pharmacy, , , $visit] = $this->workspace();
        $product = $pharmacy->products()->create(['name' => 'Obat Resep', 'sku' => 'RX-COMBINED', 'brand' => 'Generik', 'category' => 'Obat', 'base_unit' => 'Tablet', 'purchase_unit' => 'Tablet', 'purchase_conversion' => 1, 'selling_price' => 1000, 'minimum_stock' => 0, 'active' => true, 'requires_prescription' => true]);
        $this->get(route('examinations.show', [$pharmacy, $visit]))->assertOk()->assertSee('Simpan pemeriksaan', false)->assertSee('Tambah obat');
        $data = $this->payload() + ['valid_until' => now()->addDays(30)->toDateString(), 'soap_revision' => 0, 'rx_revision' => 0, 'items' => [['product_id' => $product->id, 'quantity' => 2, 'dose' => '1 tablet', 'frequency' => '2 kali sehari', 'instructions' => 'Sesudah makan']]];
        $url = route('examinations.encounter.save', [$pharmacy, $visit]);
        $this->put($url, $data)->assertSessionHasNoErrors()->assertRedirect(route('examinations.show', [$pharmacy, $visit]));
        $this->assertDatabaseHas('examinations', ['visit_id' => $visit, 'revision' => 1, 'status' => 'DRAFT']);
        $this->assertDatabaseHas('prescriptions', ['visit_id' => $visit, 'revision' => 1, 'status' => 'DRAFT']);
        $data['soap_revision'] = 1;
        $data['rx_revision'] = 1;
        $data['subjective'] = ['invalid'];
        $this->put($url, $data)->assertSessionHasErrors('subjective');
        $this->assertDatabaseHas('prescriptions', ['visit_id' => $visit, 'revision' => 1]);
        $this->get(route('prescriptions.edit', [$pharmacy, $visit]))->assertOk()->assertSee('Pemeriksaan', false);
    }

    public function test_combined_page_can_save_soap_without_prescription_and_blocks_read_only_writes(): void
    {
        [, $pharmacy, , , $visit] = $this->workspace();
        $url = route('examinations.encounter.save', [$pharmacy, $visit]);
        $this->put($url, $this->payload() + ['soap_revision' => 0, 'rx_revision' => 0])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('examinations', ['visit_id' => $visit, 'status' => 'DRAFT']);
        $this->assertDatabaseCount('prescriptions', 0);
        $reader = $this->member($pharmacy, ['examinations.view', 'examinations.view_all']);
        $this->actingAs($reader)->put($url, $this->payload() + ['soap_revision' => 1])->assertForbidden();
    }

    public function test_soap_uses_registration_measurements_and_does_not_trust_submitted_values(): void
    {
        [, $pharmacy, , , $visit] = $this->workspace();
        $intake = ['systolic' => 130, 'diastolic' => 85, 'temperature' => 37.1, 'weight' => 65, 'height' => 170, 'pulse' => null, 'urine_test' => 'Hasil urine pendaftaran'];
        VisitRegistration::query($pharmacy)->where('id', $visit)->update(['intake_encrypted' => Crypt::encryptString(json_encode($intake))]);
        $this->get(route('examinations.edit', [$pharmacy, $visit]))->assertOk()->assertSee('readonly', false)->assertSee('Hasil urine pendaftaran');
        $this->draft($pharmacy, $visit, 0, $this->payload(['urine_test' => 'Hasil palsu']));
        $exam = ExaminationRecords::query($pharmacy)->where('visit_id', $visit)->first();
        $payload = ExaminationRecords::payload($exam);
        $this->assertEquals(130, $payload['systolic']);
        $this->assertEquals(65, $payload['weight']);
        $this->assertSame('Hasil urine pendaftaran', $payload['urine_test']);
        $this->finalize($pharmacy, $visit);
        $snapshot = DB::table('examination_versions')->where('examination_id', $exam->id)->first();
        $this->assertSame('Hasil urine pendaftaran', ExaminationRecords::payload($snapshot)['urine_test']);
    }

    private function finalize($pharmacy, string $visit, int $revision = 1): void
    {
        $this->post(route('examinations.finalize', [$pharmacy, $visit]), ['revision' => $revision])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('visits', ['id' => $visit, 'status' => 'IN_PROGRESS']);
        $owner = $pharmacy->memberships()->whereHas('roles', fn ($query) => $query->where('is_owner', true))->first();
        app(VisitRegistration::class)->complete($pharmacy, $owner->user_id, $visit, (int) DB::table('visits')->find($visit)->revision, null);
    }

    private function member($pharmacy, array $permissions): object
    {
        $user = User::factory()->create();
        $member = $pharmacy->memberships()->create(['user_id' => $user->id, 'active' => true]);
        $role = $pharmacy->roles()->create(['name' => 'Peran '.$user->id, 'permissions' => $permissions]);
        app(AccessManager::class)->syncRoles($member, collect([$role]));

        return $user;
    }

    public function test_draft_finalization_encryption_privacy_and_completed_visit(): void
    {
        [, $pharmacy,$patient,$doctor,$visit] = $this->workspace();
        $this->get(route('examinations.index', $pharmacy))->assertOk();
        $this->get(route('examinations.show', [$pharmacy, $visit]))->assertOk()->assertSee('SOAP belum dicatat')->assertDontSee('1234567890123456');
        $this->get(route('examinations.edit', [$pharmacy, $visit]))->assertOk()->assertSee('Alergi privat SOAP');
        $this->draft($pharmacy, $visit);
        $exam = DB::table('examinations')->where('visit_id', $visit)->first();
        $this->assertSame('DRAFT', $exam->status);
        $this->assertStringNotContainsString('Diagnosis privat SOAP', $exam->payload_encrypted);
        $this->assertSame('Diagnosis privat SOAP', ExaminationRecords::payload($exam)['assessment']);
        $this->assertDatabaseHas('visits', ['id' => $visit, 'status' => 'IN_PROGRESS', 'open_patient_key' => $patient]);
        $response = $this->get(route('examinations.show', [$pharmacy, $visit]))->assertOk()->assertSee('Diagnosis privat SOAP');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->get(route('examinations.index', $pharmacy))->assertOk()->assertDontSee('Diagnosis privat SOAP')->assertDontSee('Anamnesis privat SOAP');
        $this->finalize($pharmacy, $visit);
        $this->assertDatabaseHas('examinations', ['id' => $exam->id, 'status' => 'FINAL', 'revision' => 2, 'current_version' => 1]);
        $this->assertDatabaseHas('visits', ['id' => $visit, 'status' => 'COMPLETED', 'open_patient_key' => null]);
        $this->assertNotNull(DB::table('visits')->find($visit)->completed_at);
        $this->assertDatabaseCount('examination_versions', 1);
        $version = DB::table('examination_versions')->first();
        $this->assertSame('Alergi privat SOAP', Crypt::decryptString($version->allergies_encrypted));
        $this->get(route('examinations.version', [$pharmacy, $visit, $version->id]))->assertOk()->assertSee('SIP-SOAP')->assertSee('Diagnosis privat SOAP');
        $this->get(route('visits.show', [$pharmacy, $visit]))->assertOk()->assertSee('Buka pemeriksaan');
        $this->get(route('patients.show', [$pharmacy, $patient]))->assertOk()->assertSee('Riwayat pemeriksaan / SOAP');
        foreach (['Anamnesis privat SOAP', 'Diagnosis privat SOAP', 'Rencana privat SOAP', 'Alergi privat SOAP'] as $secret) {
            $this->assertStringNotContainsString($secret, DB::table('audit_entries')->pluck('details')->implode(' '));
        }
        // A completed encounter releases the patient's daily open slot.
        $next = $this->visit($pharmacy, auth()->user(), $patient, $doctor, false);
        $this->assertNotSame($visit, $next);
    }

    public function test_final_is_locked_amendments_append_versions_and_reject_stale_revision(): void
    {
        [, $pharmacy,$patient,$doctor,$visit] = $this->workspace();
        $this->draft($pharmacy, $visit);
        $this->finalize($pharmacy, $visit);
        $original = DB::table('examination_versions')->first();
        $this->get(route('examinations.edit', [$pharmacy, $visit]))->assertStatus(422);
        $this->put(route('examinations.save', [$pharmacy, $visit]), ['revision' => 2] + $this->payload())->assertSessionHasErrors('record');
        $this->post(route('examinations.finalize', [$pharmacy, $visit]), ['revision' => 2])->assertSessionHasErrors('record');
        $this->get(route('examinations.amend_form', [$pharmacy, $visit]))->assertOk()->assertSee('Diagnosis privat SOAP');
        $amend = ['revision' => 2, 'reason' => 'Koreksi privat setelah tinjauan'] + $this->payload(['assessment' => 'Diagnosis versi koreksi']);
        $this->post(route('examinations.amend', [$pharmacy, $visit]), $amend)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('examination_versions', 2);
        $this->assertDatabaseHas('examinations', ['visit_id' => $visit, 'revision' => 3, 'current_version' => 2]);
        $this->assertSame($original->payload_encrypted, DB::table('examination_versions')->find($original->id)->payload_encrypted);
        $this->get(route('examinations.show', [$pharmacy, $visit]))->assertOk()->assertSee('Diagnosis versi koreksi')->assertDontSee('Diagnosis privat SOAP');
        $this->get(route('examinations.version', [$pharmacy, $visit, $original->id]))->assertOk()->assertSee('Diagnosis privat SOAP')->assertDontSee('Diagnosis versi koreksi');
        $latest = DB::table('examination_versions')->where('version', 2)->first();
        $this->get(route('examinations.version', [$pharmacy, $visit, $latest->id]))->assertOk()->assertSee('Koreksi privat setelah tinjauan');
        $this->post(route('examinations.amend', [$pharmacy, $visit]), $amend)->assertSessionHasErrors('record');
        $this->post(route('examinations.amend', [$pharmacy, $visit]), array_replace($amend, ['revision' => 3, 'reason' => ' ']))->assertSessionHasErrors('reason');
        $this->assertStringNotContainsString('Koreksi privat setelah tinjauan', DB::table('audit_entries')->pluck('details')->implode(' '));
    }

    public function test_partial_draft_requires_complete_soap_for_final_and_valid_vitals(): void
    {
        [, $pharmacy,$patient,$doctor,$visit] = $this->workspace();
        $this->draft($pharmacy, $visit, 0, ['subjective' => 'Draft awal']);
        $this->post(route('examinations.finalize', [$pharmacy, $visit]), ['revision' => 1])->assertSessionHasErrors(['assessment', 'plan']);
        $this->assertDatabaseCount('examination_versions', 0);
        $this->assertDatabaseHas('visits', ['id' => $visit, 'status' => 'IN_PROGRESS']);
        $this->put(route('examinations.save', [$pharmacy, $visit]), ['revision' => 1] + $this->payload(['systolic' => 120, 'diastolic' => null]))->assertSessionHasErrors('systolic');
        $this->put(route('examinations.save', [$pharmacy, $visit]), ['revision' => 1] + $this->payload(['pulse' => 1.5, 'temperature' => 60, 'height' => -1]))->assertSessionHasErrors(['pulse', 'temperature', 'height']);
        $this->draft($pharmacy, $visit, 1);
        $this->put(route('examinations.save', [$pharmacy, $visit]), ['revision' => 1] + $this->payload())->assertSessionHasErrors('record');
        $this->post(route('examinations.finalize', [$pharmacy, $visit]), ['revision' => 1])->assertSessionHasErrors('record');
        $this->finalize($pharmacy, $visit, 2);
    }

    public function test_read_only_accounts_cannot_write_and_owner_can_write_without_doctor_link(): void
    {
        [$owner,$pharmacy,$patient,$doctor,$visit] = $this->workspace();
        $this->draft($pharmacy, $visit);
        $reader = $this->member($pharmacy, ['examinations.view', 'examinations.view_all']);
        $this->actingAs($reader)->get(route('examinations.show', [$pharmacy, $visit]))->assertOk()->assertDontSee('Alergi privat SOAP')->assertDontSee('name="soap_revision"', false);
        $this->get(route('examinations.edit', [$pharmacy, $visit]))->assertForbidden();
        $this->put(route('examinations.save', [$pharmacy, $visit]), ['revision' => 1] + $this->payload())->assertForbidden();
        $this->post(route('examinations.finalize', [$pharmacy, $visit]), ['revision' => 1])->assertForbidden();
        $this->post(route('examinations.amend', [$pharmacy, $visit]), ['revision' => 1, 'reason' => 'Ubah'] + $this->payload())->assertForbidden();
        $this->actingAs($owner);
        app(ClinicalMaster::class)->linkDoctor($pharmacy, $owner->id, $doctor, 2, null);
        $this->get(route('examinations.show', [$pharmacy, $visit]))->assertOk()->assertSee('name="soap_revision"', false);
        $this->get(route('examinations.edit', [$pharmacy, $visit]))->assertOk();
        $this->draft($pharmacy, $visit, 1);
        $this->finalize($pharmacy, $visit, 2);
        $this->get(route('examinations.amend_form', [$pharmacy, $visit]))->assertOk();
        $this->post(route('examinations.amend', [$pharmacy, $visit]), ['revision' => 3, 'reason' => 'Koreksi oleh Owner'] + $this->payload(['assessment' => 'Penilaian diperbarui Owner']))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('examinations', ['visit_id' => $visit, 'current_version' => 2, 'updated_by' => $owner->id]);
        $this->assertSame([$owner->id, $owner->id], DB::table('examination_versions')->orderBy('version')->pluck('recorded_by')->all());
        $this->assertDatabaseHas('audit_entries', ['action' => 'examination.amended', 'actor_id' => $owner->id, 'subject' => $visit]);
    }

    public function test_owner_can_record_for_another_doctor_without_changing_visit_assignment(): void
    {
        [$owner, $pharmacy, $patient, $doctor, $visit] = $this->workspace();
        $otherDoctor = app(ClinicalMaster::class)->saveDoctor($pharmacy, $owner->id, ['request_key' => (string) Str::uuid(), 'name' => 'Dokter Lain', 'sip_number' => 'SIP-LAIN']);
        $otherPatient = app(ClinicalMaster::class)->savePatient($pharmacy, $owner->id, ['request_key' => (string) Str::uuid(), 'name' => 'Pasien Lain', 'birth_date' => '1992-01-01', 'sex' => 'male']);
        $otherVisit = $this->visit($pharmacy, $owner, $otherPatient, $otherDoctor);
        $this->get(route('examinations.show', [$pharmacy, $otherVisit]))->assertOk()->assertSee('name="soap_revision"', false);
        $this->draft($pharmacy, $otherVisit);
        $this->finalize($pharmacy, $otherVisit);
        $this->assertDatabaseHas('visits', ['id' => $otherVisit, 'doctor_id' => $otherDoctor]);
        $this->assertDatabaseHas('examination_versions', ['doctor_name' => 'Dokter Lain', 'sip_number' => 'SIP-LAIN', 'recorded_by' => $owner->id]);
        $this->assertDatabaseHas('doctors', ['id' => $doctor, 'user_id' => $owner->id]);
        $this->assertDatabaseHas('doctors', ['id' => $otherDoctor, 'user_id' => null]);
    }

    public function test_tenant_isolation_inactive_states_and_unstarted_visits(): void
    {
        [$owner,$pharmacy,$patient,$doctor,$visit] = $this->workspace();
        $this->draft($pharmacy, $visit);
        $other = app(AccessManager::class)->provision($owner->id, ['name' => 'Apotek lain']);
        $other->forceFill(['status' => 'ACTIVE'])->save();
        $this->get(route('examinations.show', [$other, $visit]))->assertNotFound();
        $this->put(route('examinations.save', [$other, $visit]), ['revision' => 1] + $this->payload())->assertNotFound();
        $this->post(route('examinations.finalize', [$other, $visit]), ['revision' => 1])->assertNotFound();
        $pharmacy->forceFill(['status' => 'INACTIVE'])->save();
        $this->get(route('examinations.show', [$pharmacy, $visit]))->assertOk();
        $this->put(route('examinations.save', [$pharmacy, $visit]), ['revision' => 1] + $this->payload())->assertStatus(422);
        $pharmacy->forceFill(['status' => 'ACTIVE'])->save();
        DB::table('doctors')->where('id', $doctor)->update(['active' => false]);
        $this->post(route('examinations.finalize', [$pharmacy, $visit]), ['revision' => 1])->assertForbidden();
        DB::table('doctors')->where('id', $doctor)->update(['active' => true]);
        DB::table('patients')->where('id', $patient)->update(['active' => false]);
        $this->post(route('examinations.finalize', [$pharmacy, $visit]), ['revision' => 1])->assertSessionHasErrors('record');
        DB::table('patients')->where('id', $patient)->update(['active' => true]);
        $this->finalize($pharmacy, $visit);
        $next = $this->visit($pharmacy, $owner, $patient, $doctor, false);
        $this->get(route('examinations.show', [$pharmacy, $next]))->assertStatus(422);
        $this->put(route('examinations.save', [$pharmacy, $next]), ['revision' => 0] + $this->payload())->assertSessionHasErrors('record');
    }

    public function test_doctor_scope_allows_only_assigned_cases_and_final_history_during_active_care(): void
    {
        [$owner,$pharmacy,$patient,$doctor,$visit] = $this->workspace();
        $this->draft($pharmacy, $visit);
        $otherDoctorUser = $this->member($pharmacy, ['examinations.view', 'examinations.update', 'examinations.finalize', 'examinations.amend']);
        $otherDoctor = app(ClinicalMaster::class)->saveDoctor($pharmacy, $owner->id, ['request_key' => (string) Str::uuid(), 'name' => 'Dokter Kedua']);
        app(ClinicalMaster::class)->linkDoctor($pharmacy, $owner->id, $otherDoctor, 1, $otherDoctorUser->id);
        $this->actingAs($otherDoctorUser)->get(route('examinations.show', [$pharmacy, $visit]))->assertNotFound();
        $this->get(route('examinations.index', $pharmacy))->assertOk()->assertViewHas('records', fn ($rows) => $rows->total() === 0);
        $this->put(route('examinations.save', [$pharmacy, $visit]), ['revision' => 1] + $this->payload())->assertForbidden();
        $this->actingAs($owner);
        $this->finalize($pharmacy, $visit);
        $version = DB::table('examination_versions')->first();
        $this->actingAs($otherDoctorUser)->get(route('examinations.version', [$pharmacy, $visit, $version->id]))->assertNotFound();
        $secondVisit = $this->visit($pharmacy, $owner, $patient, $otherDoctor);
        $this->get(route('examinations.show', [$pharmacy, $visit]))->assertOk()->assertSee('Diagnosis privat SOAP');
        $this->get(route('examinations.version', [$pharmacy, $visit, $version->id]))->assertOk();
        $this->get(route('examinations.show', [$pharmacy, $secondVisit]))->assertOk()->assertSee('KJ-');
        $this->draft($pharmacy, $secondVisit);
        $this->finalize($pharmacy, $secondVisit);
        $this->get(route('examinations.show', [$pharmacy, $visit]))->assertNotFound();
        $this->get(route('examinations.show', [$pharmacy, $secondVisit]))->assertOk();
    }

    public function test_version_is_scoped_to_encounter_and_doctor_snapshot_survives_master_changes(): void
    {
        [$owner,$pharmacy,$patient,$doctor,$visit] = $this->workspace();
        $this->draft($pharmacy, $visit);
        $this->finalize($pharmacy, $visit);
        $version = DB::table('examination_versions')->first();
        DB::table('doctors')->where('id', $doctor)->update(['name' => 'Nama dokter berubah', 'sip_number' => 'SIP-BARU']);
        DB::table('patients')->where('id', $patient)->update(['allergies_encrypted' => Crypt::encryptString('Alergi berubah')]);
        $this->get(route('examinations.version', [$pharmacy, $visit, $version->id]))->assertOk()->assertSee('Dokter SOAP')->assertSee('SIP-SOAP')->assertSee('Alergi privat SOAP')->assertDontSee('SIP-BARU')->assertDontSee('Alergi berubah');
        $next = $this->visit($pharmacy, $owner, $patient, $doctor);
        $this->draft($pharmacy, $next);
        $this->finalize($pharmacy, $next);
        $this->get(route('examinations.version', [$pharmacy, $next, $version->id]))->assertNotFound();
        $this->get(route('examinations.index', [$pharmacy, 'status' => 'FINAL', 'sort' => 'date', 'direction' => 'asc', 'q' => 'Pasien SOAP']))->assertOk()->assertViewHas('records', fn ($rows) => $rows->total() === 2);
    }
}
