<?php

namespace App\Http\Middleware;

use App\Services\OwnerLoginOtp;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureOwnerOtp
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $otp = app(OwnerLoginOtp::class);
        if ($user && $otp->required($user) && ! $otp->verified($request, $user)) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Login Owner memerlukan OTP silang. Silakan masuk kembali.'], 401);
            }

            return redirect()->route('login')->with('warning', 'Login Owner memerlukan OTP silang. Silakan masuk kembali.');
        }

        return $next($request);
    }
}
