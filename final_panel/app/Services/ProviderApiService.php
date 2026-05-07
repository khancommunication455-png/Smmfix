<?php

namespace App\Services;

use App\Models\ApiProvider;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

class ProviderApiService
{
    protected ApiProvider $provider;
    protected Client $client;

    public function __construct(ApiProvider $provider)
    {
        $this->provider = $provider;
        $this->client = new Client([
            'timeout' => 15,
            'verify' => false,
            'http_errors' => false,
        ]);
    }

    public function getServices(): array
    {
        return $this->call(['action' => 'services']);
    }

    public function getBalance(): float
    {
        $response = $this->call(['action' => 'balance']);
        return (float) ($response['balance'] ?? 0);
    }

    public function addOrder(int $serviceId, string $link, int $qty): array
    {
        return $this->call([
            'action' => 'add',
            'service' => $serviceId,
            'link' => $link,
            'quantity' => $qty,
        ]);
    }

    public function getStatus(int $orderId): array
    {
        return $this->call([
            'action' => 'status',
            'order' => $orderId,
        ]);
    }

    public function getStatusBulk(array $ids): array
    {
        return $this->call([
            'action' => 'status',
            'orders' => implode(',', $ids),
        ]);
    }

    public function requestRefill(int $orderId): array
    {
        return $this->call([
            'action' => 'refill',
            'order' => $orderId,
        ]);
    }

    private function call(array $params): array
    {
        try {
            $response = $this->client->post($this->provider->url, [
                'form_params' => array_merge(
                    ['key' => $this->provider->api_key],
                    $params
                ),
            ]);

            $statusCode = $response->getStatusCode();

            if ($statusCode !== 200) {
                Log::warning("Provider API responded with status {$statusCode}", [
                    'provider' => $this->provider->name,
                    'params' => $params,
                ]);
            }

            $body = json_decode($response->getBody(), true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \RuntimeException('Invalid JSON response from provider');
            }

            if (!empty($body['error'])) {
                throw new \RuntimeException($body['error']);
            }

            return $body ?? [];
        } catch (\Throwable $e) {
            Log::error("ProviderApiService [{$this->provider->name}]: " . $e->getMessage(), [
                'context' => 'api_call',
                'provider_id' => $this->provider->id,
            ]);
            throw $e;
        }
    }
}
