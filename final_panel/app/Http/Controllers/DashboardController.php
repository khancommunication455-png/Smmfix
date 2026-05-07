<?php
// ── DashboardController ────────────────────────────────────────────────────
namespace App\Http\Controllers;

use App\Models\{Order, Service, Category, Transaction};
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index()
    {
        $user = Auth::user();
        $balance          = $user->funds ?? 0;
        $total_orders     = Order::where('user_id', $user->id)->count();
        $pending_orders   = Order::where('user_id', $user->id)->whereIn('status',['pending','in progress'])->count();
        $completed_orders = Order::where('user_id', $user->id)->where('status','completed')->count();
        $processing_orders= Order::where('user_id', $user->id)->where('status','in progress')->count();
        $orders_this_week = Order::where('user_id', $user->id)->where('created_at','>=',now()->startOfWeek())->count();
        $spent_month      = Order::where('user_id', $user->id)->where('status','completed')->whereMonth('created_at',now()->month)->sum('total');
        $success_rate     = $total_orders > 0 ? round(($completed_orders / $total_orders) * 100, 1) : 99.8;
        $recent_orders    = Order::with('service')->where('user_id',$user->id)->latest()->take(8)->get();
        $categories       = Category::where('status','active')->get();

        // Group services by category for quick order widget
        $services_by_category = Service::where('status','active')
            ->get()
            ->groupBy('category_id')
            ->map(fn($svcs) => $svcs->map(fn($s) => [
                'id'=>$s->id,'name'=>$s->name,'rate'=>$s->rate,'min'=>$s->min,'max'=>$s->max
            ]));

        return view('dashboard.index', compact(
            'balance','total_orders','pending_orders','processing_orders',
            'completed_orders','orders_this_week','spent_month','success_rate',
            'recent_orders','categories','services_by_category'
        ));
    }
}
