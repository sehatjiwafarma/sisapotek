<?php

namespace Tests\Feature;

use App\Mail\OwnerLoginCode;
use App\Models\User;
use App\Services\AccessManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class OwnerLoginOtpTest extends TestCase
{
    use RefreshDatabase;

    private User $first;

    private User $second;

    private $pharmacy;

    private const PASSWORD = 'OwnerPassword12345';

    protected function setUp(): void
    {
        parent::setUp();
        config(['owner_otp.enabled' => true, 'owner_otp.owners' => ['owner1@example.test', 'owner2@example.test'],
            'owner_otp.mailer' => 'smtp', 'mail.from.address' => 'application@example.test', 'mail.from.name' => 'Aplikasi Apotek']);
        Mail::fake();
        $this->first = User::factory()->create(['email' => 'owner1@example.test', 'password' => self::PASSWORD]);
        $this->second = User::factory()->create(['email' => 'owner2@example.test', 'password' => self::PASSWORD]);
        $this->pharmacy = app(AccessManager::class)->provision($this->first->id, ['name' => 'Apotek OTP']);
        $member = $this->pharmacy->memberships()->create(['user_id' => $this->second->id, 'active' => true]);
        app(AccessManager::class)->syncRoles($member, $this->pharmacy->roles()->where('is_owner', true)->get());
        $this->get(route('login'))->assertOk();
    }

    private function start(?User $user = null): void
    {
        $this->post(route('login'), ['email' => ($user ?? $this->first)->email, 'password' => self::PASSWORD, 'remember' => true])
            ->assertRedirect(route('owner-otp.show'));
        $this->assertGuest();
    }

    private function code(): string
    {
        return Mail::sent(OwnerLoginCode::class)->last()->code;
    }

    public function test_cross_owner_email_is_from_application_and_login_waits_for_verification(): void
    {
        $this->start();
        Mail::assertSent(OwnerLoginCode::class, fn ($mail) => $mail->hasTo($this->second->email)
            && $mail->envelope()->from->address === 'application@example.test' && $mail->loginEmail === $this->first->email);
        $challenge = DB::table('owner_login_challenges')->first();
        $this->assertNotSame($this->code(), $challenge->code_hash);
        $this->get(route('pharmacies.index'))->assertRedirect(route('login'));
        $this->get(route('owner-otp.show'))->assertOk()->assertSee('o•••@example.test')->assertDontSee($this->code())->assertHeader('Cache-Control', 'no-store, private');
        $this->post(route('owner-otp.verify'), ['otp' => $this->code()])->assertRedirect(route('pharmacy.home', $this->pharmacy));
        $this->assertAuthenticatedAs($this->first);
        $this->get(route('pharmacy.home', $this->pharmacy))->assertOk();
        $this->assertNotNull(DB::table('owner_login_challenges')->first()->consumed_at);
        $this->assertStringNotContainsString($this->code(), json_encode(DB::table('owner_login_events')->get()));
    }

    public function test_second_owner_sends_to_first_owner_and_remember_me_does_not_create_recaller_cookie(): void
    {
        $this->start($this->second);
        Mail::assertSent(OwnerLoginCode::class, fn ($mail) => $mail->hasTo($this->first->email));
        $response = $this->post(route('owner-otp.verify'), ['otp' => $this->code()]);
        $response->assertRedirect()->assertCookieMissing(auth()->guard()->getRecallerName());
        $this->assertAuthenticatedAs($this->second);
    }

    public function test_wrong_password_never_sends_code_and_unprotected_staff_login_is_unchanged(): void
    {
        $this->post(route('login'), ['email' => $this->first->email, 'password' => 'WrongPassword123'])->assertSessionHasErrors('email');
        Mail::assertNothingSent();
        $this->assertGuest();
        $staff = User::factory()->create(['password' => self::PASSWORD]);
        $this->post(route('login'), ['email' => $staff->email, 'password' => self::PASSWORD])->assertRedirect(route('pharmacies.index'));
        $this->assertAuthenticatedAs($staff);
        Mail::assertNothingSent();
    }

    public function test_wrong_codes_are_counted_and_fifth_attempt_revokes_challenge_without_flashing_code(): void
    {
        $this->start();
        $wrong = $this->code() === '111111' ? '222222' : '111111';
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $response = $this->from(route('owner-otp.show'))->post(route('owner-otp.verify'), ['otp' => $wrong]);
            $this->assertGuest();
            $this->assertSame($attempt, DB::table('owner_login_challenges')->first()->attempts);
            $response->assertSessionMissing('_old_input.otp');
        }
        $response->assertRedirect(route('login'));
        $this->assertNotNull(DB::table('owner_login_challenges')->first()->consumed_at);
        $this->assertDatabaseHas('owner_login_events', ['action' => 'owner_otp.locked']);
    }

    public function test_expired_code_cannot_authenticate(): void
    {
        $this->start();
        $code = $this->code();
        $this->travel(5)->minutes();
        $this->post(route('owner-otp.verify'), ['otp' => $code])->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertDatabaseHas('owner_login_events', ['action' => 'owner_otp.expired']);
    }

    public function test_resend_cooldown_and_delivery_limit_persist_across_new_login_attempts(): void
    {
        $this->start();
        $firstId = DB::table('owner_login_challenges')->first()->id;
        $this->post(route('owner-otp.resend'))->assertSessionHasErrors('otp');
        Mail::assertSentCount(1);
        $this->travel(61)->seconds();
        $this->post(route('owner-otp.resend'))->assertRedirect(route('owner-otp.show'));
        Mail::assertSentCount(2);
        $this->assertNotNull(DB::table('owner_login_challenges')->where('id', $firstId)->value('consumed_at'));
        $this->travel(61)->seconds();
        $this->post(route('owner-otp.resend'))->assertRedirect();
        Mail::assertSentCount(3);
        $this->travel(61)->seconds();
        $this->post(route('owner-otp.resend'))->assertSessionHasErrors('otp');
        Mail::assertSentCount(3);
        $this->post(route('login'), ['email' => $this->first->email, 'password' => self::PASSWORD])->assertSessionHasErrors('otp');
        $this->assertGuest();
        Mail::assertSentCount(3);
    }

    public function test_challenge_is_bound_to_browser_session_and_password_snapshot(): void
    {
        $this->start();
        $code = $this->code();
        $this->withSession(['owner_otp.pending.binding' => 'another-browser']);
        $this->post(route('owner-otp.verify'), ['otp' => $code])->assertRedirect(route('login'));
        $this->assertGuest();
        $this->travel(61)->seconds();
        $this->start();
        $code = $this->code();
        $this->first->forceFill(['password' => Hash::make('ChangedPassword123')])->save();
        $this->post(route('owner-otp.verify'), ['otp' => $code])->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_recipient_role_change_or_pair_configuration_change_invalidates_pending_code(): void
    {
        $this->start();
        $code = $this->code();
        $this->pharmacy->memberships()->where('user_id', $this->second->id)->update(['active' => false]);
        $this->post(route('owner-otp.verify'), ['otp' => $code])->assertRedirect(route('login'));
        $this->assertGuest();
        $this->pharmacy->memberships()->where('user_id', $this->second->id)->update(['active' => true]);
        $this->travel(61)->seconds();
        $this->start();
        config(['owner_otp.owners' => [$this->second->email, $this->first->email]]);
        $this->post(route('owner-otp.verify'), ['otp' => $this->code()])->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_unpaired_owner_bad_sender_and_log_transport_fail_closed(): void
    {
        $third = User::factory()->create(['password' => self::PASSWORD]);
        app(AccessManager::class)->provision($third->id, ['name' => 'Other owner']);
        $this->post(route('login'), ['email' => $third->email, 'password' => self::PASSWORD])->assertSessionHasErrors('otp');
        config(['mail.from.address' => $this->first->email]);
        $this->post(route('login'), ['email' => $this->first->email, 'password' => self::PASSWORD])->assertSessionHasErrors('otp');
        config(['mail.from.address' => 'application@example.test', 'owner_otp.mailer' => 'log']);
        $this->post(route('login'), ['email' => $this->first->email, 'password' => self::PASSWORD])->assertSessionHasErrors('otp');
        $this->assertGuest();
        Mail::assertNothingSent();
        $this->assertDatabaseCount('owner_login_challenges', 0);
    }

    public function test_delivery_failure_never_authenticates_and_revokes_the_code(): void
    {
        Mail::shouldReceive('mailer')->once()->with('smtp')->andThrow(new \RuntimeException('Private transport details'));
        $this->postJson(route('login'), ['email' => $this->first->email, 'password' => self::PASSWORD])->assertUnprocessable()->assertDontSee('Private transport details');
        $this->assertGuest();
        $this->assertNotNull(DB::table('owner_login_challenges')->first()->consumed_at);
        $this->assertDatabaseHas('owner_login_events', ['action' => 'owner_otp.delivery_failed']);
    }

    public function test_existing_owner_session_requires_fresh_login_and_api_cannot_bypass_gate(): void
    {
        $this->actingAs($this->first)->get(route('pharmacies.index'))->assertRedirect(route('login'));
        $this->assertGuest();
        $this->actingAs($this->first)->getJson(route('pharmacies.index'))->assertUnauthorized();
        $this->assertGuest();
    }

    public function test_cancel_and_replay_cannot_reuse_code(): void
    {
        $this->start();
        $code = $this->code();
        $this->post(route('owner-otp.cancel'))->assertRedirect(route('login'));
        $this->post(route('owner-otp.verify'), ['otp' => $code])->assertRedirect(route('login'));
        $this->assertGuest();
        $this->travel(61)->seconds();
        $this->start();
        $code = $this->code();
        $this->post(route('owner-otp.verify'), ['otp' => $code])->assertRedirect();
        $this->post(route('logout'))->assertRedirect();
        $this->post(route('owner-otp.verify'), ['otp' => $code])->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_disabled_feature_keeps_existing_local_login_working_without_mail(): void
    {
        config(['owner_otp.enabled' => false, 'owner_otp.owners' => ['', '']]);
        $this->post(route('login'), ['email' => $this->first->email, 'password' => self::PASSWORD])->assertRedirect(route('pharmacy.home', $this->pharmacy));
        $this->assertAuthenticatedAs($this->first);
        Mail::assertNothingSent();
    }
}
