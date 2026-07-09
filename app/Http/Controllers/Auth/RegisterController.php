<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;

class RegisterController extends Controller
{
    public function show()
    {
        if (Auth::check()) {
            return redirect()->route('account.dashboard');
        }
        return Inertia::render('Auth/Register');
    }

    public function register(Request $request)
    {
        $validated = $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|max:255|unique:users,email',
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        // The User model's `password => 'hashed'` cast handles hashing.
        $user = User::create($validated);

        Auth::login($user);
        $request->session()->regenerate();
        $user->claimGuestOrders();

        // Honor the intended URL so guests redirected here from a gated
        // library/course page land back on it after signing up. The auth
        // modal also passes an explicit `redirect` when opened in place
        // (e.g. "Start learning" on a course page, no server redirect ever
        // fired), which takes priority when present.
        return redirect()->intended($this->safeRedirect($request) ?? route('account.dashboard'));
    }

    // Only ever follow a same-origin, relative path — never an
    // absolute/external URL, to avoid an open redirect.
    private function safeRedirect(Request $request): ?string
    {
        $redirect = $request->input('redirect');
        if (!is_string($redirect) || $redirect === '' || !str_starts_with($redirect, '/') || str_starts_with($redirect, '//')) {
            return null;
        }
        return $redirect;
    }
}
