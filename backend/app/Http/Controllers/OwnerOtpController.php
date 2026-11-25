<?php

namespace App\Http\Controllers;

use App\Services\OwnerLoginOtp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class OwnerOtpController extends Controller
{
    public function show(Request $request, OwnerLoginOtp $otp): Response|RedirectResponse
    {
        $context = $otp->pending($request);
        if (! $context) {
            $request->session()->forget('owner_otp.pending');

            return redirect()->route('login')->with('warning', 'Permintaan OTP tidak berlaku. Silakan masuk kembali.');
        }
        [$local, $domain] = explode('@', $context['partner']->email, 2);
        $recipient = mb_substr($local, 0, 1).'•••@'.$domain;

        return response()->view('auth.owner-otp', $context + ['recipient' => $recipient])->header('Cache-Control', 'no-store, private');
    }

    public function verify(Request $request, OwnerLoginOtp $otp, AuthController $auth): RedirectResponse
    {
        $data = $request->validate(['otp' => ['required', 'string', 'regex:/^[0-9]{6}$/D']]);
        try {
            $user = $otp->verify($request, $data['otp']);
        } catch (ValidationException $exception) {
            if (! $request->session()->has('owner_otp.pending')) {
                return redirect()->route('login')->withErrors($exception->errors());
            }
            throw $exception;
        }
        Auth::login($user, false);
        $request->session()->regenerate();
        $request->session()->put('owner_otp.verified', $otp->proof($user));

        return $auth->redirectAfterAuthentication($request);
    }

    public function resend(Request $request, OwnerLoginOtp $otp): RedirectResponse
    {
        $context = $otp->pending($request);
        if (! $context) {
            return redirect()->route('login')->with('warning', 'Permintaan OTP tidak berlaku. Silakan masuk kembali.');
        }
        $otp->start($request, $context['user'], true);

        return redirect()->route('owner-otp.show')->with('status', 'Kode baru dikirim ke Owner lainnya. Kode sebelumnya tidak berlaku.');
    }

    public function cancel(Request $request, OwnerLoginOtp $otp): RedirectResponse
    {
        $otp->cancel($request);
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', 'Permintaan login dibatalkan.');
    }
}
