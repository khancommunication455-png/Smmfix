<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class FundsController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    public function index()
    {
        return view('funds.index');
    }

    public function stripe(Request $request)
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:1|max:10000'
        ]);

        // Stripe integration placeholder
        // Full implementation requires: Stripe API key, checkout session creation, webhook handling

        return back()->with('info', 'Stripe integration requires setup. Add STRIPE_SECRET to .env');
    }

    public function paypal(Request $request)
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:1|max:10000'
        ]);

        // PayPal integration placeholder
        // Full implementation requires: PayPal SDK, OAuth setup, order creation

        return back()->with('info', 'PayPal integration requires setup. Add PAYPAL_CLIENT_ID and PAYPAL_SECRET to .env');
    }

    public function manual(Request $request)
    {
        $validated = $request->validate([
            'method' => 'required|in:easypaisa,jazzcash,crypto,pm',
            'amount' => 'required|numeric|min:1',
            'reference' => 'required|string|max:100',
        ]);

        try {
            Transaction::create([
                'user_id' => Auth::id(),
                'amount' => $validated['amount'],
                'type' => 'deposit',
                'status' => 'pending',
                'description' => strtoupper($validated['method']) . ' - Manual deposit',
                'reference' => $validated['reference'],
            ]);

            Log::info('Manual deposit created', [
                'user_id' => Auth::id(),
                'method' => $validated['method'],
                'amount' => $validated['amount'],
            ]);

            return back()->with('success', 'Payment submitted! Admin will verify and credit your account within 1-2 hours.');
        } catch (\Exception $e) {
            Log::error('Manual deposit error: ' . $e->getMessage());
            return back()->withErrors(['error' => 'Failed to process deposit. Please try again.']);
        }
    }
}
