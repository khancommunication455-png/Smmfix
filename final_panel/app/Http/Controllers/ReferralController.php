<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Transaction;
use Illuminate\Support\Facades\Auth;

class ReferralController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    public function index()
    {
        $user = Auth::user();

        $referrals = User::where('referred_by', $user->id)
            ->withCount('orders')
            ->get()
            ->map(function ($ref) use ($user) {
                $ref->referral_commission = Transaction::where('user_id', $user->id)
                    ->where('type', 'referral_bonus')
                    ->where('description', 'like', '%referral%' . $ref->id . '%')
                    ->sum('amount');
                return $ref;
            });

        $stats = [
            'total_referrals' => $referrals->count(),
            'total_earned' => Transaction::where('user_id', $user->id)
                ->where('type', 'referral_bonus')
                ->sum('amount'),
            'earned_month' => Transaction::where('user_id', $user->id)
                ->where('type', 'referral_bonus')
                ->whereMonth('created_at', now()->month)
                ->sum('amount'),
        ];

        return view('referrals.index', compact('referrals', 'stats'));
    }
}
