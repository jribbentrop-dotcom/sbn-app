<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureIsInstructor
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->is_instructor) {
            // API callers get a clean 403, not a 302 to an HTML page they can't
            // use. Both the JSON `Accept` header and the api/* path are checked
            // because a bare fetch() may omit the header. Web admin routes fall
            // through to the dashboard redirect as before.
            if ($request->expectsJson() || $request->is('api/*')) {
                abort(403, 'Instructor access required.');
            }

            return redirect()->route('account.dashboard');
        }

        return $next($request);
    }
}
