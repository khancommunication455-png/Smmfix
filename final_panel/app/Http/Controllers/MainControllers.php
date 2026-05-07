<?php
namespace App\Http\Controllers;

use App\Models\{Order, Service, Category, ApiProvider};
use App\Services\{ProviderApiService, ExchangeRateService};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth, DB, Log};

// ── OrderController ────────────────────────────────────────────────────────
class OrderController extends Controller
{
    public function index()
    {
        $orders = Order::with('service')
            ->where('user_id', Auth::id())
            ->latest()->paginate(20);
        return view('orders.index', compact('orders'));
    }

    public function create()
    {
        $services   = Service::with('category')->where('status','active')->orderBy('category_id')->get();
        $categories = Category::where('status','active')->get();
        return view('orders.create', compact('services','categories'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'service_id' => 'required|exists:services,id',
            'link'       => 'required|url|max:500',
            'quantity'   => 'required|integer|min:1|max:10000000',
        ]);

        $service = Service::findOrFail($request->service_id);

        if ($request->quantity < $service->min || $request->quantity > $service->max) {
            return back()->withErrors(['quantity' => "Quantity must be between {$service->min} and {$service->max}."]);
        }

        $total = round(($request->quantity / 1000) * $service->rate, 6);

        $order = DB::transaction(function () use ($request, $service, $total) {
            $user = \App\Models\User::lockForUpdate()->find(Auth::id());
            if ($user->funds < $total) {
                throw new \Exception('Insufficient balance. Please add funds first.');
            }
            $user->decrement('funds', $total);

            $order = Order::create([
                'user_id'    => $user->id,
                'service_id' => $service->id,
                'link'       => $request->link,
                'quantity'   => $request->quantity,
                'total'      => $total,
                'status'     => 'pending',
                'remains'    => $request->quantity,
            ]);

            // Send to API provider
            if ($service->api_provider_id && $service->api_service_id) {
                try {
                    $api    = new ProviderApiService($service->apiProvider);
                    $result = $api->addOrder($service->api_service_id, $request->link, $request->quantity);
                    if (!empty($result['order'])) {
                        $order->update(['api_order_id' => $result['order'], 'status' => 'in progress']);
                    }
                } catch (\Throwable $e) {
                    Log::error('API order failed: ' . $e->getMessage());
                }
            }
            return $order;
        });

        return redirect()->route('orders.show', $order->id)
            ->with('success', "Order #{$order->id} placed successfully!");
    }

    public function show(Order $order)
    {
        abort_unless($order->user_id === Auth::id(), 403);
        $order->load('service');
        return view('orders.show', compact('order'));
    }
}

// ── ServiceController ──────────────────────────────────────────────────────
class ServiceController extends Controller
{
    public function index(Request $request)
    {
        $categories = Category::where('status','active')->get();
        $services   = Service::with('category')
            ->where('status','active')
            ->when($request->q, fn($q,$s) => $q->where('name','like',"%$s%"))
            ->when($request->cat, fn($q,$c) => $q->where('category_id',$c))
            ->paginate(50);
        return view('services.index', compact('services','categories'));
    }
}

// ── FundsController ────────────────────────────────────────────────────────
class FundsController extends Controller
{
    public function index()
    {
        return view('funds.index');
    }

    public function stripe(Request $request)
    {
        $request->validate(['amount' => 'required|numeric|min:1|max:10000']);
        // Wire your Stripe key in .env: STRIPE_KEY, STRIPE_SECRET
        // Full Stripe integration: see INTEGRATION_GUIDE.md
        return back()->with('info', 'Stripe integration — add your STRIPE_SECRET to .env to activate.');
    }

    public function paypal(Request $request)
    {
        $request->validate(['amount' => 'required|numeric|min:1|max:10000']);
        // Wire your PayPal keys in .env: PAYPAL_CLIENT_ID, PAYPAL_SECRET
        return back()->with('info', 'PayPal integration — add your PAYPAL keys to .env to activate.');
    }

    public function manual(Request $request)
    {
        $request->validate([
            'method' => 'required|in:easypaisa,jazzcash,crypto,pm',
            'amount' => 'required|numeric|min:1',
            'reference' => 'required|string|max:100',
        ]);
        // Create pending transaction for admin to approve
        \App\Models\Transaction::create([
            'user_id'     => Auth::id(),
            'amount'      => $request->amount,
            'type'        => 'deposit',
            'status'      => 'pending',
            'description' => strtoupper($request->method) . ' deposit',
            'reference'   => $request->reference,
        ]);
        return back()->with('success', 'Payment submitted! Admin will verify and credit your account within 1-2 hours.');
    }
}
