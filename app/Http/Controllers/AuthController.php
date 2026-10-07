<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function create(): View
    {
        return view('login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::query()->where('email', $credentials['email'])->first();
        if ($user?->two_factor_secret) {
            throw ValidationException::withMessages(['email' => 'This demo does not yet support sign-in for accounts with two-factor authentication.']);
        }

        if (! Auth::attempt($credentials)) {
            throw ValidationException::withMessages(['email' => 'The provided credentials do not match our records.']);
        }

        $request->session()->regenerate();

        return redirect()->route('demo.index');
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
