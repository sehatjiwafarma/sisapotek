<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RequirePermission
{
    public function handle(Request $request, Closure $next, string $permission)
    {
        abort_unless($request->attributes->get('membership')?->allows($permission), 403, 'Anda tidak memiliki izin untuk aksi ini.');

        return $next($request);
    }
}
