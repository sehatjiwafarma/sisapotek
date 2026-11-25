<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AccessManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ClinicalMasterTest extends TestCase
{
    use RefreshDatabase;

    private function workspace(): array
    {
        $this->travelTo(now()->setDate(2026, 11, 8)->setTime(12, 0));
        $owner = User::factory()->create();
        $pharmacy = app(AccessManager::class)->provision($owner->id, ['name' => 'Apotek Klinik']);
        $this->actingAs($owner);

        return [$owner, $pharmacy];
    }

    private function patientData(array $extra = []): array
    {
        return $extra + ['request_key' => (string) Str::uuid(), 'name' => 'Pasien Contoh', 'birth_date' => '1990-05-20', 'sex' => 'female', 'phone' => '08123456789',
            'address' => 'Alamat contoh', 'nik' => '0123456789012345', 'allergies' => 'Alergi uji privat', 'notes' => 'Catatan privat uji'];
    }

    private function createPatient($pharmacy, array $extra = []): object
    {
        $response = $this->post(route('patients.store', $pharmacy), $this->patientData($extra))->assertSessionHasNoErrors();

        return DB::table('patients')->find(basename(parse_url($response->headers->get('Location'), PHP_URL_PATH)));
    }

    private function createDoctor($pharmacy, array $extra = []): object
    {
        $response = $this->post(route('doctors.store', $pharmacy), $extra + ['request_key' => (string) Str::uuid(), 'name' => 'Dokter Contoh', 'sip_number' => 'SIP-UJI', 'str_number' => 'STR-UJI', 'specialty' => 'Umum'])->assertSessionHasNoErrors();

        return DB::table('doctors')->find(basename(parse_url($response->headers->get('Location'), PHP_URL_PATH)));
    }

    private function member($pharmacy, array $permissions): object
    {
        $user = User::factory()->create();
        $membership = $pharmacy->memberships()->create(['user_id' => $user->id, 'active' => true]);
        $role = $pharmacy->roles()->create(['name' => 'Role '.$user->id, 'permissions' => $permissions]);
        app(AccessManager::class)->syncRoles($membership, collect([$role]));

        return $user;
    }

    private function signatureFile(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('signature.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/a9sAAAAASUVORK5CYII='));
    }

    public function test_patient_agreement_upload_and_private_view_are_tenant_scoped(): void
    {
        Storage::fake('local');
        [$owner, $pharmacy] = $this->workspace();
        $file = UploadedFile::fake()->createWithContent('perjanjian.pdf', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF");
        $patient = $this->createPatient($pharmacy, ['agreement' => $file]);
        Storage::disk('local')->assertExists($patient->agreement_path);
        $url = route('patients.agreement', [$pharmacy, $patient->id]);
        $this->get(route('patients.show', [$pharmacy, $patient->id]))->assertOk()->assertSee('Lihat surat perjanjian');
        $this->get($url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Content-Type', 'application/pdf');
        $this->assertDatabaseHas('audit_entries', ['action' => 'patient.agreement_viewed', 'subject' => $patient->id]);
        $this->actingAs($this->member($pharmacy, ['patients.view']))->get($url)->assertForbidden();
        $other = app(AccessManager::class)->provision($owner->id, ['name' => 'Apotek Lain']);
        $this->actingAs($owner)->get(route('patients.agreement', [$other, $patient->id]))->assertNotFound();
        $this->get(route('patients.create', $pharmacy))->assertOk()->assertSee('multipart/form-data', false)->assertSee('name="agreement"', false);
    }

    public function test_invalid_agreement_does_not_create_patient_or_file(): void
    {
        Storage::fake('local');
        [, $pharmacy] = $this->workspace();
        foreach ([UploadedFile::fake()->create('file.txt', 1, 'text/plain'), UploadedFile::fake()->create('large.pdf', 5121, 'application/pdf')] as $file) {
            $this->post(route('patients.store', $pharmacy), $this->patientData(['agreement' => $file]))->assertSessionHasErrors('agreement');
        }
        $this->assertDatabaseCount('patients', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_patient_crud_encryption_private_headers_and_audit_without_sensitive_values(): void
    {
        [, $pharmacy] = $this->workspace();
        $this->get(route('patients.create', $pharmacy))->assertOk();
        $patient = $this->createPatient($pharmacy);
        $this->assertStringStartsWith('MR-', $patient->medical_record_number);
        $this->assertNotSame('0123456789012345', $patient->nik_encrypted);
        $this->assertSame('0123456789012345', Crypt::decryptString($patient->nik_encrypted));
        $this->assertSame('Alergi uji privat', Crypt::decryptString($patient->allergies_encrypted));
        $this->get(route('patients.index', [$pharmacy, 'q' => 'Pasien', 'status' => 'active', 'sort' => 'birth']))->assertOk()->assertDontSee('0123456789012345')->assertDontSee('Alergi uji privat');
        $response = $this->get(route('patients.show', [$pharmacy, $patient->id]))->assertOk()->assertSee('Alergi uji privat')->assertSee('0123456789012345');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->get(route('patients.edit', [$pharmacy, $patient->id]))->assertOk();
        $this->put(route('patients.update', [$pharmacy, $patient->id]), $this->patientData(['revision' => 1, 'name' => 'Pasien Diperbarui']))->assertSessionHasNoErrors();
        $this->put(route('patients.update', [$pharmacy, $patient->id]), $this->patientData(['revision' => 1]))->assertSessionHasErrors('record');
        $this->patch(route('patients.status', [$pharmacy, $patient->id]), ['revision' => 2, 'active' => 0])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('patients', ['id' => $patient->id, 'active' => false]);
        $audit = DB::table('audit_entries')->where('subject', $patient->id)->pluck('details')->implode(' ');
        foreach (['0123456789012345', 'Alergi uji privat', 'Catatan privat uji', 'Pasien Diperbarui'] as $secret) {
            $this->assertStringNotContainsString($secret, $audit);
        }
        $this->assertDatabaseHas('audit_entries', ['action' => 'patient.viewed', 'subject' => $patient->id]);
    }

    public function test_patient_sensitive_access_and_updates_preserve_hidden_information(): void
    {
        [$owner,$pharmacy] = $this->workspace();
        $patient = $this->createPatient($pharmacy);
        $user = $this->member($pharmacy, ['patients.view', 'patients.update']);
        $this->actingAs($user)->get(route('patients.show', [$pharmacy, $patient->id]))->assertOk()->assertDontSee('0123456789012345')->assertDontSee('Alergi uji privat')->assertDontSee('Catatan privat uji')
            ->assertViewHas('patient', fn ($record) => ! isset($record->nik_encrypted) && ! isset($record->nik_hash) && ! isset($record->nik) && ! isset($record->allergies));
        $this->get(route('patients.edit', [$pharmacy, $patient->id]))->assertOk()->assertDontSee('name="nik"', false)->assertDontSee('name="allergies"', false);
        $safe = ['revision' => 1, 'name' => 'Identitas baru', 'birth_date' => '1990-05-20', 'sex' => 'female'];
        $this->put(route('patients.update', [$pharmacy, $patient->id]), $safe)->assertSessionHasNoErrors();
        $this->assertSame($patient->allergies_encrypted, DB::table('patients')->find($patient->id)->allergies_encrypted);
        $safe['revision'] = 2;
        $safe['allergies'] = 'Penggantian tanpa izin';
        $this->put(route('patients.update', [$pharmacy, $patient->id]), $safe)->assertForbidden();
        unset($safe['allergies']);
        $safe['notes'] = null;
        $this->put(route('patients.update', [$pharmacy, $patient->id]), $safe)->assertForbidden();
        $this->actingAs($owner)->get(route('patients.show', [$pharmacy, $patient->id]))->assertSee('Alergi uji privat');
    }

    public function test_patient_validation_duplicate_nik_replayed_create_and_tenant_isolation(): void
    {
        [$owner,$pharmacy] = $this->workspace();
        $data = $this->patientData();
        $this->post(route('patients.store', $pharmacy), $data)->assertSessionHasNoErrors();
        $this->post(route('patients.store', $pharmacy), $data)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('patients', 1);
        $this->post(route('patients.store', $pharmacy), $this->patientData())->assertSessionHasErrors('nik');
        foreach ([['birth_date' => '2026-11-09'], ['nik' => '123'], ['sex' => 'invalid']] as $invalid) {
            $this->post(route('patients.store', $pharmacy), $this->patientData($invalid))->assertSessionHasErrors();
        }
        $other = app(AccessManager::class)->provision($owner->id, ['name' => 'Apotek Lain']);
        $patient = DB::table('patients')->first();
        $this->get(route('patients.show', [$other, $patient->id]))->assertNotFound();
        $this->get(route('patients.edit', [$other, $patient->id]))->assertNotFound();
        $this->createPatient($other);
        $this->assertDatabaseCount('patients', 2);
        $viewer = $this->member($pharmacy, []);
        $this->actingAs($viewer)->get(route('patients.index', $pharmacy))->assertForbidden();
        $this->get(route('patients.show', [$pharmacy, $patient->id]))->assertForbidden();
    }

    public function test_doctor_profile_linking_permissions_and_status(): void
    {
        [$owner,$pharmacy] = $this->workspace();
        $doctor = $this->createDoctor($pharmacy);
        $this->get(route('doctors.index', [$pharmacy, 'q' => 'SIP-UJI', 'sort' => 'name']))->assertOk()->assertSee('Dokter Contoh');
        $this->get(route('doctors.edit', [$pharmacy, $doctor->id]))->assertOk();
        $this->get(route('doctors.create', $pharmacy))->assertOk();
        $this->post(route('doctors.account', [$pharmacy, $doctor->id]), ['revision' => 1, 'user_id' => $owner->id])->assertSessionHasNoErrors();
        $another = $this->createDoctor($pharmacy, ['name' => 'Dokter Kedua']);
        $this->post(route('doctors.account', [$pharmacy, $another->id]), ['revision' => 1, 'user_id' => $owner->id])->assertSessionHasErrors('user_id');
        $outsider = User::factory()->create();
        $this->post(route('doctors.account', [$pharmacy, $another->id]), ['revision' => 1, 'user_id' => $outsider->id])->assertSessionHasErrors('user_id');
        $manager = $this->member($pharmacy, ['doctors.view', 'doctors.update']);
        $this->actingAs($manager)->post(route('doctors.account', [$pharmacy, $doctor->id]), ['revision' => 2, 'user_id' => $manager->id])->assertForbidden();
        $this->put(route('doctors.update', [$pharmacy, $doctor->id]), ['revision' => 2, 'name' => 'Dokter Diperbarui', 'sip_number' => 'SIP-UJI', 'str_number' => 'STR-UJI', 'user_id' => $manager->id])->assertSessionHasNoErrors();
        $this->assertEquals($owner->id, DB::table('doctors')->find($doctor->id)->user_id);
        $this->actingAs($owner)->patch(route('doctors.status', [$pharmacy, $doctor->id]), ['revision' => 3, 'active' => 0])->assertSessionHasNoErrors();
        $this->get(route('doctors.index', [$pharmacy, 'status' => 'inactive']))->assertOk()->assertSee('Dokter Diperbarui');
    }

    public function test_signature_private_versioned_future_dated_and_identity_changes_require_new_version(): void
    {
        Storage::fake('local');
        [$owner,$pharmacy] = $this->workspace();
        $doctor = $this->createDoctor($pharmacy);
        $this->post(route('doctors.account', [$pharmacy, $doctor->id]), ['revision' => 1, 'user_id' => $owner->id])->assertSessionHasNoErrors();
        $this->post(route('doctors.signatures', [$pharmacy, $doctor->id]), ['revision' => 2, 'valid_from' => '2026-11-08', 'signature' => $this->signatureFile()])->assertSessionHasNoErrors();
        $first = DB::table('doctor_signatures')->first();
        Storage::disk('local')->assertExists($first->path);
        $this->get(route('doctors.show', [$pharmacy, $doctor->id]))->assertOk()->assertViewHas('currentSignature', fn ($row) => $row->id === $first->id);
        $image = $this->get(route('doctors.signature_image', [$pharmacy, $doctor->id, $first->id]))->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('no-store', $image->headers->get('Cache-Control'));
        $this->post(route('doctors.signatures', [$pharmacy, $doctor->id]), ['revision' => 3, 'valid_from' => '2026-11-10', 'signature' => $this->signatureFile()])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('doctor_signatures', 2);
        $this->get(route('doctors.show', [$pharmacy, $doctor->id]))->assertViewHas('currentSignature', fn ($row) => $row->id === $first->id);
        $this->travel(3)->days();
        $second = DB::table('doctor_signatures')->where('version', 2)->first();
        $this->get(route('doctors.show', [$pharmacy, $doctor->id]))->assertViewHas('currentSignature', fn ($row) => $row->id === $second->id);
        $this->put(route('doctors.update', [$pharmacy, $doctor->id]), ['revision' => 4, 'name' => 'Dokter Identitas Baru', 'sip_number' => 'SIP-BARU', 'str_number' => 'STR-UJI'])->assertSessionHasNoErrors();
        $this->get(route('doctors.show', [$pharmacy, $doctor->id]))->assertOk()->assertViewHas('currentSignature', fn ($signature) => $signature === null);
        $this->get(route('doctors.signature_image', [$pharmacy, $doctor->id, $first->id]))->assertOk();
        Storage::disk('local')->assertExists($first->path);
        $this->post(route('doctors.signatures', [$pharmacy, $doctor->id]), ['revision' => 4, 'valid_from' => '2026-11-11', 'signature' => $this->signatureFile()])->assertSessionHasErrors('record');
        $this->assertDatabaseCount('doctor_signatures', 2);
    }

    public function test_signature_ownership_image_validation_and_cross_tenant_access(): void
    {
        Storage::fake('local');
        [$owner,$pharmacy] = $this->workspace();
        $doctor = $this->createDoctor($pharmacy);
        $user = $this->member($pharmacy, ['doctors.view', 'doctors.signature_update']);
        $this->post(route('doctors.account', [$pharmacy, $doctor->id]), ['revision' => 1, 'user_id' => $user->id])->assertSessionHasNoErrors();
        $stranger = $this->member($pharmacy, ['doctors.view', 'doctors.signature_update']);
        $this->actingAs($stranger)->post(route('doctors.signatures', [$pharmacy, $doctor->id]), ['revision' => 2, 'valid_from' => '2026-11-08', 'signature' => $this->signatureFile()])->assertForbidden();
        $this->actingAs($user);
        $original = $this->signatureFile();
        $oversized = UploadedFile::fake()->createWithContent('large.png', file_get_contents($original->getRealPath()).str_repeat('x', 2 * 1024 * 1024));
        $this->post(route('doctors.signatures', [$pharmacy, $doctor->id]), ['revision' => 2, 'valid_from' => '2026-11-08', 'signature' => $oversized])->assertSessionHasErrors('signature');
        $this->post(route('doctors.signatures', [$pharmacy, $doctor->id]), ['revision' => 2, 'valid_from' => '2026-11-08', 'signature' => UploadedFile::fake()->createWithContent('signature.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>')])->assertSessionHasErrors('signature');
        $this->post(route('doctors.signatures', [$pharmacy, $doctor->id]), ['revision' => 2, 'valid_from' => '2026-11-08', 'signature' => $this->signatureFile()])->assertSessionHasNoErrors();
        $signature = DB::table('doctor_signatures')->first();
        $this->get(route('doctors.signature_image', [$pharmacy, $doctor->id, $signature->id]))->assertOk();
        $viewer = $this->member($pharmacy, ['doctors.view']);
        $this->actingAs($viewer)->get(route('doctors.show', [$pharmacy, $doctor->id]))->assertOk()->assertViewHas('signatures', fn ($rows) => $rows === null)->assertDontSee($signature->id);
        $this->get(route('doctors.signature_image', [$pharmacy, $doctor->id, $signature->id]))->assertForbidden();
        $this->actingAs($owner);
        $other = app(AccessManager::class)->provision($owner->id, ['name' => 'Apotek Lain']);
        $this->get(route('doctors.show', [$other, $doctor->id]))->assertNotFound();
        $this->get(route('doctors.signature_image', [$other, $doctor->id, $signature->id]))->assertNotFound();
        $this->assertDatabaseCount('doctor_signatures', 1);
    }

    public function test_owner_can_upload_for_unlinked_doctor_and_signature_is_used_until_identity_changes(): void
    {
        Storage::fake('local');
        [$owner, $pharmacy] = $this->workspace();
        $doctor = $this->createDoctor($pharmacy);
        $this->get(route('doctors.show', [$pharmacy, $doctor->id]))->assertOk()->assertSee('Unggah tanda tangan dokter');
        $this->post(route('doctors.signatures', [$pharmacy, $doctor->id]), ['revision' => 1, 'valid_from' => '2026-11-08', 'signature' => $this->signatureFile()])->assertSessionHasNoErrors();
        $signature = DB::table('doctor_signatures')->first();
        $this->assertEquals($owner->id, $signature->uploaded_by);
        $this->assertTrue((bool) $signature->uploaded_as_owner);
        $this->get(route('doctors.show', [$pharmacy, $doctor->id]))->assertViewHas('currentSignature', fn ($row) => $row?->id === $signature->id);
        $other = app(AccessManager::class)->provision($owner->id, ['name' => 'Apotek lain']);
        $this->post(route('doctors.signatures', [$other, $doctor->id]), ['revision' => 2, 'valid_from' => '2026-11-08', 'signature' => $this->signatureFile()])->assertNotFound();
        $this->put(route('doctors.update', [$pharmacy, $doctor->id]), ['revision' => 2, 'name' => 'Nama dokter baru'])->assertSessionHasNoErrors();
        $this->get(route('doctors.show', [$pharmacy, $doctor->id]))->assertViewHas('currentSignature', fn ($row) => $row === null);
        $this->get(route('doctors.signature_image', [$pharmacy, $doctor->id, $signature->id]))->assertOk();
    }

    public function test_patient_server_sorting_precedes_pagination_and_filters_are_preserved(): void
    {
        [, $pharmacy] = $this->workspace();
        foreach (range(1, 17) as $index) {
            $this->createPatient($pharmacy, ['name' => sprintf('Pasien %02d', $index), 'nik' => null]);
        }
        $this->get(route('patients.index', [$pharmacy, 'sort' => 'name', 'direction' => 'desc', 'status' => 'active']))->assertOk()->assertViewHas('patients', fn ($rows) => $rows->first()->name === 'Pasien 17' && $rows->last()->name === 'Pasien 03')->assertSee('status=active', false);
        $this->get(route('patients.index', [$pharmacy, 'sort' => 'name', 'direction' => 'desc', 'status' => 'active', 'page' => 2]))->assertOk()->assertViewHas('patients', fn ($rows) => $rows->first()->name === 'Pasien 02');
        $this->get(route('patients.index', [$pharmacy, 'sort' => 'nik_encrypted', 'direction' => 'invalid']))->assertOk()->assertViewHas('patients', fn ($rows) => $rows->first()->name === 'Pasien 01');
    }

    public function test_patient_age_uses_the_pharmacy_calendar_on_a_birthday(): void
    {
        [, $pharmacy] = $this->workspace();
        $this->travelTo(Carbon::parse('2026-11-08 01:00:00', 'Asia/Jakarta'));
        $patient = $this->createPatient($pharmacy, ['birth_date' => '2000-11-08']);
        $this->get(route('patients.show', [$pharmacy, $patient->id]))->assertOk()->assertSee('(26 tahun)');
    }

    public function test_relinked_account_cannot_reuse_or_read_previous_owners_signature(): void
    {
        Storage::fake('local');
        [$owner,$pharmacy] = $this->workspace();
        $doctor = $this->createDoctor($pharmacy);
        $this->post(route('doctors.account', [$pharmacy, $doctor->id]), ['revision' => 1, 'user_id' => $owner->id])->assertSessionHasNoErrors();
        $this->post(route('doctors.signatures', [$pharmacy, $doctor->id]), ['revision' => 2, 'valid_from' => '2026-11-08', 'signature' => $this->signatureFile()])->assertSessionHasNoErrors();
        $old = DB::table('doctor_signatures')->first();
        $replacement = $this->member($pharmacy, ['doctors.view', 'doctors.signature_update']);
        $this->post(route('doctors.account', [$pharmacy, $doctor->id]), ['revision' => 3, 'user_id' => $replacement->id])->assertSessionHasNoErrors();
        $this->actingAs($replacement)->get(route('doctors.show', [$pharmacy, $doctor->id]))->assertOk()->assertViewHas('currentSignature', fn ($signature) => $signature === null)->assertViewHas('signatures', fn ($rows) => $rows->total() === 0);
        $this->get(route('doctors.signature_image', [$pharmacy, $doctor->id, $old->id]))->assertForbidden();
        $this->post(route('doctors.signatures', [$pharmacy, $doctor->id]), ['revision' => 4, 'valid_from' => '2026-11-08', 'signature' => $this->signatureFile()])->assertSessionHasNoErrors();
        $new = DB::table('doctor_signatures')->where('version', 2)->first();
        $this->get(route('doctors.show', [$pharmacy, $doctor->id]))->assertViewHas('currentSignature', fn ($signature) => $signature->id === $new->id);
        $pharmacy->memberships()->where('user_id', $replacement->id)->update(['active' => false]);
        $this->actingAs($owner)->get(route('doctors.show', [$pharmacy, $doctor->id]))->assertViewHas('currentSignature', fn ($signature) => $signature === null);
        $this->get(route('doctors.signature_image', [$pharmacy, $doctor->id, $old->id]))->assertOk();
    }
}
