<?php

namespace App\Http\Controllers;

use App\Models\Invitation;
use App\Models\User;
use App\Services\OwnerLoginOtp;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request, OwnerLoginOtp $otp)
    {
        $data = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string'], 'remember' => ['nullable', 'boolean']]);
        $credentials = ['email' => mb_strtolower(trim($data['email'])), 'password' => $data['password']];
        $guard = Auth::guard('web');
        if (! $guard->validate($credentials)) {
            throw ValidationException::withMessages(['email' => 'Email atau password tidak sesuai.']);
        }
        $user = $guard->getLastAttempted();
        if ($otp->required($user)) {
            $guard->getProvider()->rehashPasswordIfRequired($user, $credentials);
            $request->session()->regenerate();
            $otp->start($request, $user);

            return redirect()->route('owner-otp.show');
        }
        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages(['email' => 'Email atau password tidak sesuai.']);
        }
        $request->session()->regenerate();

        return $this->redirectAfterAuthentication($request);
    }

    public function register(Request $request, OwnerLoginOtp $otp)
    {
        $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);
        $data = $request->validate(['name' => ['required', 'string', 'max:150'], 'email' => ['required', 'email', 'max:255', 'unique:users'], 'password' => ['required', 'confirmed', PasswordRule::min(12)->letters()->numbers()]]);
        $user = User::create($data);
        if ($otp->required($user)) {
            $request->session()->regenerate();
            $otp->start($request, $user);

            return redirect()->route('owner-otp.show');
        }
        Auth::login($user);
        $request->session()->regenerate();

        return $this->redirectAfterAuthentication($request);
    }

    public function redirectAfterAuthentication(Request $request): RedirectResponse
    {
        $intended = $request->session()->pull('url.intended');

        if (is_string($intended) && preg_match('~/invitations/([a-f0-9]{64})$~', $intended, $matches)
            && $intended === route('invitations.show', $matches[1])) {
            $invite = Invitation::where('token_hash', hash('sha256', $matches[1]))
                ->where('email', $request->user()->email)->first();

            if ($invite?->usable()) {
                return redirect()->route('invitations.show', $matches[1]);
            }
        }

        if ($request->user()->is_system_admin) {
            return redirect()->route('system.pharmacies');
        }
        $memberships = $request->user()->memberships()->whereHas('pharmacy')->where('active', true)->limit(2)->get();

        if ($memberships->count() === 1) {
            return redirect()->route('pharmacy.home', $memberships->first()->pharmacy_id);
        }

        return redirect()->route('pharmacies.index');
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    public function forgot(Request $request)
    {
        $data = $request->validate(['email' => ['required', 'email']]);
        Password::sendResetLink(['email' => mb_strtolower(trim($data['email']))]);

        return back()->with('status', 'Jika akun terdaftar, tautan reset password akan dikirim ke email Anda.');
    }

    public function reset(Request $request)
    {
        $data = $request->validate(['token' => ['required'], 'email' => ['required', 'email'], 'password' => ['required', 'confirmed', PasswordRule::min(12)->letters()->numbers()]]);
        $status = Password::reset($data, function (User $user, string $password) {
            DB::transaction(function () use ($user, $password) {
                $user->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60)])->save();
                DB::table('sessions')->where('user_id', $user->id)->delete();
            });
            event(new PasswordReset($user));
        });
        if ($status !== Password::PasswordReset) {
            throw ValidationException::withMessages(['email' => 'Tautan reset tidak valid atau kedaluwarsa.']);
        }

        return redirect()->route('login')->with('status', 'Password diperbarui. Silakan masuk kembali.');
    }

    public function profile(Request $request)
    {
        return view('auth.profile', ['user' => $request->user()]);
    }

    public function updateProfile(Request $request)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:150'], 'phone' => ['nullable', 'string', 'max:30']]);
        $request->user()->update($data);

        return back()->with('status', 'Profil disimpan.');
    }

    public function password(Request $request)
    {
        $data = $request->validate(['current_password' => ['required', 'current_password'], 'password' => ['required', 'confirmed', 'different:current_password', PasswordRule::min(12)->letters()->numbers()]]);
        $request->user()->forceFill(['password' => $data['password'], 'remember_token' => Str::random(60)])->save();
        DB::table('sessions')->where('user_id', $request->user()->id)->where('id', '!=', $request->session()->getId())->delete();
        $request->session()->put('password_hash_web', $request->user()->password);
        $request->session()->regenerate();

        return back()->with('status', 'Password diperbarui; sesi perangkat lain dicabut.');
    }
}
