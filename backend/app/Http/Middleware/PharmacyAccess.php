<?php

namespace App\Http\Middleware;

use App\Models\Membership;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;

class PharmacyAccess
{
    public function handle(Request $request, Closure $next)
    {
        $pharmacy = $request->route('pharmacy');
        $membership = Membership::with('roles')->where('pharmacy_id', $pharmacy->id)
            ->where('user_id', $request->user()->id)->where('active', true)->first();
        if (! $membership && $request->user()->is_system_admin && $request->routeIs('master.*', 'api.master.*')) {
            $membership = new Membership(['pharmacy_id' => $pharmacy->id, 'active' => true]);
            $membership->setRelation('roles', collect());
        }
        abort_unless($membership, 403, 'Anda tidak memiliki akses aktif ke apotek ini.');
        $request->attributes->set('membership', $membership);
        View::share(['currentPharmacy' => $pharmacy, 'access' => $membership]);

        $response = $next($request);
        if (! $response->headers->hasCacheControlDirective('no-store')) {
            $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        }

        return $response;
    }
}
