<?php

use App\Http\Middleware\ActivePharmacy;
use App\Http\Middleware\EnsureOwnerOtp;
use App\Http\Middleware\PharmacyAccess;
use App\Http\Middleware\RequirePermission;
use App\Http\Middleware\SystemAdministrator;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [EnsureOwnerOtp::class]);
        $middleware->alias([
            'pharmacy' => PharmacyAccess::class,
            'permission' => RequirePermission::class,
            'system.admin' => SystemAdministrator::class,
            'pharmacy.active' => ActivePharmacy::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['otp']);
        $exceptions->render(function (QueryException $e, Request $request) {
            if (in_array((string) $e->getCode(), ['23505', '23000'], true)
                && Str::contains($e->getMessage(), ['pharmacies_code_unique', 'pharmacy_code_case_insensitive', 'pharmacies.code'])) {
                return response()->json(['message' => 'Kode apotek sudah digunakan.'], 409);
            }
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
