<?php

namespace App\Services;

use App\Mail\OwnerLoginCode;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class OwnerLoginOtp
{
    public function required(User $user): bool
    {
        return config('owner_otp.enabled') && (in_array(mb_strtolower($user->email), $this->emails(), true) || $this->isOwner($user));
    }

    private function isOwner(User $user): bool
    {
        return $user->memberships()->where('active', true)->whereHas('roles', fn ($query) => $query->where('is_owner', true))->exists();
    }

    private function emails(): array
    {
        return array_map(fn ($email) => mb_strtolower(trim((string) $email)), config('owner_otp.owners', []));
    }

    private function pairHash(): string
    {
        return hash('sha256', implode('|', $this->emails()));
    }

    public function partner(User $user): User
    {
        $emails = $this->emails();
        if (count($emails) !== 2 || count(array_unique($emails)) !== 2 || ! in_array(mb_strtolower($user->email), $emails, true)
            || ! filter_var($emails[0], FILTER_VALIDATE_EMAIL) || ! filter_var($emails[1], FILTER_VALIDATE_EMAIL) || ! $this->isOwner($user)) {
            $this->fail('Pasangan OTP Owner belum dikonfigurasi dengan benar. Hubungi pengelola aplikasi.');
        }
        $email = $emails[0] === mb_strtolower($user->email) ? $emails[1] : $emails[0];
        $partner = User::where('email', $email)->first();
        if (! $partner || ! $this->isOwner($partner)) {
            $this->fail('Owner penerima OTP belum tersedia atau tidak aktif. Hubungi pengelola aplikasi.');
        }

        return $partner;
    }

    public function proof(User $user): array
    {
        return ['user_id' => $user->id, 'password_hash' => hash('sha256', $user->password), 'pair_hash' => $this->pairHash()];
    }

    public function verified(Request $request, User $user): bool
    {
        $proof = $request->session()->get('owner_otp.verified');
        if (! is_array($proof) || $proof !== $this->proof($user)) {
            return false;
        }
        try {
            $this->partner($user);
        } catch (ValidationException) {
            return false;
        }

        return true;
    }

    private function assertTransport(): void
    {
        $from = config('mail.from.address');
        $transport = config('mail.mailers.'.config('owner_otp.mailer').'.transport');
        if (! filter_var($from, FILTER_VALIDATE_EMAIL) || in_array(mb_strtolower((string) $from), $this->emails(), true)
            || ! in_array($transport, ['smtp', 'sendmail', 'ses', 'ses-v2', 'postmark', 'resend', 'mailgun'], true)) {
            $this->fail('Email aplikasi belum siap untuk mengirim OTP. Hubungi pengelola aplikasi.');
        }
    }

    public function start(Request $request, User $user, bool $resend = false): void
    {
        $partner = $this->partner($user);
        $this->assertTransport();
        $id = (string) Str::uuid();
        $binding = bin2hex(random_bytes(32));
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expires = now()->addMinutes(config('owner_otp.expires_minutes'));
        $authenticatedAt = $resend ? $request->session()->get('owner_otp.pending.authenticated_at') : now()->timestamp;
        DB::transaction(function () use ($user, $partner, $id, $binding, $code, $expires, $request): void {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $recent = DB::table('owner_login_challenges')->where('user_id', $user->id)->where('created_at', '>', now()->subMinutes(config('owner_otp.send_window_minutes')));
            $latest = (clone $recent)->orderByDesc('created_at')->first();
            if ($recent->count() >= config('owner_otp.max_sends')) {
                $this->fail('Batas pengiriman OTP tercapai. Coba lagi setelah 15 menit.');
            }
            if ($latest && Carbon::parse($latest->created_at)->addSeconds(config('owner_otp.resend_seconds'))->isFuture()) {
                $this->fail('Tunggu 60 detik sebelum meminta kode baru.');
            }
            DB::table('owner_login_challenges')->where('user_id', $user->id)->whereNull('consumed_at')->update(['consumed_at' => now()]);
            DB::table('owner_login_challenges')->insert([
                'id' => $id, 'user_id' => $user->id, 'recipient_id' => $partner->id,
                'code_hash' => $this->codeHash($id, $code), 'binding_hash' => hash('sha256', $binding),
                'password_hash' => hash('sha256', $user->password), 'pair_hash' => $this->pairHash(),
                'expires_at' => $expires, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit($request, $user->id, $id, 'owner_otp.issued');
        });
        try {
            Mail::mailer(config('owner_otp.mailer'))->to($partner->email)->send(new OwnerLoginCode(
                $code, $user->email, now('Asia/Jakarta')->format('d/m/Y H:i:s').' WIB',
                $expires->copy()->timezone('Asia/Jakarta')->format('d/m/Y H:i:s').' WIB',
                (string) $request->ip(), Str::limit((string) $request->userAgent(), 200), substr($id, 0, 8),
            ));
        } catch (Throwable) {
            DB::table('owner_login_challenges')->where('id', $id)->update(['consumed_at' => now()]);
            $request->session()->forget('owner_otp.pending');
            $this->audit($request, $user->id, $id, 'owner_otp.delivery_failed');
            // Transport exceptions may include message contents. Never log the exception or OTP.
            $this->fail('OTP tidak berhasil dikirim. Login belum diberikan. Coba lagi atau hubungi pengelola aplikasi.');
        }
        DB::table('owner_login_challenges')->where('id', $id)->whereNull('consumed_at')->update(['delivered_at' => now()]);
        $request->session()->put('owner_otp.pending', ['id' => $id, 'user_id' => $user->id, 'binding' => $binding, 'authenticated_at' => $authenticatedAt]);
        $this->audit($request, $user->id, $id, 'owner_otp.sent');
    }

    public function pending(Request $request): ?array
    {
        $pending = $request->session()->get('owner_otp.pending');
        if (! config('owner_otp.enabled') || ! is_array($pending) || ! isset($pending['id'], $pending['binding'], $pending['user_id'], $pending['authenticated_at'])
            || $pending['authenticated_at'] <= now()->subMinutes(15)->timestamp) {
            return null;
        }
        $challenge = DB::table('owner_login_challenges')->where('id', $pending['id'])->where('user_id', $pending['user_id'])->first();
        $user = User::find($pending['user_id']);
        if (! $challenge || ! $user || $challenge->consumed_at || ! $challenge->delivered_at
            || ! hash_equals($challenge->binding_hash, hash('sha256', $pending['binding']))
            || ! hash_equals($challenge->password_hash, hash('sha256', $user->password)) || ! hash_equals($challenge->pair_hash, $this->pairHash())) {
            return null;
        }
        try {
            $partner = $this->partner($user);
        } catch (ValidationException) {
            return null;
        }
        if ($partner->id !== $challenge->recipient_id) {
            return null;
        }

        return compact('challenge', 'user', 'partner');
    }

    public function verify(Request $request, string $code): User
    {
        $pending = $request->session()->get('owner_otp.pending');
        $context = $this->pending($request);
        if (! $context) {
            $request->session()->forget('owner_otp.pending');
            $this->fail('Permintaan login tidak berlaku. Silakan masuk kembali.');
        }
        $verifiedUser = null;
        $outcome = DB::transaction(function () use ($context, $pending, $code, $request, &$verifiedUser): string {
            $user = User::whereKey($context['user']->id)->lockForUpdate()->firstOrFail();
            $challenge = DB::table('owner_login_challenges')->where('id', $pending['id'])->lockForUpdate()->first();
            if (! $challenge || $challenge->consumed_at || ! $challenge->delivered_at
                || ! hash_equals($challenge->password_hash, hash('sha256', $user->password)) || ! hash_equals($challenge->pair_hash, $this->pairHash())) {
                return 'invalid';
            }
            if (Carbon::parse($challenge->expires_at)->lessThanOrEqualTo(now())) {
                DB::table('owner_login_challenges')->where('id', $challenge->id)->update(['consumed_at' => now()]);
                $this->audit($request, $user->id, $challenge->id, 'owner_otp.expired');

                return 'expired';
            }
            $attempts = $challenge->attempts + 1;
            $correct = hash_equals($challenge->code_hash, $this->codeHash($challenge->id, $code));
            $locked = $attempts >= config('owner_otp.max_attempts');
            DB::table('owner_login_challenges')->where('id', $challenge->id)->update([
                'attempts' => $attempts, 'consumed_at' => $correct || $locked ? now() : null, 'updated_at' => now(),
            ]);
            $this->audit($request, $user->id, $challenge->id, $correct ? 'owner_otp.verified' : ($locked ? 'owner_otp.locked' : 'owner_otp.failed'));

            if ($correct) {
                $verifiedUser = $user;
            }

            return $correct ? 'verified' : ($locked ? 'locked' : 'wrong');
        });
        if ($outcome !== 'verified') {
            if ($outcome !== 'wrong') {
                $request->session()->forget('owner_otp.pending');
            }
            $this->fail($outcome === 'wrong' ? 'Kode OTP tidak sesuai. Periksa kode terbaru dari Owner lainnya.' : 'OTP kedaluwarsa, sudah digunakan, atau batas percobaan tercapai. Silakan masuk kembali.');
        }
        $request->session()->forget('owner_otp.pending');

        return $verifiedUser;
    }

    public function cancel(Request $request): void
    {
        $context = $this->pending($request);
        if ($context) {
            DB::table('owner_login_challenges')->where('id', $context['challenge']->id)->whereNull('consumed_at')->update(['consumed_at' => now()]);
            $this->audit($request, $context['user']->id, $context['challenge']->id, 'owner_otp.cancelled');
        }
        $request->session()->forget('owner_otp.pending');
    }

    private function codeHash(string $id, string $code): string
    {
        return hash_hmac('sha256', $id.'|'.$code, config('app.key'));
    }

    private function audit(Request $request, int $user, string $challenge, string $action): void
    {
        DB::table('owner_login_events')->insert(['user_id' => $user, 'challenge_id' => $challenge, 'action' => $action, 'ip_address' => $request->ip(), 'created_at' => now()]);
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['otp' => $message]);
    }
}
