<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AccessManager;
use App\Services\ClinicalMaster;
use App\Services\VisitRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class DeferredClinicalWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function workspace(): array
    {
        // Separate temporary files from other test processes running against another database.
        Storage::set('local', Storage::fake('deferred-clinical-'.getmypid()));
        $owner = User::factory()->create();
        $pharmacy = app(AccessManager::class)->provision($owner->id, ['name' => 'Apotek Layanan Online']);
        $pharmacy->forceFill(['status' => 'ACTIVE'])->save();
        $master = app(ClinicalMaster::class);
        $patient = $master->savePatient($pharmacy, $owner->id, ['request_key' => (string) Str::uuid(), 'name' => 'Pasien Online', 'birth_date' => '1990-01-01', 'sex' => 'female', 'allergies' => 'Alergi privat online']);
        $doctor = $master->saveDoctor($pharmacy, $owner->id, ['request_key' => (string) Str::uuid(), 'name' => 'Dokter Online']);
        $visits = app(VisitRegistration::class);
        $visit = $visits->save($pharmacy, $owner->id, ['request_key' => (string) Str::uuid(), 'patient_id' => $patient, 'doctor_id' => $doctor, 'type' => 'new', 'complaint' => 'Konsultasi online']);
        \Illuminate\Support\Facades\DB::table('visits')->where('id', $visit)->update(['status' => 'IN_PROGRESS', 'started_at' => now(), 'revision' => 2]); // Historical visit fixture.
        $product = $pharmacy->products()->create(['sku' => 'OBAT-ONLINE', 'name' => 'Obat Simulasi', 'brand' => 'Dummy', 'category' => 'Obat', 'base_unit' => 'Tablet', 'purchase_unit' => 'Tablet', 'purchase_conversion' => 1, 'selling_price' => 1000, 'minimum_stock' => 0, 'active' => true, 'requires_prescription' => true]);
        DB::table('stock_movements')->insert(['id' => (string) Str::uuid(), 'pharmacy_id' => $pharmacy->id, 'product_id' => $product->id, 'type' => 'STOCKTAKE_IN', 'quantity' => '20.0000', 'base_unit' => 'Tablet', 'occurred_on' => now()->toDateString(), 'expires_on' => now()->addYear()->toDateString(), 'created_at' => now()]);
        $this->actingAs($owner);

        return [$owner, $pharmacy, $visit, $product];
    }

    private function image(string $name = 'soap.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/a9sAAAAASUVORK5CYII='));
    }

    private function recipe($product): array
    {
        return ['valid_until' => now()->addDays(30)->toDateString(), 'items' => [['product_id' => $product->id, 'quantity' => 5, 'dose' => 'Dosis simulasi', 'frequency' => 'Frekuensi simulasi', 'instructions' => 'Aturan pakai privat simulasi']], 'notes' => 'Catatan resep privat'];
    }

    public function test_print_qr_uses_finalized_doctor_and_patient_identity(): void
    {
        [, $pharmacy, $visit, $product] = $this->workspace();
        $record = DB::table('visits')->find($visit);
        DB::table('doctors')->where('id', $record->doctor_id)->update(['sip_number' => 'SIP-UJI-001']);
        $this->put(route('prescriptions.save', [$pharmacy, $visit]), ['revision' => 0] + $this->recipe($product))->assertSessionHasNoErrors();
        $this->post(route('prescriptions.finalize', [$pharmacy, $visit]), ['revision' => 1])->assertSessionHasNoErrors();
        $rx = DB::table('prescriptions')->where('visit_id', $visit)->first();
        $payload = \App\Services\ExaminationRecords::payload($rx);
        $text = \App\Services\PrescriptionPrint::scanText($rx, $payload);
        foreach (['SIP-UJI-001', 'Dokter Online', $rx->number, 'Pasien Online', '1990-01-01', 'Perempuan', $record->medical_record_number] as $value) {
            $this->assertStringContainsString($value, $text);
        }
        $this->assertStringNotContainsString('Alergi privat', $text);
        DB::table('patients')->where('id', $record->patient_id)->update(['name' => 'Nama terbaru', 'birth_date' => '2000-01-01']);
        DB::table('doctors')->where('id', $record->doctor_id)->update(['sip_number' => 'SIP-TERBARU']);
        $response = $this->get(route('prescriptions.print', [$pharmacy, $visit]))->assertOk()
            ->assertSee('Pasien Online')->assertSee('SIP-UJI-001')->assertSee('data:image/svg+xml;base64,', false)
            ->assertDontSee('SIP-TERBARU')->assertDontSee('Nama terbaru');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        unset($payload['patient']['birth_date'], $payload['patient']['sex']);
        $this->assertStringContainsString('Tanggal Lahir: Belum tercatat', \App\Services\PrescriptionPrint::scanText($rx, $payload));
    }

    public function test_online_images_are_encrypted_private_and_immutable_across_versions(): void
    {
        [, $pharmacy,$visit] = $this->workspace();
        $image = $this->image();
        $bytes = $image->getContent();
        $this->get(route('examinations.edit', [$pharmacy, $visit]))->assertOk()->assertSee('multipart/form-data')->assertSee('examination-images.js');
        $this->put(route('examinations.save', [$pharmacy, $visit]), ['revision' => 0, 'documentation_mode' => 'IMAGE', 'image_notes' => 'Keterangan gambar privat', 'images' => [$image]])->assertSessionHasNoErrors();
        $record = DB::table('examination_images')->first();
        $this->assertNotSame($bytes, Storage::disk('local')->get($record->path));
        $this->assertSame($bytes, Crypt::decryptString(Storage::disk('local')->get($record->path)));
        $this->get(route('examinations.image', [$pharmacy, $visit, $record->id]))->assertOk()->assertHeader('Content-Type', 'image/png')->assertContent($bytes);
        $this->post(route('examinations.finalize', [$pharmacy, $visit]), ['revision' => 1])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('visits', ['id' => $visit, 'status' => 'IN_PROGRESS']);
        $oldVersion = DB::table('examination_versions')->first();
        $this->get(route('examinations.version', [$pharmacy, $visit, $oldVersion->id]))->assertOk()->assertSee($record->id);
        $this->post(route('examinations.amend', [$pharmacy, $visit]), ['revision' => 2, 'documentation_mode' => 'FORM', 'reason' => 'Koreksi belum lengkap', 'images' => [$this->image('rollback.png')]])->assertSessionHasErrors(['subjective', 'assessment', 'plan']);
        $this->assertDatabaseCount('examination_images', 1);
        $this->assertCount(1, Storage::disk('local')->allFiles('clinical-images'));
        $this->post(route('examinations.amend', [$pharmacy, $visit]), ['revision' => 2, 'documentation_mode' => 'IMAGE', 'reason' => 'Halaman koreksi privat', 'images' => [$this->image('koreksi.png')]])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('examination_images', 2);
        $this->assertDatabaseCount('examination_version_images', 2);
        $this->get(route('examinations.image', [$pharmacy, $visit, $record->id]))->assertOk();
        $this->get(route('examinations.version', [$pharmacy, $visit, $oldVersion->id]))->assertOk()->assertSee($record->id);
        $this->assertSame($oldVersion->payload_encrypted, DB::table('examination_versions')->find($oldVersion->id)->payload_encrypted);
        $response = $this->get(route('examinations.show', [$pharmacy, $visit]))->assertOk();
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('Keterangan gambar privat', DB::table('audit_entries')->pluck('details')->implode(' '));
        $this->assertStringNotContainsString('Halaman koreksi privat', DB::table('audit_entries')->pluck('details')->implode(' '));
    }

    public function test_service_can_close_with_reason_and_soap_is_completed_later(): void
    {
        [, $pharmacy,$visit] = $this->workspace();
        $this->post(route('visits.complete', [$pharmacy, $visit]), ['revision' => 2])->assertSessionHasErrors('pending_reason');
        $this->post(route('visits.complete', [$pharmacy, $visit]), ['revision' => 2, 'pending_reason' => 'Dokumen online menyusul privat'])->assertSessionHasNoErrors();
        $closed = DB::table('visits')->find($visit);
        $this->assertSame('COMPLETED', $closed->status);
        $this->assertNull($closed->open_patient_key);
        $this->assertSame('Dokumen online menyusul privat', Crypt::decryptString($closed->completion_note_encrypted));
        $this->get(route('examinations.edit', [$pharmacy, $visit]))->assertOk();
        $this->put(route('examinations.save', [$pharmacy, $visit]), ['revision' => 0, 'subjective' => 'S', 'assessment' => 'A', 'plan' => 'P'])->assertSessionHasNoErrors();
        $this->post(route('examinations.finalize', [$pharmacy, $visit]), ['revision' => 1])->assertSessionHasNoErrors();
        $this->assertSame($closed->completed_at, DB::table('visits')->find($visit)->completed_at);
        $this->assertDatabaseHas('visits', ['id' => $visit, 'revision' => 3]);
        $this->get(route('visits.show', [$pharmacy, $visit]))->assertOk()->assertSee('Dokumen online menyusul privat')->assertSee('Dokumentasi SOAP');
        $this->get(route('visits.index', $pharmacy))->assertOk()->assertSee('SOAP')->assertSee('RESEP');
        $this->post(route('visits.complete', [$pharmacy, $visit]), ['revision' => 3])->assertSessionHasErrors('record');
    }

    public function test_recipe_can_be_final_before_any_soap_without_stock_or_visit_side_effects(): void
    {
        [, $pharmacy,$visit,$product] = $this->workspace();
        $this->get(route('prescriptions.show', [$pharmacy, $visit]))->assertOk()->assertSee('SOAP &amp; resep', false);
        $this->get(route('prescriptions.edit', [$pharmacy, $visit]))->assertOk()->assertSee('Obat Simulasi');
        $this->put(route('prescriptions.save', [$pharmacy, $visit]), ['revision' => 0] + $this->recipe($product))->assertSessionHasNoErrors();
        $rx = DB::table('prescriptions')->first();
        $this->assertStringNotContainsString('Aturan pakai privat', $rx->payload_encrypted);
        $this->get(route('prescriptions.show', [$pharmacy, $visit]))->assertOk()->assertSee('Aturan pakai privat simulasi');
        $this->post(route('prescriptions.finalize', [$pharmacy, $visit]), ['revision' => 1])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('examinations', 0);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertSame(0, DB::table('stock_movements')->where('type', 'SALE')->count());
        $this->assertDatabaseHas('visits', ['id' => $visit, 'status' => 'IN_PROGRESS', 'revision' => 2]);
        $this->assertDatabaseHas('prescriptions', ['id' => $rx->id, 'status' => 'FINAL', 'revision' => 2]);
        $this->get(route('prescriptions.print', [$pharmacy, $visit]))->assertOk()->assertSee($rx->number)->assertSee('Belum ada gambar tanda tangan');
        $this->put(route('prescriptions.save', [$pharmacy, $visit]), ['revision' => 2] + $this->recipe($product))->assertSessionHasErrors('record');
        $this->post(route('prescriptions.finalize', [$pharmacy, $visit]), ['revision' => 2])->assertSessionHasErrors('record');
        $this->put(route('examinations.save', [$pharmacy, $visit]), ['revision' => 0, 'documentation_mode' => 'IMAGE', 'images' => [$this->image()]])->assertSessionHasNoErrors();
        $this->post(route('examinations.finalize', [$pharmacy, $visit]), ['revision' => 1])->assertSessionHasNoErrors();
        $this->post(route('visits.complete', [$pharmacy, $visit]), ['revision' => 2])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('visits', ['id' => $visit, 'status' => 'COMPLETED']);
    }

    public function test_upload_and_recipe_validation_stale_requests_and_rollback(): void
    {
        [, $pharmacy,$visit,$product] = $this->workspace();
        $this->put(route('examinations.save', [$pharmacy, $visit]), ['revision' => 0, 'documentation_mode' => 'IMAGE'])->assertSessionHasNoErrors();
        $this->post(route('examinations.finalize', [$pharmacy, $visit]), ['revision' => 1])->assertSessionHasErrors('images');
        $this->put(route('examinations.save', [$pharmacy, $visit]), ['revision' => 1, 'documentation_mode' => 'IMAGE', 'images' => [UploadedFile::fake()->createWithContent('bad.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>')]])->assertSessionHasErrors('images.0');
        $this->put(route('examinations.save', [$pharmacy, $visit]), ['revision' => 0, 'documentation_mode' => 'IMAGE', 'images' => [$this->image()]])->assertSessionHasErrors('record');
        $this->assertDatabaseCount('examination_images', 0);
        $this->assertCount(0, Storage::disk('local')->allFiles('clinical-images'));
        $this->put(route('examinations.save', [$pharmacy, $visit]), ['revision' => 1, 'documentation_mode' => 'IMAGE', 'image_ids' => [(string) Str::uuid()]])->assertSessionHasErrors('image_ids');
        $this->put(route('prescriptions.save', [$pharmacy, $visit]), ['valid_until' => now()->addDays(30)->toDateString(), 'revision' => 0, 'items' => [['product_id' => $product->id, 'quantity' => 1]]])->assertSessionHasNoErrors();
        $this->get(route('prescriptions.show', [$pharmacy, $visit]))->assertOk()->assertSee('Draft');
        $this->post(route('prescriptions.finalize', [$pharmacy, $visit]), ['revision' => 1])->assertSessionHasErrors(['items.0.dose', 'items.0.frequency', 'items.0.instructions']);
        $this->put(route('prescriptions.save', [$pharmacy, $visit]), ['revision' => 0] + $this->recipe($product))->assertSessionHasErrors('record');
        $this->post(route('visits.complete', [$pharmacy, $visit]), ['revision' => 2])->assertSessionHasErrors('pending_reason');
        $this->put(route('prescriptions.save', [$pharmacy, $visit]), ['revision' => 1, 'items' => [['product_id' => $product->id, 'quantity' => -1]]])->assertSessionHasErrors('items.0.quantity');
    }

    public function test_clinical_files_and_prescriptions_respect_tenants_and_read_only_roles(): void
    {
        [$owner,$pharmacy,$visit,$product] = $this->workspace();
        $this->put(route('examinations.save', [$pharmacy, $visit]), ['revision' => 0, 'documentation_mode' => 'IMAGE', 'images' => [$this->image()]])->assertSessionHasNoErrors();
        $file = DB::table('examination_images')->first();
        $other = app(AccessManager::class)->provision($owner->id, ['name' => 'Apotek Terpisah']);
        $other->forceFill(['status' => 'ACTIVE'])->save();
        $this->get(route('examinations.image', [$other, $visit, $file->id]))->assertNotFound();
        $this->get(route('prescriptions.show', [$other, $visit]))->assertNotFound();
        $this->put(route('prescriptions.save', [$other, $visit]), ['revision' => 0] + $this->recipe($product))->assertNotFound();
        $reader = User::factory()->create();
        $membership = $pharmacy->memberships()->create(['user_id' => $reader->id, 'active' => true]);
        $role = $pharmacy->roles()->create(['name' => 'Pembaca dokumen', 'permissions' => ['examinations.view', 'examinations.view_all', 'prescriptions.view', 'prescriptions.view_all', 'visits.view']]);
        app(AccessManager::class)->syncRoles($membership, collect([$role]));
        $this->actingAs($reader)->get(route('examinations.image', [$pharmacy, $visit, $file->id]))->assertOk();
        $this->get(route('prescriptions.show', [$pharmacy, $visit]))->assertOk()->assertDontSee('Buat draft resep')->assertDontSee('Alergi privat online');
        $this->get(route('prescriptions.edit', [$pharmacy, $visit]))->assertForbidden();
        $this->put(route('prescriptions.save', [$pharmacy, $visit]), ['revision' => 0] + $this->recipe($product))->assertForbidden();
        $this->post(route('visits.complete', [$pharmacy, $visit]), ['revision' => 2, 'pending_reason' => 'Coba'])->assertForbidden();
    }

    public function test_another_doctor_cannot_write_prescription_and_missing_images_cannot_be_finalized(): void
    {
        [$owner, $pharmacy, $visit, $product] = $this->workspace();
        $this->put(route('examinations.save', [$pharmacy, $visit]), ['revision' => 0, 'documentation_mode' => 'IMAGE', 'images' => [$this->image()]])->assertSessionHasNoErrors();
        $image = DB::table('examination_images')->first();
        Storage::disk('local')->delete($image->path);
        $this->post(route('examinations.finalize', [$pharmacy, $visit]), ['revision' => 1])->assertSessionHasErrors('images');
        $this->assertDatabaseCount('examination_versions', 0);
        $user = User::factory()->create();
        $membership = $pharmacy->memberships()->create(['user_id' => $user->id, 'active' => true]);
        $role = $pharmacy->roles()->create(['name' => 'Dokter tidak ditugaskan', 'permissions' => ['prescriptions.view', 'prescriptions.view_all', 'prescriptions.create', 'prescriptions.update', 'prescriptions.finalize']]);
        app(AccessManager::class)->syncRoles($membership, collect([$role]));
        $doctor = app(ClinicalMaster::class)->saveDoctor($pharmacy, $owner->id, ['request_key' => (string) Str::uuid(), 'name' => 'Dokter Berbeda']);
        app(ClinicalMaster::class)->linkDoctor($pharmacy, $owner->id, $doctor, 1, $user->id);
        $this->actingAs($user)->put(route('prescriptions.save', [$pharmacy, $visit]), ['revision' => 0] + $this->recipe($product))->assertForbidden();
        $this->assertDatabaseCount('prescriptions', 0);
        $this->actingAs($owner)->put(route('prescriptions.save', [$pharmacy, $visit]), ['revision' => 0] + $this->recipe($product))->assertSessionHasNoErrors();
        $this->actingAs($user)->post(route('prescriptions.finalize', [$pharmacy, $visit]), ['revision' => 1])->assertForbidden();
    }

    public function test_recipe_rechecks_units_and_keeps_the_final_signature_snapshot(): void
    {
        [$owner, $pharmacy, $visit, $product] = $this->workspace();
        $doctorId = DB::table('visits')->find($visit)->doctor_id;
        $master = app(ClinicalMaster::class);
        $master->linkDoctor($pharmacy, $owner->id, $doctorId, 1, $owner->id);
        $master->signature($pharmacy, $owner->id, $doctorId, 2, $this->image(), now($pharmacy->settings->timezone)->toDateString());
        $this->put(route('prescriptions.save', [$pharmacy, $visit]), ['revision' => 0] + $this->recipe($product))->assertSessionHasNoErrors();
        $product->update(['base_unit' => 'Kapsul']);
        $this->post(route('prescriptions.finalize', [$pharmacy, $visit]), ['revision' => 1])->assertSessionHasErrors('items');
        $this->put(route('prescriptions.save', [$pharmacy, $visit]), ['revision' => 1] + $this->recipe($product))->assertSessionHasNoErrors();
        $this->post(route('prescriptions.finalize', [$pharmacy, $visit]), ['revision' => 2])->assertSessionHasNoErrors();
        $rx = DB::table('prescriptions')->first();
        $signature = DB::table('doctor_signatures')->first();
        $this->assertSame($signature->id, $rx->signature_id);
        $this->get(route('prescriptions.signature', [$pharmacy, $visit]))->assertOk();
        $master->saveDoctor($pharmacy, $owner->id, ['revision' => 3, 'name' => 'Nama dokter diperbarui'], $doctorId);
        $this->get(route('prescriptions.signature', [$pharmacy, $visit]))->assertOk();
        $this->get(route('prescriptions.print', [$pharmacy, $visit]))->assertOk()->assertSee('Dokter Online')->assertDontSee('Nama dokter diperbarui');
    }
}
