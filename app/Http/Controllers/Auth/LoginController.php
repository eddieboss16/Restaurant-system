<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class LoginController extends Controller
{
    public function show(): View
    {
        return view('auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        if (! Auth::attempt($credentials, true)) {
            return back()->withErrors([
                'email' => 'Invalid email or password.',
            ])->onlyInput('email');
        }

        $user = Auth::user();

        if (! $user->is_active) {
            Auth::logout();
            return back()->withErrors(['email' => 'Account is inactive.']);
        }

        $request->session()->regenerate();

        $token = $user->createToken('web-session', [$user->role])->plainTextToken;
        $request->session()->put('api_token', $token);

        return redirect()->intended(
            match ($user->role) {
                'admin' => '/admin/dashboard',
                'kitchen' => '/kitchen/dashboard',
                default => '/waiter/dashboard',
            }
        );
    }

    public function logout(Request $request): RedirectResponse
    {
        if ($user = $request->user()) {
            $user->tokens()->delete();
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }
}
