<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class ActivePharmacy
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless($request->route('pharmacy')?->status === 'ACTIVE', 422, 'Apotek belum aktif. Transaksi baru tidak diperbolehkan.');

        return $next($request);
    }
}
