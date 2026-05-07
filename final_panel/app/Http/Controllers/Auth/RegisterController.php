<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Auth\Events\Registered;

class RegisterController extends Controller
{
    public function __construct()
    {
        $this->middleware('guest');
    }

    public function showRegistrationForm()
    {
        return view('auth.register');
    }

    public function register(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|min:3',
            'email' => 'required|email|unique:users,email|max:255',
            'password' => 'required|min:8|confirmed|regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)/,
            'referral_code' => 'nullable|string|exists:users,referral_code',
            'terms' => 'accepted',
        ], [
            'password.regex' => 'Password must contain at least one uppercase letter, one lowercase letter, and one number.',
            'terms.accepted' => 'You must accept the terms and conditions.',
        ]);

        try {
            $referrer = null;

            if (!empty($validated['referral_code'])) {
                $referrer = User::where('referral_code', $validated['referral_code'])
                    ->where('status', 'active')
                    ->first();

                if (!$referrer) {
                    return back()
                        ->withInput()
                        ->withErrors(['referral_code' => 'Invalid referral code.']);
                }
            }

            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
                'funds' => 0,
                'referred_by' => $referrer?->id,
                'status' => 'active',
            ]);

            event(new Registered($user));

            Auth::login($user);

            Log::info('User registered', [
                'user_id' => $user->id,
                'email' => $user->email,
                'referred_by' => $referrer?->id,
            ]);

            return redirect()->route('dashboard')
                ->with('success', 'Welcome! Your account has been created.');
        } catch (\Exception $e) {
            Log::error('Registration failed: ' . $e->getMessage());
            return back()
                ->withInput()
                ->withErrors(['error' => 'Registration failed. Please try again.']);
        }
    }
}

