<?php
// app/Http/Middleware/IsAdmin.php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class IsAdmin
{
    public function handle(Request $request, Closure $next)
    {
        if (! session('admin_authenticated')) {
            return redirect()->route('admin.login');
        }

        return $next($request);
    }
}
