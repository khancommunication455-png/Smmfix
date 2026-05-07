<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\User;
use App\Models\Service;
use App\Models\Category;
use App\Models\ApiProvider;
use App\Models\Transaction;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\Log as ActivityLog;
use App\Services\ProviderApiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;

class AdminController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware(function ($request, $next) {
            abort_unless(Auth::user()?->is_admin, 403, 'Admin access required.');
            return $next($request);
        });
    }

    public function dashboard()
    {
        $totalRevenue = Transaction::where('type', 'deposit')
            ->where('status', 'completed')
            ->sum('amount');

        $totalOrders = Order::count();
        $activeUsers = User::where('status', 'active')->count();
        $pendingOrders = Order::whereIn('status', ['pending', 'in progress'])->count();
        $pendingTransactions = Transaction::where('status', 'pending')->count();
        $openTickets = Ticket::where('status', '!=', 'closed')->count();

        $recentOrders = Order::with(['user', 'service'])->latest()->take(10)->get();
        $recentUsers = User::latest()->take(6)->get();
        $providers = ApiProvider::withCount('services')->get();

        return view('admin.dashboard', compact(
            'totalRevenue', 'totalOrders', 'activeUsers', 'pendingOrders',
            'pendingTransactions', 'openTickets', 'recentOrders', 'recentUsers', 'providers'
        ));
    }

    public function providersIndex()
    {
        $providers = ApiProvider::withCount('services')->get();
        return view('admin.providers.index', compact('providers'));
    }

    public function providersCreate()
    {
        return view('admin.providers.create');
    }

    public function providersStore(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100|unique:api_providers',
            'url' => 'required|url|max:255',
            'api_key' => 'required|string|max:255',
            'percentage_increase' => 'required|numeric|min:0|max:10000',
        ]);

        try {
            $provider = ApiProvider::create($validated + ['status' => 'active']);
            Log::info('API Provider created', ['provider_id' => $provider->id, 'admin_id' => Auth::id()]);
            return redirect()->route('admin.providers.index')
                ->with('success', 'Provider added successfully. Click Sync to import services.');
        } catch (\Exception $e) {
            Log::error('Provider creation failed: ' . $e->getMessage());
            return back()->withInput()->withErrors(['error' => 'Failed to create provider.']);
        }
    }

    public function providersEdit(ApiProvider $provider)
    {
        return view('admin.providers.edit', compact('provider'));
    }

    public function providersUpdate(Request $request, ApiProvider $provider)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100|unique:api_providers,name,' . $provider->id,
            'url' => 'required|url|max:255',
            'api_key' => 'required|string|max:255',
            'percentage_increase' => 'required|numeric|min:0|max:10000',
            'status' => 'required|in:active,inactive',
        ]);

        try {
            $provider->update($validated);
            Log::info('API Provider updated', ['provider_id' => $provider->id, 'admin_id' => Auth::id()]);
            return back()->with('success', 'Provider updated successfully.');
        } catch (\Exception $e) {
            Log::error('Provider update failed: ' . $e->getMessage());
            return back()->withInput()->withErrors(['error' => 'Failed to update provider.']);
        }
    }

    public function syncProvider(ApiProvider $provider)
    {
        try {
            $api = new ProviderApiService($provider);
            $services = $api->getServices();
            $synced = 0;

            foreach (($services ?? []) as $service) {
                $category = Category::firstOrCreate(
                    ['name' => $service['category'] ?? 'General'],
                    ['status' => 'active', 'icon' => 'list_alt', 'color' => '#adc6ff']
                );

                Service::updateOrCreate(
                    [
                        'api_provider_id' => $provider->id,
                        'api_service_id' => $service['service'] ?? $service['id'] ?? null,
                    ],
                    [
                        'name' => $service['name'] ?? 'Unnamed Service',
                        'category_id' => $category->id,
                        'rate' => round(($service['rate'] ?? 0) * (1 + ($provider->percentage_increase / 100)), 6),
                        'min' => $service['min'] ?? 10,
                        'max' => $service['max'] ?? 100000,
                        'status' => 'active',
                        'type' => 'api',
                    ]
                );
                $synced++;
            }

            Log::info('Provider sync completed', ['provider_id' => $provider->id, 'synced_services' => $synced, 'admin_id' => Auth::id()]);
            return back()->with('success', "Synced {$synced} services from {$provider->name}.");
        } catch (\Throwable $e) {
            Log::error('Provider sync failed: ' . $e->getMessage(), ['provider_id' => $provider->id]);
            return back()->with('error', 'Sync failed: ' . $e->getMessage());
        }
    }

    public function syncAll()
    {
        $synced = 0;

        foreach (ApiProvider::where('status', 'active')->get() as $provider) {
            try {
                $api = new ProviderApiService($provider);
                $services = $api->getServices();

                if (!is_array($services)) continue;

                foreach ($services as $service) {
                    $category = Category::firstOrCreate(
                        ['name' => $service['category'] ?? 'General'],
                        ['status' => 'active', 'icon' => 'list_alt', 'color' => '#adc6ff']
                    );

                    Service::updateOrCreate(
                        [
                            'api_provider_id' => $provider->id,
                            'api_service_id' => $service['service'] ?? $service['id'] ?? null,
                        ],
                        [
                            'name' => $service['name'] ?? 'Unnamed Service',
                            'category_id' => $category->id,
                            'rate' => round(($service['rate'] ?? 0) * (1 + ($provider->percentage_increase / 100)), 6),
                            'min' => $service['min'] ?? 10,
                            'max' => $service['max'] ?? 100000,
                            'status' => 'active',
                            'type' => 'api',
                        ]
                    );
                    $synced++;
                }
            } catch (\Throwable $e) {
                Log::error("Sync failed for provider {$provider->id}: " . $e->getMessage());
            }
        }

        Log::info('All providers synced', ['synced_services' => $synced, 'admin_id' => Auth::id()]);
        return response()->json(['message' => "Synced {$synced} services successfully."]);
    }

    public function syncServices()
    {
        return $this->syncAll();
    }

    public function syncOrders()
    {
        try {
            $updated = 0;
            $pendingOrders = Order::whereIn('status', ['pending', 'in progress'])
                ->where('api_order_id', '!=', null)
                ->with('service.apiProvider')
                ->get();

            foreach ($pendingOrders as $order) {
                try {
                    if (!$order->service || !$order->service->apiProvider) continue;

                    $api = new ProviderApiService($order->service->apiProvider);
                    $status = $api->getStatus($order->api_order_id);

                    if (!empty($status['status'])) {
                        $newStatus = match (strtolower($status['status'])) {
                            'completed' => 'completed',
                            'partial' => 'partial',
                            'error', 'failed' => 'error',
                            'cancelled' => 'cancelled',
                            default => $order->status,
                        };

                        if ($newStatus !== $order->status) {
                            $order->update(['status' => $newStatus, 'remains' => $status['remains'] ?? $order->remains]);
                            $updated++;
                        }
                    }
                } catch (\Throwable $e) {
                    Log::warning("Failed to sync order {$order->id}: " . $e->getMessage());
                }
            }

            Log::info('Orders sync completed', ['updated_orders' => $updated, 'admin_id' => Auth::id()]);
            return response()->json(['message' => "Updated {$updated} orders."]);
        } catch (\Exception $e) {
            Log::error('Order sync failed: ' . $e->getMessage());
            return response()->json(['error' => 'Sync failed'], 500);
        }
    }

    public function ordersIndex(Request $request)
    {
        $orders = Order::with(['user', 'service'])
            ->when($request->status, fn($q, $s) => $q->where('status', $s))
            ->when($request->search, fn($q, $s) => $q->where('id', $s)->orWhereHas('user', fn($u) => $u->where('name', 'like', "%$s%")))
            ->latest()
            ->paginate(30);
        return view('admin.orders.index', compact('orders'));
    }

    public function ordersUpdateStatus(Request $request, Order $order)
    {
        $validated = $request->validate(['status' => 'required|in:pending,in progress,completed,cancelled,refunded,partial,error']);

        try {
            $oldStatus = $order->status;
            $order->update(['status' => $validated['status']]);
            Log::info('Order status updated', ['order_id' => $order->id, 'old_status' => $oldStatus, 'new_status' => $validated['status'], 'admin_id' => Auth::id()]);
            return back()->with('success', "Order #{$order->id} status updated to {$validated['status']}.");
        } catch (\Exception $e) {
            Log::error('Order status update failed: ' . $e->getMessage());
            return back()->withErrors(['error' => 'Failed to update order status.']);
        }
    }

    public function usersIndex(Request $request)
    {
        $users = User::withCount('orders')
            ->when($request->search, fn($q, $s) => $q->where('name', 'like', "%$s%")->orWhere('email', 'like', "%$s%"))
            ->latest()
            ->paginate(30);
        return view('admin.users.index', compact('users'));
    }

    public function usersAddFunds(Request $request, User $user)
    {
        $validated = $request->validate(['amount' => 'required|numeric|min:0.01|max:1000000', 'note' => 'nullable|string|max:200']);

        try {
            DB::transaction(function () use ($user, $validated) {
                $user->increment('funds', $validated['amount']);
                Transaction::create([
                    'user_id' => $user->id,
                    'amount' => $validated['amount'],
                    'type' => 'deposit',
                    'status' => 'completed',
                    'description' => 'Admin credit: ' . ($validated['note'] ?? 'Manual top-up'),
                ]);
                Log::info('Admin added funds to user', ['user_id' => $user->id, 'amount' => $validated['amount'], 'admin_id' => Auth::id()]);
            });
            return back()->with('success', "PKR {$validated['amount']} added to {$user->name}'s account.");
        } catch (\Exception $e) {
            Log::error('Failed to add funds: ' . $e->getMessage());
            return back()->withErrors(['error' => 'Failed to add funds.']);
        }
    }

    public function usersBan(Request $request, User $user)
    {
        if ($user->is_admin) return back()->withErrors(['error' => 'Cannot ban admin users.']);
        try {
            $user->update(['status' => 'banned']);
            Log::warning('User banned', ['user_id' => $user->id, 'admin_id' => Auth::id()]);
            return back()->with('success', "User {$user->name} has been banned.");
        } catch (\Exception $e) {
            Log::error('Failed to ban user: ' . $e->getMessage());
            return back()->withErrors(['error' => 'Failed to ban user.']);
        }
    }

    public function usersUnban(Request $request, User $user)
    {
        try {
            $user->update(['status' => 'active']);
            Log::info('User unbanned', ['user_id' => $user->id, 'admin_id' => Auth::id()]);
            return back()->with('success', "User {$user->name} has been unbanned.");
        } catch (\Exception $e) {
            Log::error('Failed to unban user: ' . $e->getMessage());
            return back()->withErrors(['error' => 'Failed to unban user.']);
        }
    }

    public function transactionsIndex(Request $request)
    {
        $transactions = Transaction::with('user')
            ->when($request->status, fn($q, $s) => $q->where('status', $s))
            ->when($request->type, fn($q, $t) => $q->where('type', $t))
            ->latest()
            ->paginate(30);
        return view('admin.transactions.index', compact('transactions'));
    }

    public function transactionsApprove(Request $request, Transaction $transaction)
    {
        if ($transaction->status !== 'pending') return back()->with('error', 'Transaction already processed.');
        try {
            DB::transaction(function () use ($transaction) {
                $transaction->user->increment('funds', $transaction->amount);
                $transaction->update(['status' => 'completed']);
                Log::info('Transaction approved', ['transaction_id' => $transaction->id, 'admin_id' => Auth::id()]);
            });
            return back()->with('success', "Transaction approved — PKR {$transaction->amount} credited to {$transaction->user->name}.");
        } catch (\Exception $e) {
            Log::error('Transaction approval failed: ' . $e->getMessage());
            return back()->withErrors(['error' => 'Failed to approve transaction.']);
        }
    }

    public function transactionsReject(Request $request, Transaction $transaction)
    {
        if ($transaction->status !== 'pending') return back()->with('error', 'Transaction already processed.');
        try {
            $transaction->update(['status' => 'failed']);
            Log::info('Transaction rejected', ['transaction_id' => $transaction->id, 'admin_id' => Auth::id()]);
            return back()->with('success', "Transaction rejected.");
        } catch (\Exception $e) {
            Log::error('Transaction rejection failed: ' . $e->getMessage());
            return back()->withErrors(['error' => 'Failed to reject transaction.']);
        }
    }

    public function ticketsIndex()
    {
        $tickets = Ticket::with('user')->latest()->paginate(20);
        return view('admin.tickets.index', compact('tickets'));
    }

    public function ticketsReply(Request $request, Ticket $ticket)
    {
        $validated = $request->validate(['message' => 'required|string|max:5000|min:3']);
        try {
            TicketMessage::create(['ticket_id' => $ticket->id, 'user_id' => Auth::id(), 'message' => $validated['message'], 'is_admin' => true]);
            $ticket->update(['status' => 'pending']);
            Log::info('Admin replied to ticket', ['ticket_id' => $ticket->id, 'admin_id' => Auth::id()]);
            return back()->with('success', 'Reply sent.');
        } catch (\Exception $e) {
            Log::error('Ticket reply failed: ' . $e->getMessage());
            return back()->withErrors(['error' => 'Failed to send reply.']);
        }
    }

    public function ticketsClose(Ticket $ticket)
    {
        try {
            $ticket->update(['status' => 'closed']);
            Log::info('Ticket closed', ['ticket_id' => $ticket->id, 'admin_id' => Auth::id()]);
            return back()->with('success', 'Ticket closed.');
        } catch (\Exception $e) {
            Log::error('Ticket close failed: ' . $e->getMessage());
            return back()->withErrors(['error' => 'Failed to close ticket.']);
        }
    }

    public function activityLogs(Request $request)
    {
        $logs = ActivityLog::with('user')
            ->when($request->user_id, fn($q, $id) => $q->where('user_id', $id))
            ->when($request->action, fn($q, $a) => $q->where('action', $a))
            ->latest()
            ->paginate(50);
        return view('admin.logs.activity', compact('logs'));
    }

    public function paymentLogs(Request $request)
    {
        $logs = DB::table('payment_logs')
            ->when($request->user_id, fn($q, $id) => $q->where('user_id', $id))
            ->when($request->status, fn($q, $s) => $q->where('status', $s))
            ->latest()
            ->paginate(50);
        return view('admin.logs.payments', compact('logs'));
    }

    public function providerLogs(Request $request)
    {
        $logs = DB::table('provider_logs')
            ->when($request->provider_id, fn($q, $id) => $q->where('api_provider_id', $id))
            ->when($request->status, fn($q, $s) => $q->where('status', $s))
            ->latest()
            ->paginate(50);
        return view('admin.logs.providers', compact('logs'));
    }
}
