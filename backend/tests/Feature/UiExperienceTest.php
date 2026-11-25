<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AccessManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class UiExperienceTest extends TestCase
{
    use RefreshDatabase;

    public function test_layout_and_invitation_dates_follow_the_active_pharmacy_timezone(): void
    {
        $owner = User::factory()->create();
        $pharmacy = app(AccessManager::class)->provision($owner->id, ['name' => 'Apotek UI']);
        $pharmacy->settings()->updateOrCreate([], ['timezone' => 'Asia/Jayapura']);
        $this->actingAs($owner)->get(route('pharmacy.home', $pharmacy))
            ->assertOk()->assertSee('Zona waktu Asia/Jayapura')->assertDontSee('Zona waktu Asia/Jakarta');
        $pharmacy->invitations()->create([
            'email' => 'timezone@example.test', 'invited_by' => $owner->id,
            'role_ids' => [$pharmacy->roles()->first()->id], 'token_hash' => hash('sha256', Str::random(40)),
            'expires_at' => Carbon::parse('2026-11-11 15:05:00', 'UTC'),
        ]);
        $this->get(route('users.index', $pharmacy))->assertOk()->assertSee('12/11/2026 00:05')->assertSee('js/forms.js', false);
    }

    public function test_patient_validation_renders_linked_inline_errors_and_keeps_input(): void
    {
        $owner = User::factory()->create();
        $pharmacy = app(AccessManager::class)->provision($owner->id, ['name' => 'Apotek UI']);
        $pharmacy->forceFill(['status' => 'ACTIVE'])->save();
        $this->actingAs($owner)->get(route('patients.create', $pharmacy))->assertOk();
        $this->followingRedirects()->from(route('patients.create', $pharmacy))->post(route('patients.store', $pharmacy), [
            'name' => str_repeat('x', 151), 'birth_date' => '2099-12-31', 'sex' => 'unspecified', 'request_key' => (string) Str::uuid(),
        ])->assertOk()
            ->assertSee('data-error-field="birth_date"', false)
            ->assertSee('id="input-birth_date-error"', false)
            ->assertSee('aria-describedby="input-birth_date-error"', false)
            ->assertSee(str_repeat('x', 151));
    }

    public function test_workspace_shortcuts_respect_membership_permissions(): void
    {
        $owner = User::factory()->create();
        $manager = app(AccessManager::class);
        $pharmacy = $manager->provision($owner->id, ['name' => 'Apotek UI']);
        $pharmacy->forceFill(['status' => 'ACTIVE'])->save();
        $this->actingAs($owner)->get(route('pharmacy.home', $pharmacy))->assertOk()
            ->assertSee('Mulai pekerjaan')->assertSee(route('sales.create', $pharmacy), false);
        $staff = User::factory()->create();
        $member = $pharmacy->memberships()->create(['user_id' => $staff->id, 'active' => true]);
        $role = $pharmacy->roles()->create(['name' => 'Pembaca', 'permissions' => ['sales.view', 'shifts.view']]);
        $manager->syncRoles($member, collect([$role]));
        $this->actingAs($staff)->get(route('pharmacy.home', $pharmacy))->assertOk()
            ->assertSee(route('shifts.index', $pharmacy), false)
            ->assertDontSee(route('sales.create', $pharmacy), false)
            ->assertDontSee(route('users.index', $pharmacy), false);
    }
}
