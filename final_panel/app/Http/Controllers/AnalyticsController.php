<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Support\Facades\Auth;

class AnalyticsController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    public function index()
    {
        $user = Auth::user();

        $totalSpent = Order::where('user_id', $user->id)
            ->where('status', 'completed')
            ->sum('total');

        $totalOrders = Order::where('user_id', $user->id)->count();

        $ordersThisMonth = Order::where('user_id', $user->id)
            ->whereMonth('created_at', now()->month)
            ->count();

        $completed = Order::where('user_id', $user->id)
            ->where('status', 'completed')
            ->count();

        $pending = Order::where('user_id', $user->id)
            ->where('status', 'pending')
            ->count();

        $processing = Order::where('user_id', $user->id)
            ->where('status', 'in progress')
            ->count();

        $cancelled = Order::where('user_id', $user->id)
            ->where('status', 'cancelled')
            ->count();

        // Last 30 days spending chart
        $chartRaw = Order::where('user_id', $user->id)
            ->where('status', 'completed')
            ->where('created_at', '>=', now()->subDays(29))
            ->selectRaw('DATE(created_at) as day, SUM(total) as total')
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->keyBy('day');

        $chartLabels = [];
        $chartData = [];

        for ($i = 29; $i >= 0; $i--) {
            $day = now()->subDays($i)->format('Y-m-d');
            $chartLabels[] = now()->subDays($i)->format('M d');
            $chartData[] = round($chartRaw[$day]->total ?? 0, 4);
        }

        // Top services
        $topServices = Order::where('user_id', $user->id)
            ->where('status', 'completed')
            ->join('services', 'orders.service_id', '=', 'services.id')
            ->selectRaw('services.name as service_name, SUM(orders.total) as total_spent, COUNT(*) as order_count')
            ->groupBy('services.id', 'services.name')
            ->orderByDesc('total_spent')
            ->take(8)
            ->get();

        $bestService = $topServices->first()?->service_name;

        return view('analytics.index', compact(
            'totalSpent',
            'totalOrders',
            'ordersThisMonth',
            'completed',
            'pending',
            'processing',
            'cancelled',
            'chartLabels',
            'chartData',
            'topServices',
            'bestService'
        ));
    }
}
