<?php

namespace Tests\Feature;

use App\Models\Membership;
use App\Models\Pharmacy;
use App\Models\Role;
use App\Models\User;
use App\Notifications\PharmacyInvitation;
use App\Services\AccessManager;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class AccessManagementTest extends TestCase
{
    use RefreshDatabase;

    private function workspace(): array
    {
        $owner = User::factory()->create();
        $pharmacy = app(AccessManager::class)->provision($owner->id, ['name' => 'Apotek Mak Uncu']);

        return [$owner, $pharmacy];
    }

    private function member($pharmacy, array $permissions): Membership
    {
        $role = $pharmacy->roles()->create(['name' => 'Role '.bin2hex(random_bytes(3)), 'permissions' => $permissions]);
        $member = $pharmacy->memberships()->create(['user_id' => User::factory()->create()->id, 'active' => true]);
        app(AccessManager::class)->syncRoles($member, collect([$role]));

        return $member;
    }

    public function test_pages_render_and_owner_can_manage_custom_roles(): void
    {
        [$owner, $pharmacy] = $this->workspace();
        foreach (['/login', '/register', '/forgot-password', '/reset-password/example'] as $path) {
            $this->get($path)->assertOk();
        }
        $this->actingAs($owner);
        foreach (['/pharmacies', '/profile', "/a/$pharmacy->id", "/a/$pharmacy->id/users", "/a/$pharmacy->id/users/invite", "/a/$pharmacy->id/roles", "/a/$pharmacy->id/roles/create", "/a/$pharmacy->id/audit", "/a/$pharmacy->id/master"] as $path) {
            $this->get($path)->assertOk()
                ->assertSee('id="app-confirm"', false)
                ->assertSee('js/confirmation.js', false);
        }
        $this->post("/a/$pharmacy->id/roles", ['name' => 'Kasir', 'permissions' => ['sales.view', 'sales.pay']])->assertRedirect();
        $role = $pharmacy->roles()->where('name', 'Kasir')->firstOrFail();
        $this->get("/a/$pharmacy->id/roles/$role->id/edit")->assertOk()->assertSee('sales.pay');
        $this->put("/a/$pharmacy->id/roles/$role->id", ['name' => 'Kasir', 'permissions' => []])->assertRedirect();
        $this->assertSame([], $role->fresh()->permissions);
        $this->delete("/a/$pharmacy->id/roles/$role->id")->assertRedirect();
        $this->assertDatabaseMissing('roles', ['id' => $role->id]);
        $this->assertDatabaseHas('audit_entries', ['pharmacy_id' => $pharmacy->id, 'action' => 'role.deleted']);
    }

    public function test_tenant_boundaries_cover_reads_writes_and_foreign_ids(): void
    {
        [$ownerA, $a] = $this->workspace();
        [$ownerB, $b] = $this->workspace();
        $memberB = $b->memberships()->first();
        $roleB = $b->roles()->first();
        $this->actingAs($ownerA)->get("/a/$b->id/users")->assertForbidden();
        $this->post("/a/$b->id/roles", ['name' => 'Injected'])->assertForbidden();
        $this->get("/a/$a->id/users/$memberB->id/edit")->assertNotFound();
        $this->put("/a/$a->id/users/$memberB->id/status", ['active' => 0])->assertNotFound();
        $this->delete("/a/$a->id/roles/$roleB->id")->assertNotFound();
        $this->put("/a/$a->id/users/".$a->memberships()->first()->id.'/roles', ['roles' => [$roleB->id]])->assertSessionHasErrors('roles');
        $this->assertTrue($memberB->fresh()->active);
    }

    public function test_last_owner_cannot_be_disabled_removed_or_demoted(): void
    {
        [$owner, $pharmacy] = $this->workspace();
        $member = $pharmacy->memberships()->first();
        $role = $pharmacy->roles()->create(['name' => 'Kasir', 'permissions' => ['sales.view']]);
        $this->actingAs($owner)->put("/a/$pharmacy->id/users/$member->id/status", ['active' => 0])->assertSessionHasErrors('owner');
        $this->delete("/a/$pharmacy->id/users/$member->id")->assertSessionHasErrors('owner');
        $this->put("/a/$pharmacy->id/users/$member->id/roles", ['roles' => [$role->id]])->assertSessionHasErrors('owner');
        $this->assertTrue($member->fresh()->isOwner());
        $ownerRole = $pharmacy->roles()->where('is_owner', true)->first();
        $this->put("/a/$pharmacy->id/roles/$ownerRole->id", ['name' => 'Altered'])->assertForbidden();
    }

    public function test_permissions_are_per_function_combined_and_revoked_immediately(): void
    {
        [$owner, $pharmacy] = $this->workspace();
        $member = $this->member($pharmacy, ['users.view']);
        $this->actingAs($member->user)->get("/a/$pharmacy->id/users")->assertOk();
        $this->get("/a/$pharmacy->id/users/invite")->assertForbidden();
        $this->post("/a/$pharmacy->id/roles", ['name' => 'Invalid'])->assertForbidden();
        $extra = $pharmacy->roles()->create(['name' => 'Auditor', 'permissions' => ['audit.view']]);
        $member->roles()->attach($extra->id, ['pharmacy_id' => $pharmacy->id]);
        $this->get("/a/$pharmacy->id/audit")->assertOk();
        $extra->update(['permissions' => []]);
        $this->get("/a/$pharmacy->id/audit")->assertForbidden();
        $member->update(['active' => false]);
        $this->get("/a/$pharmacy->id/users")->assertForbidden();
    }

    public function test_managers_cannot_escalate_or_assign_owner_or_edit_higher_roles(): void
    {
        [$owner, $pharmacy] = $this->workspace();
        $manager = $this->member($pharmacy, ['roles.create', 'roles.update', 'users.update', 'users.invite', 'users.deactivate', 'sales.view']);
        $this->actingAs($manager->user)->post("/a/$pharmacy->id/roles", ['name' => 'Escalation', 'permissions' => ['roles.delete']])->assertForbidden();
        $this->post("/a/$pharmacy->id/roles", ['name' => 'Invalid', 'permissions' => ['anything.root']])->assertSessionHasErrors('permissions.0');
        $this->post("/a/$pharmacy->id/roles", ['name' => 'Reader', 'permissions' => ['sales.view']])->assertRedirect();
        $ownerRole = $pharmacy->roles()->where('is_owner', true)->first();
        $this->put("/a/$pharmacy->id/users/$manager->id/roles", ['roles' => [$ownerRole->id]])->assertForbidden();
        $ownerMember = $pharmacy->memberships()->where('user_id', $owner->id)->first();
        $this->put("/a/$pharmacy->id/users/$ownerMember->id/status", ['active' => 0])->assertForbidden();
    }

    public function test_same_role_names_and_one_account_have_independent_tenant_access(): void
    {
        [$owner, $a] = $this->workspace();
        $b = app(AccessManager::class)->provision($owner->id, ['name' => 'Cabang B']);
        foreach ([$a, $b] as $p) {
            $this->actingAs($owner)->post("/a/$p->id/roles", ['name' => 'Kasir', 'permissions' => ['sales.view']])->assertRedirect();
        }
        $this->assertSame(2, Role::where('name', 'Kasir')->count());
        $this->actingAs($owner)->post("/a/$a->id/roles", ['name' => 'kasir'])->assertSessionHasErrors('name');
        $member = $this->member($a, ['users.view']);
        $mb = $b->memberships()->create(['user_id' => $member->user_id, 'active' => true]);
        app(AccessManager::class)->syncRoles($mb, collect([$b->roles()->where('name', 'Kasir')->first()]));
        $this->actingAs($member->user)->get("/a/$a->id/users")->assertOk();
        $this->get("/a/$b->id/users")->assertForbidden();
        $member->update(['active' => false]);
        $this->get("/a/$b->id")->assertOk();
    }

    public function test_invitation_requires_recipient_and_is_single_use(): void
    {
        Notification::fake();
        [$owner, $p] = $this->workspace();
        $role = $p->roles()->create(['name' => 'Kasir', 'permissions' => ['sales.view']]);
        $user = User::factory()->create();
        $this->actingAs($owner)->post("/a/$p->id/users/invite", ['email' => $user->email, 'roles' => [$role->id]])->assertRedirect();
        $token = null;
        Notification::assertSentOnDemand(PharmacyInvitation::class, function ($notification) use (&$token) {
            $token = $notification->token;

            return true;
        });
        $this->get('/invitations/'.$token)->assertOk();
        $this->post('/invitations/'.$token)->assertForbidden();
        $this->actingAs($user)->post('/invitations/'.$token)->assertRedirect();
        $this->assertDatabaseHas('memberships', ['pharmacy_id' => $p->id, 'user_id' => $user->id, 'active' => true]);
        $this->post('/invitations/'.$token)->assertStatus(410);
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_revoked_expired_and_no_longer_authorized_invitations_fail(): void
    {
        [$owner, $p] = $this->workspace();
        $manager = $this->member($p, ['users.invite', 'sales.view']);
        $role = $p->roles()->create(['name' => 'Kasir', 'permissions' => ['sales.view']]);
        $user = User::factory()->create();
        [$invite, $token] = app(AccessManager::class)->invite($p, $manager->user_id, $user->email, [$role->id]);
        $invite->update(['expires_at' => now()->subMinute()]);
        $this->actingAs($user)->post('/invitations/'.$token)->assertStatus(410);
        $invite->update(['expires_at' => now()->addDay(), 'revoked_at' => now()]);
        $this->post('/invitations/'.$token)->assertStatus(410);
        $invite->update(['revoked_at' => null]);
        $manager->update(['active' => false]);
        $this->post('/invitations/'.$token)->assertForbidden();
        $this->assertDatabaseMissing('memberships', ['pharmacy_id' => $p->id, 'user_id' => $user->id]);
    }

    public function test_used_roles_cannot_be_deleted_and_audit_is_tenant_scoped(): void
    {
        [$owner, $p] = $this->workspace();
        $member = $this->member($p, ['sales.view']);
        $role = $member->roles->first();
        $this->actingAs($owner)->delete("/a/$p->id/roles/$role->id")->assertSessionHasErrors('role');
        $this->get("/a/$p->id/audit")->assertOk()->assertSee('pharmacy.created');
        $this->actingAs($member->user)->get("/a/$p->id/audit")->assertForbidden();
    }

    public function test_login_profile_password_reset_and_logout(): void
    {
        $user = User::factory()->create(['email' => 'owner@example.test', 'password' => 'OldPassword123']);
        $this->post('/login', ['email' => $user->email, 'password' => 'incorrect'])->assertSessionHasErrors('email');
        $this->post('/login', ['email' => $user->email, 'password' => 'OldPassword123'])->assertRedirect();
        $this->assertAuthenticatedAs($user);
        $this->put('/profile', ['name' => 'Pemilik', 'phone' => '0812345'])->assertRedirect();
        $this->assertSame('Pemilik', $user->fresh()->name);
        $this->put('/profile/password', ['current_password' => 'OldPassword123', 'password' => 'NewPassword123', 'password_confirmation' => 'NewPassword123'])->assertRedirect();
        $this->assertTrue(Hash::check('NewPassword123', $user->fresh()->password));
        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
        $token = Password::createToken($user);
        $this->post('/reset-password', ['email' => $user->email, 'token' => $token, 'password' => 'ResetPassword123', 'password_confirmation' => 'ResetPassword123'])->assertRedirect('/login');
        $this->assertTrue(Hash::check('ResetPassword123', $user->fresh()->password));
    }

    public function test_registration_ignores_stale_pharmacy_url_and_account_can_login_again(): void
    {
        $this->withSession(['url.intended' => url('/a/2/roles/create')])
            ->post('/register', ['name' => 'Pengguna Baru', 'email' => 'NewOwner@example.test', 'password' => 'Registration123', 'password_confirmation' => 'Registration123'])
            ->assertRedirect(route('pharmacies.index'));
        $this->assertDatabaseHas('users', ['email' => 'newowner@example.test']);
        $this->get('/pharmacies')->assertOk()->assertSee('Belum ada apotek');
        $this->post('/logout')->assertRedirect(route('login'));
        $this->assertGuest();

        $this->withSession(['url.intended' => url('/a/2/roles/create')])
            ->post('/login', ['email' => 'newowner@example.test', 'password' => 'Registration123'])
            ->assertRedirect(route('pharmacies.index'));
        $this->assertAuthenticatedAs(User::where('email', 'newowner@example.test')->firstOrFail());
        $this->get('/pharmacies')->assertOk();
    }

    public function test_registration_returns_to_valid_invitation_for_matching_email(): void
    {
        $this->freezeTime();
        [$owner, $pharmacy] = $this->workspace();
        $role = $pharmacy->roles()->firstOrFail();
        [$invite, $token] = app(AccessManager::class)->invite($pharmacy, $owner->id, 'recipient@example.test', [$role->id]);

        $this->withSession(['url.intended' => route('invitations.show', $token)])
            ->post('/register', ['name' => 'Penerima', 'email' => 'recipient@example.test', 'password' => 'Registration123', 'password_confirmation' => 'Registration123'])
            ->assertRedirect(route('invitations.show', $token));

        $this->get(route('invitations.show', $token))->assertOk()->assertSee('Terima undangan');
        $this->assertDatabaseHas('users', ['email' => $invite->email]);
    }

    public function test_login_does_not_follow_expired_or_external_invitation_urls(): void
    {
        $this->freezeTime();
        [$owner, $pharmacy] = $this->workspace();
        $user = User::factory()->create(['password' => 'Registration123']);
        [$invite, $token] = app(AccessManager::class)->invite($pharmacy, $owner->id, $user->email, [$pharmacy->roles()->firstOrFail()->id]);
        $invite->update(['expires_at' => now()->subMinute()]);

        foreach ([route('invitations.show', $token), 'https://example.test/invitations/'.$token] as $destination) {
            $this->withSession(['url.intended' => $destination])
                ->post('/login', ['email' => $user->email, 'password' => 'Registration123'])
                ->assertRedirect(route('pharmacies.index'));
            $this->post('/logout');
        }
    }

    public function test_created_pharmacy_is_available_after_logout_and_login(): void
    {
        $user = User::factory()->create(['password' => 'Registration123']);
        $this->actingAs($user);
        app(AccessManager::class)->provision($user->id, ['name' => 'Apotek Tetap Tersimpan', 'address' => 'Jalan Melati 10']);
        $pharmacy = Pharmacy::where('name', 'Apotek Tetap Tersimpan')->firstOrFail();
        $this->post('/logout')->assertRedirect(route('login'));

        $this->post('/login', ['email' => $user->email, 'password' => 'Registration123'])
            ->assertRedirect(route('pharmacy.home', $pharmacy));

        $this->get(route('pharmacy.home', $pharmacy))->assertOk()->assertSee('Apotek Tetap Tersimpan');
        $this->get('/pharmacies')->assertOk()->assertSee('Apotek Anda sudah terdaftar.')->assertSee('Apotek Tetap Tersimpan')->assertSee('Buka workspace');
        $this->assertDatabaseHas('memberships', ['pharmacy_id' => $pharmacy->id, 'user_id' => $user->id, 'active' => true]);
        $this->assertDatabaseCount('pharmacies', 1);
    }

    public function test_login_with_multiple_active_pharmacies_opens_selection(): void
    {
        $user = User::factory()->create(['password' => 'Registration123']);
        app(AccessManager::class)->provision($user->id, ['name' => 'Apotek Satu']);
        app(AccessManager::class)->provision($user->id, ['name' => 'Apotek Dua']);

        $this->post('/login', ['email' => $user->email, 'password' => 'Registration123'])
            ->assertRedirect(route('pharmacies.index'));

        $this->get('/pharmacies')->assertOk()->assertSee('Apotek Satu')->assertSee('Apotek Dua')->assertSee('Tambah apotek lain');
    }

    public function test_inactive_pharmacy_membership_is_not_opened_after_login(): void
    {
        $user = User::factory()->create(['password' => 'Registration123']);
        $pharmacy = app(AccessManager::class)->provision($user->id, ['name' => 'Apotek Nonaktif']);
        $pharmacy->memberships()->update(['active' => false]);

        $this->post('/login', ['email' => $user->email, 'password' => 'Registration123'])
            ->assertRedirect(route('pharmacies.index'));

        $this->get('/pharmacies')->assertOk()->assertSee('Belum ada apotek')->assertDontSee('Apotek Nonaktif');
    }

    public function test_database_rejects_cross_tenant_role_pivot(): void
    {
        [$owner, $a] = $this->workspace();
        [$other, $b] = $this->workspace();
        $this->expectException(QueryException::class);
        DB::table('membership_role')->insert(['membership_id' => $a->memberships()->first()->id, 'role_id' => $b->roles()->first()->id, 'pharmacy_id' => $a->id]);
    }
}
