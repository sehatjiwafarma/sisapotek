<?php

namespace Tests\Feature;

use App\Models\Pharmacy;
use App\Models\Role;
use App\Models\User;
use App\Services\AccessManager;
use App\Services\PharmacyMaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class MasterPharmacyTest extends TestCase
{
    use RefreshDatabase;

    private function payload(): array
    {
        DB::table('regions')->upsert([
            ['code' => '11', 'name' => 'Aceh', 'level' => 1, 'parent_code' => null],
            ['code' => '11.01', 'name' => 'Aceh Selatan', 'level' => 2, 'parent_code' => '11'],
            ['code' => '11.01.01', 'name' => 'Bakongan', 'level' => 3, 'parent_code' => '11.01'],
            ['code' => '11.01.01.2001', 'name' => 'Keude Bakongan', 'level' => 4, 'parent_code' => '11.01.01'],
            ['code' => '13', 'name' => 'Sumatera Barat', 'level' => 1, 'parent_code' => null],
        ], ['code'], ['name']);

        return ['code' => 'MAK-UNCU', 'name' => 'Apotek Mak Uncu', 'ownership_type' => 'Individual', 'phone' => '081234567890',
            'address' => 'Jalan Melati 10', 'province_code' => '11', 'city_code' => '11.01', 'district_code' => '11.01.01', 'village_code' => '11.01.01.2001',
            'owner_name' => 'Pemilik Apotek', 'owner_phone' => '081234567890'];
    }

    private function workspace(): array
    {
        $user = User::factory()->create();
        $pharmacy = app(PharmacyMaster::class)->create($user, $this->payload())->fresh();

        return [$user, $pharmacy];
    }

    private function administrator(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['is_system_admin' => true])->save();

        return $user;
    }

    public function test_multiple_owners_are_saved_updated_and_shown_in_profile(): void
    {
        $user = User::factory()->create();
        $data = $this->payload();
        unset($data['owner_name'], $data['owner_phone']);
        $data['owners'] = [
            ['name' => 'Pemilik Pertama', 'phone' => '081234567890', 'email' => 'first@example.test', 'share_percentage' => '60.25'],
            ['name' => 'Pemilik Kedua', 'phone' => '081234567891', 'email' => null, 'share_percentage' => '39.75'],
        ];
        $this->actingAs($user)->postJson('/api/v1/pharmacies', $data)->assertCreated()->assertJsonPath('data.owners.1.name', 'Pemilik Kedua');
        $pharmacy = Pharmacy::firstOrFail();
        $this->assertCount(2, $pharmacy->owners);
        $this->get(route('master.show', $pharmacy))->assertOk()->assertSee('Pemilik Kedua')->assertSee('39,75%');
        $this->get(route('master.edit', $pharmacy))->assertOk()->assertSee('owners[1][share_percentage]', false);
        $data['owners'] = [['name' => 'Pemilik Tunggal', 'phone' => '081234567890', 'share_percentage' => 100]];
        $this->patchJson('/api/v1/pharmacies/'.$pharmacy->id, $data)->assertOk();
        $this->assertCount(1, $pharmacy->fresh()->owners);
        $this->assertSame('Pemilik Tunggal', $pharmacy->fresh()->owner_name);
    }

    public function test_owner_shares_must_be_valid_and_total_one_hundred(): void
    {
        $user = User::factory()->create();
        foreach ([[], [['name' => 'Satu', 'phone' => '081234567890', 'share_percentage' => 50]], [['name' => 'Satu', 'phone' => '081234567890', 'share_percentage' => -1]], [['name' => 'Satu', 'phone' => '081234567890', 'share_percentage' => '100.001']]] as $owners) {
            $this->actingAs($user)->postJson('/api/v1/pharmacies', $this->payload() + ['owners' => $owners])->assertUnprocessable();
        }
        $this->assertDatabaseCount('pharmacies', 0);
    }

    private function complete(Pharmacy $pharmacy, User $owner): void
    {
        Storage::fake('local');
        $master = app(PharmacyMaster::class);
        $master->saveLicense($pharmacy, $owner, ['nib' => '1234567890123', 'license_number' => 'SIA-001', 'has_expiry' => 1,
            'issued_on' => '2026-01-01', 'expires_on' => '2030-12-31', 'npwp' => '1234567890123456', 'document_status' => 'Recorded']);
        $master->personnel($pharmacy, $owner, ['name' => 'Apoteker Satu', 'str_number' => 'STR-001', 'sip_number' => 'SIP-001', 'sip_expires_on' => '2030-12-31', 'active' => true]);
        $master->assign($pharmacy, $owner, ['personnel_id' => $pharmacy->personnel()->firstOrFail()->id, 'starts_on' => '2026-01-01']);
        $master->upload($pharmacy, $owner, UploadedFile::fake()->createWithContent('izin.pdf', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF"), ['type' => 'license', 'name' => 'Izin apotek', 'expires_on' => '2030-12-31']);
        $master->saveHours($pharmacy, $owner, ['hours' => array_map(fn ($day) => ['day' => $day, 'is_open' => false, 'overnight' => false], range(0, 6))]);
    }

    public function test_registration_creates_draft_uuid_defaults_and_audit_atomically(): void
    {
        $owner = User::factory()->create();
        $data = $this->payload();
        $response = $this->actingAs($owner)->postJson('/api/v1/pharmacies', $data + ['status' => 'ACTIVE', 'is_system_admin' => true]);
        $response->assertCreated()->assertJsonPath('data.status', 'DRAFT')->assertJsonPath('data.code', 'MAK-UNCU');
        $pharmacy = Pharmacy::firstOrFail();
        $this->assertTrue(Str::isUuid($pharmacy->id));
        $this->assertDatabaseHas('pharmacy_settings', ['pharmacy_id' => $pharmacy->id, 'stock_issue_method' => 'FEFO', 'valuation_method' => 'MOVING_AVERAGE', 'allow_negative_stock' => false, 'track_batch' => true, 'track_expiry' => true, 'expiry_reminder_days' => 90]);
        $this->assertDatabaseHas('memberships', ['pharmacy_id' => $pharmacy->id, 'user_id' => $owner->id, 'active' => true]);
        $this->assertDatabaseHas('audit_entries', ['pharmacy_id' => $pharmacy->id, 'action' => 'pharmacy.created']);
        $this->assertFalse($owner->fresh()->is_system_admin);
    }

    public function test_registration_validates_required_fields_email_and_region_parentage(): void
    {
        $owner = User::factory()->create();
        $data = $this->payload();
        $this->actingAs($owner)->postJson('/api/v1/pharmacies', array_replace($data, ['name' => '', 'email' => 'invalid', 'province_code' => '13']))
            ->assertUnprocessable()->assertJsonValidationErrors(['name', 'email', 'city_code']);
        $this->assertDatabaseCount('pharmacies', 0);
    }

    public function test_duplicate_codes_are_rejected_with_409_and_cannot_be_renamed_after_transactions(): void
    {
        [$owner,$pharmacy] = $this->workspace();
        $this->actingAs($owner)->postJson('/api/v1/pharmacies', $this->payload())->assertConflict();
        $this->assertDatabaseCount('pharmacies', 1);
        $pharmacy->forceFill(['has_transactions' => true])->save();
        $this->patchJson('/api/v1/pharmacies/'.$pharmacy->id, array_replace($this->payload(), ['code' => 'NEW-CODE']))->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->assertSame('MAK-UNCU', $pharmacy->fresh()->code);
    }

    public function test_profile_update_records_before_after_and_cannot_change_status(): void
    {
        [$owner,$pharmacy] = $this->workspace();
        $this->actingAs($owner)->patchJson('/api/v1/pharmacies/'.$pharmacy->id, array_replace($this->payload(), ['name' => 'Apotek Baru', 'status' => 'ACTIVE']))
            ->assertOk()->assertJsonPath('data.name', 'Apotek Baru')->assertJsonPath('data.status', 'DRAFT');
        $entry = DB::table('audit_entries')->where('action', 'pharmacy.updated')->first();
        $details = json_decode($entry->details, true);
        $this->assertSame('Apotek Mak Uncu', $details['before']['name']);
        $this->assertSame('Apotek Baru', $details['after']['name']);
    }

    public function test_owner_and_system_admin_can_request_activation_and_missing_requirements_are_reported(): void
    {
        [$owner,$pharmacy] = $this->workspace();
        $url = '/api/v1/pharmacies/'.$pharmacy->id.'/activate';
        $this->actingAs($owner)->postJson($url)->assertUnprocessable()->assertJsonValidationErrors('activation');
        $this->actingAs($this->administrator())->postJson($url)->assertUnprocessable()->assertJsonValidationErrors('activation');
        $this->assertSame('DRAFT', $pharmacy->fresh()->status);
    }

    public function test_activation_deactivation_and_reactivation_preserve_history(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 4));
        [$owner,$pharmacy] = $this->workspace();
        $this->complete($pharmacy, $owner);
        $admin = $this->administrator();
        $url = '/api/v1/pharmacies/'.$pharmacy->id;
        $this->actingAs($admin)->postJson($url.'/activate')->assertOk()->assertJsonPath('data.status', 'ACTIVE');
        $this->postJson($url.'/deactivate', ['reason' => ''])->assertUnprocessable();
        $this->postJson($url.'/deactivate', ['reason' => 'Pemeliharaan apotek'])->assertOk()->assertJsonPath('data.status', 'INACTIVE');
        $this->assertDatabaseCount('pharmacy_personnel_assignments', 1);
        $this->assertDatabaseCount('pharmacy_documents', 1);
        $this->postJson($url.'/activate')->assertOk()->assertJsonPath('data.status', 'ACTIVE');
        $this->assertDatabaseHas('audit_entries', ['pharmacy_id' => $pharmacy->id, 'action' => 'pharmacy.deactivated']);
    }

    public function test_owner_can_manage_status_of_own_pharmacy_without_system_admin_access(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 4));
        [$owner, $pharmacy] = $this->workspace();
        $this->complete($pharmacy, $owner);
        $this->actingAs($owner)->get(route('master.show', $pharmacy))->assertOk()->assertSee('Aktifkan apotek');
        $this->post(route('master.activate', $pharmacy))->assertRedirect(route('master.show', $pharmacy));
        $this->assertSame('ACTIVE', $pharmacy->fresh()->status);
        $this->post(route('master.deactivate', $pharmacy), ['reason' => 'Pemeliharaan oleh Owner'])->assertRedirect(route('master.show', $pharmacy));
        $this->assertSame('INACTIVE', $pharmacy->fresh()->status);
        $this->postJson('/api/v1/pharmacies/'.$pharmacy->id.'/activate')->assertOk();
        $this->get('/system/pharmacies')->assertForbidden();
        $outsider = User::factory()->create();
        $this->actingAs($outsider)->post(route('master.deactivate', $pharmacy), ['reason' => 'Tidak berwenang'])->assertForbidden();
    }

    public function test_status_controls_are_only_on_admin_list_and_web_actions_return_to_list(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 4));
        [$owner, $pharmacy] = $this->workspace();
        $this->complete($pharmacy, $owner);
        $this->actingAs($owner)->get('/system/pharmacies')->assertForbidden();
        $admin = $this->administrator();
        $this->actingAs($admin)->get('/a/'.$pharmacy->id.'/master')->assertOk()
            ->assertDontSee('Persyaratan aktivasi sudah lengkap')
            ->assertDontSee('Kontrol Admin Sistem')->assertDontSee('Alasan penonaktifan');
        $this->get('/system/pharmacies')->assertOk()->assertSee('Ya, aktifkan')
            ->assertDontSee('data-deactivate-open', false);
        $this->post(route('master.activate', $pharmacy))->assertRedirect(route('system.pharmacies'));
        $response = $this->get('/system/pharmacies')->assertOk()
            ->assertSee('data-deactivate-open', false);
        $this->assertMatchesRegularExpression('/data-deactivate-panel\s+hidden/', $response->getContent());
        $this->from('/system/pharmacies')->post(route('master.deactivate', $pharmacy), [
            'deactivate_pharmacy' => $pharmacy->id, 'reason' => '',
        ])->assertRedirect('/system/pharmacies')->assertSessionHasErrors('reason');
        $response = $this->get('/system/pharmacies')->assertOk()
            ->assertSee('aria-expanded="true"', false);
        $this->assertDoesNotMatchRegularExpression('/data-deactivate-panel\s+hidden/', $response->getContent());
        $this->post(route('master.deactivate', $pharmacy), [
            'deactivate_pharmacy' => $pharmacy->id, 'reason' => 'Pemeliharaan sementara',
        ])->assertRedirect(route('system.pharmacies'));
        $this->assertSame('INACTIVE', $pharmacy->fresh()->status);
    }

    public function test_apj_assignments_cannot_overlap_and_previous_snapshot_is_preserved(): void
    {
        [$owner,$pharmacy] = $this->workspace();
        $master = app(PharmacyMaster::class);
        $master->personnel($pharmacy, $owner, ['name' => 'APJ Pertama', 'str_number' => 'STR1', 'sip_number' => 'SIP1', 'sip_expires_on' => '2030-01-01', 'active' => true]);
        $person = $pharmacy->personnel()->firstOrFail();
        $url = '/api/v1/pharmacies/'.$pharmacy->id;
        $this->actingAs($owner)->postJson($url.'/personnel', ['personnel_id' => $person->id, 'starts_on' => '2026-01-01'])->assertCreated();
        $this->postJson($url.'/personnel', ['personnel_id' => $person->id, 'starts_on' => '2026-02-01'])->assertUnprocessable();
        $assignment = $pharmacy->assignments()->firstOrFail();
        $this->patchJson($url.'/assignments/'.$assignment->id.'/end', ['ends_on' => '2026-02-01'])->assertOk();
        $this->postJson($url.'/personnel', ['personnel_id' => $person->id, 'starts_on' => '2026-02-02'])->assertCreated();
        $person->update(['name' => 'Nama Diubah', 'sip_number' => 'SIP2']);
        $this->assertSame('APJ Pertama', $assignment->fresh()->credential_snapshot['name']);
        $this->assertDatabaseCount('pharmacy_personnel_assignments', 2);
    }

    public function test_hours_reject_overlap_across_midnight_and_week_boundary(): void
    {
        [$owner,$pharmacy] = $this->workspace();
        $hours = array_map(fn ($day) => ['day' => $day, 'is_open' => false, 'overnight' => false], range(0, 6));
        $hours[6] = ['day' => 6, 'is_open' => true, 'opens_at' => '22:00', 'closes_at' => '02:00', 'overnight' => true];
        $hours[0] = ['day' => 0, 'is_open' => true, 'opens_at' => '01:00', 'closes_at' => '04:00', 'overnight' => false];
        $url = '/api/v1/pharmacies/'.$pharmacy->id.'/hours';
        $this->actingAs($owner)->putJson($url, ['hours' => $hours])->assertUnprocessable();
        $this->assertDatabaseCount('pharmacy_operating_hours', 0);
        $hours[0]['opens_at'] = '02:00';
        $this->putJson($url, ['hours' => $hours])->assertOk();
        $this->assertDatabaseCount('pharmacy_operating_hours', 7);
        $hours[] = ['day' => 0, 'is_open' => true, 'opens_at' => '05:00', 'closes_at' => '06:00', 'overnight' => false];
        $this->putJson($url, ['hours' => $hours])->assertOk();
        $this->assertDatabaseCount('pharmacy_operating_hours', 8);
    }

    public function test_documents_are_private_scoped_and_reject_invalid_files(): void
    {
        Storage::fake('local');
        [$owner,$pharmacy] = $this->workspace();
        $other = app(AccessManager::class)->provision($owner->id, ['name' => 'Apotek Lain']);
        $url = '/api/v1/pharmacies/'.$pharmacy->id.'/documents';
        $this->actingAs($owner)->postJson($url, ['type' => 'license', 'name' => 'Berkas besar', 'file' => UploadedFile::fake()->create('besar.pdf', 6000, 'application/pdf')])->assertUnprocessable();
        $this->postJson($url, ['type' => 'other', 'name' => 'Script', 'file' => UploadedFile::fake()->createWithContent('script.php', '<?php echo 1;')])->assertUnprocessable();
        $this->postJson($url, ['type' => 'license', 'name' => 'Izin', 'file' => UploadedFile::fake()->createWithContent('izin.pdf', "%PDF-1.4\n%%EOF")])->assertCreated();
        $document = $pharmacy->documents()->firstOrFail();
        Storage::disk('local')->assertExists($document->path);
        $this->get('/a/'.$other->id.'/master/documents/'.$document->id)->assertNotFound();
        $this->get('/a/'.$pharmacy->id.'/master/documents/'.$document->id)->assertOk();
        $this->actingAs(User::factory()->create())->getJson($url.'/'.$document->id)->assertForbidden();
    }

    public function test_staff_has_limited_view_and_apj_can_only_update_contact_and_hours(): void
    {
        [$owner,$pharmacy] = $this->workspace();
        $staff = User::factory()->create();
        $role = $pharmacy->roles()->create(['name' => 'APJ Terbatas', 'permissions' => ['pharmacy.view', 'pharmacy.update_limited']]);
        $member = $pharmacy->memberships()->create(['user_id' => $staff->id, 'active' => true]);
        app(AccessManager::class)->syncRoles($member, collect([$role]));
        $url = '/api/v1/pharmacies/'.$pharmacy->id;
        $this->actingAs($staff)->getJson($url)->assertOk()->assertJsonMissingPath('data.owner_phone')->assertJsonMissingPath('data.license');
        $this->patchJson($url, $this->payload())->assertForbidden();
        $this->patchJson($url.'/contact', ['phone' => '081111111111', 'status' => 'ACTIVE', 'owner_name' => 'Hacker'])->assertOk();
        $this->assertDatabaseHas('pharmacies', ['id' => $pharmacy->id, 'phone' => '081111111111', 'status' => 'DRAFT', 'owner_name' => 'Pemilik Apotek']);
        $this->getJson($url.'/settings')->assertForbidden();
    }

    public function test_guest_and_other_tenant_cannot_read_or_mutate_master(): void
    {
        [$owner,$pharmacy] = $this->workspace();
        $url = '/api/v1/pharmacies/'.$pharmacy->id;
        $this->getJson($url)->assertUnauthorized();
        $this->actingAs(User::factory()->create())->getJson($url)->assertForbidden();
        $this->patchJson($url, $this->payload())->assertForbidden();
        $this->assertDatabaseHas('pharmacies', ['id' => $pharmacy->id, 'name' => 'Apotek Mak Uncu']);
    }

    public function test_inactive_pharmacies_block_new_operations_but_allow_master_reads(): void
    {
        [$owner,$pharmacy] = $this->workspace();
        $pharmacy->forceFill(['status' => 'INACTIVE'])->save();
        Route::post('/a/{pharmacy}/test-operation', fn (Pharmacy $pharmacy) => response()->json(['ok' => true]))->middleware(['web', 'auth', 'pharmacy', 'pharmacy.active']);
        $this->actingAs($owner)->postJson('/a/'.$pharmacy->id.'/test-operation')->assertUnprocessable();
        $this->getJson('/api/v1/pharmacies/'.$pharmacy->id)->assertOk();
    }

    public function test_registration_rolls_back_when_role_creation_fails(): void
    {
        $owner = User::factory()->create();
        $data = $this->payload();
        Event::listen('eloquent.creating: '.Role::class, function () {
            throw new \RuntimeException('Simulated role failure');
        });
        $this->actingAs($owner)->postJson('/api/v1/pharmacies', $data)->assertServerError();
        $this->assertDatabaseCount('pharmacies', 0);
        $this->assertDatabaseCount('pharmacy_settings', 0);
        $this->assertDatabaseCount('memberships', 0);
    }

    public function test_master_screens_render_for_owner_and_admin_without_membership(): void
    {
        [$owner,$pharmacy] = $this->workspace();
        $this->actingAs($owner)->get('/pharmacies/create')->assertOk()->assertSee('Simpan draft');
        $this->get('/a/'.$pharmacy->id.'/master')->assertOk()->assertSee('Legalitas')->assertDontSee('Kontrol Admin Sistem');
        $this->get('/a/'.$pharmacy->id.'/master/edit')->assertOk();
        $this->actingAs($this->administrator())->get('/a/'.$pharmacy->id.'/master')->assertOk()->assertDontSee('Kontrol Admin Sistem');
        $this->get('/system/pharmacies')->assertOk();
    }

    public function test_personnel_screen_explains_setup_and_distinguishes_current_scheduled_and_past_assignments(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 4)->setTime(12, 0));
        [$owner, $pharmacy] = $this->workspace();
        $url = '/a/'.$pharmacy->id.'/master';
        $this->actingAs($owner)->get($url)->assertOk()
            ->assertSee('Belum ada penanggung jawab untuk hari ini')
            ->assertSee('Daftarkan apoteker')->assertSee('Tetapkan penanggung jawab')
            ->assertSee('Belum ada apoteker terdaftar');

        $master = app(PharmacyMaster::class);
        $master->personnel($pharmacy, $owner, [
            'name' => 'Apoteker Pengujian', 'active' => true, 'str_number' => 'STR-TEST',
            'sip_number' => 'SIP-TEST', 'sip_expires_on' => '2030-12-31',
        ]);
        $person = $pharmacy->personnel()->firstOrFail();
        foreach ([['2026-01-01', '2026-11-03'], ['2026-11-04', '2026-11-05'], ['2026-11-06', null]] as [$start, $end]) {
            $master->assign($pharmacy, $owner, ['personnel_id' => $person->id, 'starts_on' => $start, 'ends_on' => $end]);
        }

        $this->get($url)->assertOk()
            ->assertSee('Bertugas sejak 04/11/2026 sampai 05/11/2026.')
            ->assertSee('Bertugas hari ini')->assertSee('Terjadwal')->assertSee('Selesai')
            ->assertSee('Nomor Surat Tanda Registrasi (STR)')
            ->assertSee('Nomor Surat Izin Praktik (SIP)')
            ->assertSee('data-confirm-title="Tentukan akhir tugas"', false)
            ->assertDontSee('Belum ada penanggung jawab untuk hari ini');
    }

    public function test_personnel_page_keeps_forms_and_history_on_one_page(): void
    {
        [$owner, $pharmacy] = $this->workspace();
        $this->actingAs($owner)->get(route('master.show', $pharmacy))->assertOk()
            ->assertDontSee('data-personnel-step=', false)
            ->assertSee('id="personnel-assignment-dialog"', false)
            ->assertSee('id="personnel-history"', false)
            ->assertSee('role="tooltip"', false)
            ->assertSee('aria-label="Tentang apoteker penanggung jawab"', false)
            ->assertSee('data-personnel-editor-open="create"', false)
            ->assertSee('NAMA APOTEKER')
            ->assertDontSee('<summary>+ Tambah data apoteker</summary>', false);
        $this->post(route('master.personnel', $pharmacy), [
            'tab' => 'personnel', '_personnel_form' => 'create', 'name' => 'Apoteker Baru',
            'active' => 1, 'str_number' => 'STR-BARU', 'sip_number' => 'SIP-BARU', 'sip_expires_on' => '2030-12-31',
        ])->assertRedirect(route('master.show', ['pharmacy' => $pharmacy, 'personnel_step' => 'data']).'#personnel');
        $person = $pharmacy->personnel()->firstOrFail();
        $response = $this->get(route('master.show', $pharmacy))->assertOk()
            ->assertSee('data-personnel-editor-open="edit-'.$person->id.'"', false)
            ->assertSee('data-assignment-open="'.$person->id.'"', false);
        $this->assertMatchesRegularExpression('/data-personnel-editor="create"\s+hidden/', $response->getContent());
        $this->assertMatchesRegularExpression('/data-personnel-editor="edit-'.preg_quote($person->id, '/').'"\s+hidden/', $response->getContent());
        $this->withSession(['_old_input' => ['_personnel_form' => 'assign', 'personnel_id' => $person->id, 'starts_on' => '2026-11-04']])
            ->get(route('master.show', $pharmacy))->assertOk()
            ->assertSee('data-reopen="true"', false)
            ->assertSee('value="2026-11-04"', false);
        $response = $this->withSession(['_old_input' => ['_personnel_form' => 'edit-'.$person->id, 'name' => 'Nama gagal validasi']])
            ->get(route('master.show', $pharmacy))->assertOk()
            ->assertSee('value="Nama gagal validasi"', false);
        $this->assertMatchesRegularExpression('/data-personnel-list\s+hidden/', $response->getContent());
        $this->assertDoesNotMatchRegularExpression('/data-personnel-editor="edit-'.preg_quote($person->id, '/').'"\s+hidden/', $response->getContent());
        $this->withSession(['_old_input' => []]);
        $this->post(route('master.assign', $pharmacy), [
            'tab' => 'personnel', '_personnel_form' => 'assign',
            'personnel_id' => $person->id, 'starts_on' => '2026-11-04',
        ])->assertRedirect(route('master.show', ['pharmacy' => $pharmacy, 'personnel_step' => 'assignment']).'#personnel');
        $this->get(route('master.show', ['pharmacy' => $pharmacy, 'personnel_step' => 'assignment']))
            ->assertOk()->assertSee('Penanggung jawab saat ini');
        $this->withSession(['_old_input' => ['_personnel_form' => 'end-example']])
            ->get(route('master.show', $pharmacy))->assertOk()
            ->assertSee('id="personnel-history"  open', false);
    }

    public function test_personnel_deletion_requires_permission_is_tenant_scoped_and_preserves_assignment_history(): void
    {
        [$owner, $pharmacy] = $this->workspace();
        $master = app(PharmacyMaster::class);
        $master->personnel($pharmacy, $owner, ['name' => 'Apoteker Belum Ditugaskan', 'active' => true]);
        $person = $pharmacy->personnel()->firstOrFail();
        $otherOwner = User::factory()->create();
        $otherPharmacy = $master->create($otherOwner, array_replace($this->payload(), ['code' => 'OTHER-APOTEK']));
        $this->actingAs($otherOwner)->deleteJson('/api/v1/pharmacies/'.$otherPharmacy->id.'/personnel/people/'.$person->id)->assertNotFound();
        $viewer = User::factory()->create();
        $pharmacy->memberships()->create(['user_id' => $viewer->id, 'active' => true]);
        $this->actingAs($viewer)->deleteJson('/api/v1/pharmacies/'.$pharmacy->id.'/personnel/people/'.$person->id)->assertForbidden();
        $this->assertDatabaseHas('pharmacy_personnel', ['id' => $person->id]);
        $this->actingAs($owner)->get(route('master.show', $pharmacy))->assertOk()
            ->assertSee('aria-label="Hapus data Apoteker Belum Ditugaskan"', false)
            ->assertSee('data-confirm-title="Hapus apoteker"', false);
        $this->delete(route('master.personnel.delete', [$pharmacy, $person->id]), ['tab' => 'personnel', '_personnel_form' => 'delete'])
            ->assertRedirect(route('master.show', ['pharmacy' => $pharmacy, 'personnel_step' => 'data']).'#personnel');
        $this->assertDatabaseMissing('pharmacy_personnel', ['id' => $person->id]);
        $this->assertDatabaseHas('audit_entries', ['pharmacy_id' => $pharmacy->id, 'action' => 'pharmacy.personnel_deleted']);
        $master->personnel($pharmacy, $owner, ['name' => 'Apoteker Historis', 'active' => true, 'str_number' => 'STR', 'sip_number' => 'SIP', 'sip_expires_on' => '2030-12-31']);
        $assigned = $pharmacy->personnel()->firstOrFail();
        $master->assign($pharmacy, $owner, ['personnel_id' => $assigned->id, 'starts_on' => '2026-01-01', 'ends_on' => '2026-01-31']);
        $this->deleteJson('/api/v1/pharmacies/'.$pharmacy->id.'/personnel/people/'.$assigned->id)
            ->assertUnprocessable()->assertJsonValidationErrors('personnel');
        $this->assertDatabaseHas('pharmacy_personnel', ['id' => $assigned->id]);
        $this->assertDatabaseCount('pharmacy_personnel_assignments', 1);
    }

    public function test_stock_methods_are_locked_after_transactions(): void
    {
        [$owner,$pharmacy] = $this->workspace();
        $pharmacy->forceFill(['has_transactions' => true])->save();
        $data = $pharmacy->settings->only(['timezone', 'currency', 'stock_issue_method', 'valuation_method', 'allow_negative_stock', 'track_batch', 'track_expiry', 'expiry_reminder_days', 'minimum_stock_notification']);
        $this->actingAs($owner)->patchJson('/api/v1/pharmacies/'.$pharmacy->id.'/settings', array_replace($data, ['valuation_method' => 'FIFO']))->assertUnprocessable();
        $this->assertSame('MOVING_AVERAGE', $pharmacy->settings->fresh()->valuation_method);
    }

    public function test_system_admin_can_be_provisioned_without_default_password_and_login_opens_admin_portal(): void
    {
        $this->artisan('apotek:system-admin', ['email' => 'administrator@example.test', '--create' => true])->assertSuccessful();
        $admin = User::where('email', 'administrator@example.test')->firstOrFail();
        $this->assertTrue($admin->is_system_admin);
        $this->assertDatabaseHas('system_admin_events', ['user_id' => $admin->id, 'granted' => true]);
        $admin->password = 'Administrator123';
        $admin->save();
        $this->post('/login', ['email' => $admin->email, 'password' => 'Administrator123'])->assertRedirect(route('system.pharmacies'));
        $this->get('/system/pharmacies')->assertOk()->assertSee('Admin Sistem');
    }

    public function test_sidebar_branding_follows_the_current_pharmacy_and_falls_back_to_initials(): void
    {
        Storage::fake('local');
        [$owner, $pharmacy] = $this->workspace();
        $this->actingAs($owner)->get(route('master.show', $pharmacy))->assertOk()
            ->assertSee('aria-label="Apotek Mak Uncu — Pilih apotek"', false)
            ->assertSee('pharmacy-brand-initials')->assertSee('>AM</span>', false);
        Storage::disk('local')->put('logos/example.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a2ioAAAAASUVORK5CYII='));
        $pharmacy->forceFill(['logo_path' => 'logos/example.png'])->save();
        $this->get(route('pharmacies.index'))->assertOk()
            ->assertSee(route('master.brand-logo', $pharmacy));
        $this->get(route('master.show', $pharmacy))->assertOk()
            ->assertSee(route('master.brand-logo', $pharmacy))->assertDontSee('pharmacy-brand-initials');
        $other = app(AccessManager::class)->provision($owner->id, ['name' => 'Apotek Kedua']);
        $this->get(route('master.show', $other))->assertOk()
            ->assertSee('aria-label="Apotek Kedua — Pilih apotek"', false)
            ->assertDontSee(route('master.brand-logo', $pharmacy));
        $pharmacy->forceFill(['name' => 'Nama Apotek Baru', 'logo_path' => 'missing.png'])->save();
        $this->get(route('master.show', $pharmacy))->assertOk()
            ->assertSee('aria-label="Nama Apotek Baru — Pilih apotek"', false)
            ->assertSee('pharmacy-brand-initials')->assertSee('>NA</span>', false);
        $this->get(route('pharmacies.index'))->assertOk()->assertDontSee('pharmacy-brand-initials')
            ->assertDontSee(route('master.brand-logo', $pharmacy));
    }

    public function test_brand_logo_is_available_to_members_without_profile_permission_but_not_outsiders(): void
    {
        Storage::fake('local');
        [$owner, $pharmacy] = $this->workspace();
        Storage::disk('local')->put('logos/example.png', 'logo');
        $pharmacy->forceFill(['logo_path' => 'logos/example.png'])->save();
        $member = User::factory()->create();
        $membership = $pharmacy->memberships()->create(['user_id' => $member->id, 'active' => true]);
        $this->actingAs($member)->get(route('master.brand-logo', $pharmacy))->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->get(route('master.logo', $pharmacy))->assertForbidden();
        $this->post(route('master.logo.save', $pharmacy))->assertForbidden();
        $membership->update(['active' => false]);
        $this->get(route('master.brand-logo', $pharmacy))->assertForbidden();
        $this->actingAs(User::factory()->create())->get(route('master.brand-logo', $pharmacy))->assertForbidden();
    }

    public function test_logo_upload_is_private_and_rejects_oversized_image(): void
    {
        Storage::fake('local');
        [$owner,$pharmacy] = $this->workspace();
        $url = '/api/v1/pharmacies/'.$pharmacy->id.'/logo';
        $this->actingAs($owner)->postJson($url, ['logo' => UploadedFile::fake()->create('logo.png', 3000, 'image/png')])->assertUnprocessable();
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a2ioAAAAASUVORK5CYII=');
        $this->postJson($url, ['logo' => UploadedFile::fake()->createWithContent('logo.png', $png)])->assertOk();
        Storage::disk('local')->assertExists($pharmacy->fresh()->logo_path);
        $this->get('/a/'.$pharmacy->id.'/master/logo')->assertOk();
    }
}
