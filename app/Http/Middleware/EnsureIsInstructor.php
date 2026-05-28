<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureIsInstructor
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if (!$user || !$user->is_instructor) {
            return redirect()->route('account.dashboard');
        }
        return $next($request);
    }
}
