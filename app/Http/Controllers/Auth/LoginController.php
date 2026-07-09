<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class LoginController extends Controller
{
    public function show()
    {
        if (Auth::check()) {
            return redirect($this->landingFor(Auth::user()));
        }
        return Inertia::render('Auth/Login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email'    => 'required|email',
            'password' => 'required',
        ]);

        if (Auth::attempt($credentials, $request->boolean('remember'))) {
            $request->session()->regenerate();
            Auth::user()->claimGuestOrders();
            return redirect()->intended($this->safeRedirect($request) ?? $this->landingFor(Auth::user()));
        }

        return back()->withErrors([
            'email' => 'Invalid credentials.',
        ])->onlyInput('email');
    }

    private function landingFor($user): string
    {
        return $user && $user->is_instructor
            ? route('admin.dashboard')
            : route('account.dashboard');
    }

    // Only ever follow a same-origin, relative path passed from the auth
    // modal (e.g. "Start learning" on a gated course page) — never an
    // absolute/external URL, to avoid an open redirect.
    private function safeRedirect(Request $request): ?string
    {
        $redirect = $request->input('redirect');
        if (!is_string($redirect) || $redirect === '' || !str_starts_with($redirect, '/') || str_starts_with($redirect, '//')) {
            return null;
        }
        return $redirect;
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect()->route('login');
    }
}
