<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AccessManager;
use App\Services\ClinicalMaster;
use App\Services\ExaminationRecords;
use App\Services\VisitRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ConsultationEvidenceTest extends TestCase
{
    use RefreshDatabase;

    private function workspace(): array
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $pharmacy = app(AccessManager::class)->provision($owner->id, ['name' => 'Apotek Online']);
        $pharmacy->forceFill(['status' => 'ACTIVE'])->save();
        $master = app(ClinicalMaster::class);
        $patient = $master->savePatient($pharmacy, $owner->id, ['request_key' => (string) Str::uuid(), 'name' => 'Pasien Konsultasi', 'birth_date' => '1990-01-01', 'sex' => 'female']);
        $doctor = $master->saveDoctor($pharmacy, $owner->id, ['request_key' => (string) Str::uuid(), 'name' => 'Dokter Konsultasi']);
        $visit = app(VisitRegistration::class)->save($pharmacy, $owner->id, ['request_key' => (string) Str::uuid(), 'patient_id' => $patient, 'doctor_id' => $doctor, 'type' => 'new', 'complaint' => 'Konsultasi online']);
        \Illuminate\Support\Facades\DB::table('visits')->where('id', $visit)->update(['status' => 'IN_PROGRESS', 'started_at' => now(), 'revision' => 2]); // Historical visit fixture.
        $this->actingAs($owner);

        return [$owner, $pharmacy, $visit];
    }

    private function image(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('bukti.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/a9sAAAAASUVORK5CYII='));
    }

    private function data(int $revision = 0): array
    {
        return ['evidence_revision' => $revision, 'method' => 'whatsapp', 'consulted_at' => now('Asia/Jakarta')->subHour()->format('Y-m-d\TH:i'), 'notes' => 'Catatan privat konsultasi'];
    }

    public function test_screenshots_are_encrypted_private_scoped_and_audited(): void
    {
        [$owner, $pharmacy, $visit] = $this->workspace();
        $image = $this->image();
        $bytes = $image->getContent();
        $this->post(route('examinations.consultation.save', [$pharmacy, $visit]), $this->data() + ['screenshots' => [$image], 'new_captions' => ['Konfirmasi keluhan privat']])->assertSessionHasNoErrors();
        $file = DB::table('consultation_evidence_images')->first();
        $version = DB::table('consultation_evidence_versions')->first();
        $this->assertNotSame($bytes, Storage::disk('local')->get($file->path));
        $this->assertSame($bytes, Crypt::decryptString(Storage::disk('local')->get($file->path)));
        $this->assertStringNotContainsString('Catatan privat', $version->payload_encrypted);
        $this->get(route('examinations.show', [$pharmacy, $visit]))->assertOk()->assertSee('Bukti konsultasi online')->assertSee('Konfirmasi keluhan privat');
        $response = $this->get(route('examinations.consultation.image', [$pharmacy, $visit, $file->id]))->assertOk()->assertHeader('Content-Type', 'image/png')->assertContent($bytes);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->get(route('examinations.consultation.version', [$pharmacy, $visit, $version->id]))->assertOk()->assertSee('Catatan privat konsultasi');
        $other = app(AccessManager::class)->provision($owner->id, ['name' => 'Apotek lain']);
        $this->get(route('examinations.consultation.image', [$other, $visit, $file->id]))->assertNotFound();
        $this->get(route('examinations.consultation.version', [$other, $visit, $version->id]))->assertNotFound();
        $this->assertDatabaseHas('audit_entries', ['action' => 'consultation.image_viewed', 'pharmacy_id' => $pharmacy->id]);
        $this->assertStringNotContainsString('Konfirmasi keluhan privat', DB::table('audit_entries')->pluck('details')->implode(' '));
        $staff = User::factory()->create();
        $role = $pharmacy->roles()->create(['name' => 'Pembaca pemeriksaan', 'permissions' => ['examinations.view', 'examinations.view_all'], 'is_owner' => false]);
        $member = $pharmacy->memberships()->create(['user_id' => $staff->id, 'active' => true]);
        app(AccessManager::class)->syncRoles($member, collect([$role]));
        $this->actingAs($staff)->get(route('examinations.consultation.image', [$pharmacy, $visit, $file->id]))->assertOk();
        $this->post(route('examinations.consultation.save', [$pharmacy, $visit]), $this->data(1))->assertForbidden();
        $this->actingAs(User::factory()->create())->get(route('examinations.consultation.image', [$pharmacy, $visit, $file->id]))->assertForbidden();
    }

    public function test_online_finalization_requires_evidence_and_corrections_preserve_snapshots(): void
    {
        [$owner, $pharmacy, $visit] = $this->workspace();
        app(ExaminationRecords::class)->draft($pharmacy, $owner->id, $visit, 0, ['subjective' => 'S', 'assessment' => 'A', 'plan' => 'P']);
        $this->post(route('examinations.consultation.save', [$pharmacy, $visit]), $this->data())->assertSessionHasNoErrors();
        $this->post(route('examinations.finalize', [$pharmacy, $visit]), ['revision' => 1])->assertSessionHasErrors('screenshots');
        $this->post(route('examinations.consultation.save', [$pharmacy, $visit]), $this->data() + ['screenshots' => [$this->image()]])->assertSessionHasErrors('evidence_revision');
        $this->assertDatabaseCount('consultation_evidence_images', 0);
        $this->post(route('examinations.consultation.save', [$pharmacy, $visit]), $this->data(1) + ['screenshots' => [$this->image()]])->assertSessionHasNoErrors();
        $current = DB::table('consultation_evidence_versions')->where('version', 2)->first();
        $this->post(route('examinations.finalize', [$pharmacy, $visit]), ['revision' => 1])->assertSessionHasNoErrors();
        $soap = DB::table('examination_versions')->first();
        $this->assertSame($current->id, ExaminationRecords::payload($soap)['consultation_version_id']);
        $file = DB::table('consultation_evidence_images')->first();
        $this->post(route('examinations.consultation.save', [$pharmacy, $visit]), $this->data(2) + ['retained_images' => [$file->id]])->assertSessionHasErrors('change_reason');
        $this->post(route('examinations.consultation.save', [$pharmacy, $visit]), $this->data(2) + ['retained_images' => [$file->id], 'change_reason' => 'Tambahan konteks konsultasi', 'captions' => [$file->id => 'Keterangan dikoreksi']])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('consultation_evidence_versions', 3);
        $this->assertSame($soap->payload_encrypted, DB::table('examination_versions')->find($soap->id)->payload_encrypted);
        $this->assertSame($current->payload_encrypted, DB::table('consultation_evidence_versions')->find($current->id)->payload_encrypted);
        $this->get(route('examinations.consultation.version', [$pharmacy, $visit, $current->id]))->assertOk()->assertDontSee('Keterangan dikoreksi');
    }

    public function test_telephone_without_screenshot_requires_time_reason_and_notes(): void
    {
        [$owner, $pharmacy, $visit] = $this->workspace();
        app(ExaminationRecords::class)->draft($pharmacy, $owner->id, $visit, 0, ['subjective' => 'S', 'assessment' => 'A', 'plan' => 'P']);
        $this->post(route('examinations.consultation.save', [$pharmacy, $visit]), ['evidence_revision' => 0, 'method' => 'telephone'])->assertSessionHasNoErrors();
        $this->post(route('examinations.finalize', [$pharmacy, $visit]), ['revision' => 1])->assertSessionHasErrors(['consulted_at', 'exception_reason', 'notes']);
        $data = array_replace($this->data(1), ['method' => 'telephone', 'exception_reason' => 'Konsultasi melalui sambungan telepon']);
        $this->post(route('examinations.consultation.save', [$pharmacy, $visit]), $data)->assertSessionHasNoErrors();
        $this->post(route('examinations.finalize', [$pharmacy, $visit]), ['revision' => 1])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('consultation_evidence_images', 0);
    }

    public function test_invalid_files_future_time_and_missing_evidence_are_rejected(): void
    {
        [$owner, $pharmacy, $visit] = $this->workspace();
        $url = route('examinations.consultation.save', [$pharmacy, $visit]);
        $this->post($url, $this->data() + ['screenshots' => [UploadedFile::fake()->createWithContent('wrong.svg', '<svg/>')]])->assertSessionHasErrors('screenshots.0');
        $this->post($url, array_replace($this->data(), ['consulted_at' => now('Asia/Jakarta')->addDay()->format('Y-m-d\TH:i')]))->assertSessionHasErrors('consulted_at');
        $this->post($url, $this->data() + ['retained_images' => [(string) Str::uuid()]])->assertSessionHasErrors('screenshots');
        $this->assertDatabaseCount('consultation_evidence_versions', 0);
        $this->post($url, $this->data() + ['screenshots' => [$this->image()]])->assertSessionHasNoErrors();
        $file = DB::table('consultation_evidence_images')->first();
        Storage::disk('local')->delete($file->path);
        app(ExaminationRecords::class)->draft($pharmacy, $owner->id, $visit, 0, ['subjective' => 'S', 'assessment' => 'A', 'plan' => 'P']);
        $this->post(route('examinations.finalize', [$pharmacy, $visit]), ['revision' => 1])->assertSessionHasErrors('screenshots');
    }
}
