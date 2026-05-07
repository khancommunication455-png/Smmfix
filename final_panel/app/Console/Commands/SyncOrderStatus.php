<?php
namespace App\Console\Commands;

use App\Models\{Order, ApiProvider};
use App\Services\ProviderApiService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncOrderStatus extends Command
{
    protected $signature   = 'orders:sync';
    protected $description = 'Auto-sync pending orders from API providers every 5 minutes';

    public function handle(): int
    {
        $pending = Order::whereIn('status', ['pending','in progress'])
            ->whereNotNull('api_order_id')
            ->with('service.apiProvider')
            ->get();

        if ($pending->isEmpty()) {
            $this->info('No pending orders to sync.');
            return 0;
        }

        $this->info("Syncing {$pending->count()} orders...");

        $byProvider = $pending->groupBy(fn($o) => optional($o->service?->apiProvider)->id);

        foreach ($byProvider as $providerId => $orders) {
            $provider = $orders->first()->service?->apiProvider;
            if (!$provider) continue;

            foreach ($orders->chunk(100) as $chunk) {
                $ids = $chunk->pluck('api_order_id')->toArray();
                try {
                    $api      = new ProviderApiService($provider);
                    $response = $api->getStatusBulk($ids);
                    if (!is_array($response)) continue;

                    foreach ($chunk as $order) {
                        $data = $response[$order->api_order_id] ?? null;
                        if (!$data) continue;

                        $newStatus = $this->mapStatus($data['status'] ?? '');
                        $order->update([
                            'status'  => $newStatus,
                            'remains' => $data['remains'] ?? $order->remains,
                        ]);
                        $this->line("Order #{$order->id} → {$newStatus}");
                    }
                } catch (\Throwable $e) {
                    Log::error("SyncOrderStatus provider {$providerId}: " . $e->getMessage());
                    $this->error("Provider {$providerId}: " . $e->getMessage());
                }
            }
        }

        $this->info('Sync complete.');
        return 0;
    }

    private function mapStatus(string $raw): string
    {
        return match(strtolower(trim($raw))) {
            'completed'            => 'completed',
            'partial'              => 'partial',
            'cancelled','canceled' => 'cancelled',
            'processing'           => 'in progress',
            'in progress'          => 'in progress',
            'error','fail','failed'=> 'error',
            default                => 'pending',
        };
    }
}
