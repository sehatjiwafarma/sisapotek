<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class SystemAdministrator
{
    public function handle(Request $request, Closure $next)
    {
        $ownerStatusAction = $request->routeIs('master.activate', 'master.deactivate', 'api.master.activate', 'api.master.deactivate')
            && $request->route('pharmacy')?->memberships()->with('roles')->where('user_id', $request->user()?->id)->where('active', true)->first()?->isOwner();
        abort_unless($request->user()?->is_system_admin || $ownerStatusAction, 403, 'Akses memerlukan Admin Sistem atau Owner apotek untuk perubahan status apoteknya.');

        return $next($request);
    }
}
