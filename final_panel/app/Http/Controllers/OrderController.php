<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Service;
use App\Models\Category;
use App\Services\ProviderApiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrderController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    public function index()
    {
        $orders = Order::with('service')
            ->where('user_id', Auth::id())
            ->latest()
            ->paginate(20);

        return view('orders.index', compact('orders'));
    }

    public function create()
    {
        $services = Service::with('category')
            ->where('status', 'active')
            ->orderBy('category_id')
            ->get();

        $categories = Category::where('status', 'active')->get();

        return view('orders.create', compact('services', 'categories'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'service_id' => 'required|exists:services,id',
            'link' => 'required|url|max:500',
            'quantity' => 'required|integer|min:1|max:10000000',
        ]);

        $service = Service::findOrFail($validated['service_id']);

        if ($validated['quantity'] < $service->min || $validated['quantity'] > $service->max) {
            return back()->withErrors(['quantity' => "Quantity must be between {$service->min} and {$service->max}."]);
        }

        $total = round(($validated['quantity'] / 1000) * $service->rate, 6);

        try {
            $order = DB::transaction(function () use ($validated, $service, $total) {
                $user = Auth::user()->lockForUpdate();

                if ($user->funds < $total) {
                    throw new \Exception('Insufficient balance. Please add funds first.');
                }

                $user->decrement('funds', $total);

                $order = Order::create([
                    'user_id' => $user->id,
                    'service_id' => $service->id,
                    'link' => $validated['link'],
                    'quantity' => $validated['quantity'],
                    'total' => $total,
                    'status' => 'pending',
                    'remains' => $validated['quantity'],
                ]);

                // Send to API provider
                if ($service->api_provider_id && $service->api_service_id) {
                    try {
                        $api = new ProviderApiService($service->apiProvider);
                        $result = $api->addOrder(
                            $service->api_service_id,
                            $validated['link'],
                            $validated['quantity']
                        );

                        if (!empty($result['order'])) {
                            $order->update([
                                'api_order_id' => $result['order'],
                                'status' => 'in progress'
                            ]);
                        }
                    } catch (\Throwable $e) {
                        Log::error('API order failed for service ' . $service->id . ': ' . $e->getMessage());
                    }
                }

                return $order;
            });

            return redirect()->route('orders.show', $order->id)
                ->with('success', "Order #{$order->id} placed successfully!");
        } catch (\Exception $e) {
            Log::error('Order creation failed: ' . $e->getMessage());
            return back()->withErrors(['error' => $e->getMessage()]);
        }
    }

    public function show(Order $order)
    {
        abort_unless($order->user_id === Auth::id(), 403);
        $order->load('service');

        return view('orders.show', compact('order'));
    }
}
