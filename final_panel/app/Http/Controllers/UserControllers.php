<?php
namespace App\Http\Controllers;

use App\Models\{Order, User, Ticket, TicketMessage, Transaction};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth, DB};

// ── AnalyticsController ────────────────────────────────────────────────────
class AnalyticsController extends Controller
{
    public function index()
    {
        $user = Auth::user();

        $total_spent     = Order::where('user_id',$user->id)->where('status','completed')->sum('total');
        $total_orders    = Order::where('user_id',$user->id)->count();
        $orders_this_month = Order::where('user_id',$user->id)->whereMonth('created_at',now()->month)->count();
        $completed       = Order::where('user_id',$user->id)->where('status','completed')->count();
        $pending         = Order::where('user_id',$user->id)->where('status','pending')->count();
        $processing      = Order::where('user_id',$user->id)->where('status','in progress')->count();
        $cancelled       = Order::where('user_id',$user->id)->where('status','cancelled')->count();

        // Last 30 days spending chart
        $chart_raw = Order::where('user_id',$user->id)
            ->where('status','completed')
            ->where('created_at','>=',now()->subDays(29))
            ->selectRaw('DATE(created_at) as day, SUM(total) as total')
            ->groupBy('day')->orderBy('day')->get()
            ->keyBy('day');

        $chart_labels = [];
        $chart_data   = [];
        for ($i = 29; $i >= 0; $i--) {
            $day = now()->subDays($i)->format('Y-m-d');
            $chart_labels[] = now()->subDays($i)->format('M d');
            $chart_data[]   = round($chart_raw[$day]->total ?? 0, 4);
        }

        // Top services
        $top_services = Order::where('user_id',$user->id)
            ->where('status','completed')
            ->join('services','orders.service_id','=','services.id')
            ->selectRaw('services.name as service_name, SUM(orders.total) as total_spent, COUNT(*) as order_count')
            ->groupBy('services.id','services.name')
            ->orderByDesc('total_spent')
            ->take(8)->get();

        $best_service = $top_services->first()?->service_name;

        return view('analytics.index', compact(
            'total_spent','total_orders','orders_this_month','completed',
            'pending','processing','cancelled','chart_labels','chart_data',
            'top_services','best_service'
        ));
    }
}

// ── ReferralController ─────────────────────────────────────────────────────
class ReferralController extends Controller
{
    public function index()
    {
        $user = Auth::user();

        $referrals = User::where('referred_by', $user->id)
            ->withCount('orders')
            ->get()
            ->map(function($ref) use ($user) {
                $ref->referral_commission = Transaction::where('user_id', $user->id)
                    ->where('description','LIKE','%referral%'.$ref->id.'%')
                    ->sum('amount');
                return $ref;
            });

        $stats = [
            'total_referrals' => $referrals->count(),
            'total_earned'    => Transaction::where('user_id',$user->id)
                                    ->where('type','referral_bonus')->sum('amount'),
            'earned_month'    => Transaction::where('user_id',$user->id)
                                    ->where('type','referral_bonus')
                                    ->whereMonth('created_at',now()->month)->sum('amount'),
        ];

        return view('referrals.index', compact('referrals','stats'));
    }
}

// ── TicketController ───────────────────────────────────────────────────────
class TicketController extends Controller
{
    public function index()
    {
        $tickets = Ticket::where('user_id',Auth::id())->latest()->get();
        return view('support.index', compact('tickets'));
    }

    public function create()
    {
        $tickets = Ticket::where('user_id',Auth::id())->latest()->take(10)->get();
        return view('support.index', compact('tickets'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'subject'  => 'required|string|max:255',
            'message'  => 'required|string|max:5000',
            'category' => 'required|in:order,payment,technical,other',
            'order_id' => 'nullable|integer|exists:orders,id',
        ]);

        $ticket = Ticket::create([
            'user_id'   => Auth::id(),
            'subject'   => $request->subject,
            'message'   => $request->message,
            'category'  => $request->category,
            'order_id'  => $request->order_id,
            'status'    => 'open',
        ]);

        TicketMessage::create([
            'ticket_id' => $ticket->id,
            'user_id'   => Auth::id(),
            'message'   => $request->message,
            'is_admin'  => false,
        ]);

        return redirect()->route('tickets.show', $ticket->id)
            ->with('success', 'Ticket #'.$ticket->id.' opened. We\'ll respond within 2-4 hours.');
    }

    public function show(Ticket $ticket)
    {
        abort_unless($ticket->user_id === Auth::id(), 403);
        $ticket->load('messages');
        $tickets = Ticket::where('user_id',Auth::id())->latest()->get();
        return view('support.index', compact('ticket','tickets'));
    }

    public function reply(Request $request, Ticket $ticket)
    {
        abort_unless($ticket->user_id === Auth::id(), 403);
        $request->validate(['message' => 'required|string|max:5000']);

        TicketMessage::create([
            'ticket_id' => $ticket->id,
            'user_id'   => Auth::id(),
            'message'   => $request->message,
            'is_admin'  => false,
        ]);

        $ticket->update(['status' => 'open']);
        return back()->with('success', 'Reply sent.');
    }
}

// ── TransactionController ──────────────────────────────────────────────────
class TransactionController extends Controller
{
    public function index()
    {
        $transactions = Transaction::where('user_id', Auth::id())
            ->latest()->paginate(30);
        return view('transactions.index', compact('transactions'));
    }
}
