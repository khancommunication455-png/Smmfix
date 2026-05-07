<?php
namespace App\Services;

use App\Models\ApiProvider;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\{Cache, Http, Log};

// ── ProviderApiService ─────────────────────────────────────────────────────
class ProviderApiService
{
    protected ApiProvider $provider;
    protected Client $client;

    public function __construct(ApiProvider $provider)
    {
        $this->provider = $provider;
        $this->client   = new Client(['timeout' => 15, 'verify' => false]);
    }

    public function getServices(): array  { return $this->call(['action'=>'services']); }
    public function getBalance(): float   { return (float)($this->call(['action'=>'balance'])['balance'] ?? 0); }

    public function addOrder(int $serviceId, string $link, int $qty): array {
        return $this->call(['action'=>'add','service'=>$serviceId,'link'=>$link,'quantity'=>$qty]);
    }

    public function getStatus(int $orderId): array {
        return $this->call(['action'=>'status','order'=>$orderId]);
    }

    public function getStatusBulk(array $ids): array {
        return $this->call(['action'=>'status','orders'=>implode(',',$ids)]);
    }

    public function requestRefill(int $orderId): array {
        return $this->call(['action'=>'refill','order'=>$orderId]);
    }

    private function call(array $params): array
    {
        try {
            $res  = $this->client->post($this->provider->url, [
                'form_params' => array_merge(['key' => $this->provider->api_key], $params)
            ]);
            $body = json_decode($res->getBody(), true);
            if (json_last_error() !== JSON_ERROR_NONE) throw new \RuntimeException('Invalid JSON');
            if (!empty($body['error'])) throw new \RuntimeException($body['error']);
            return $body ?? [];
        } catch (\Throwable $e) {
            Log::error("ProviderApiService [{$this->provider->name}]: " . $e->getMessage());
            throw $e;
        }
    }
}

// ── ExchangeRateService ────────────────────────────────────────────────────
class ExchangeRateService
{
    public static function getUsdToPkr(): float
    {
        return Cache::remember('usd_pkr_rate', 86400, function () {
            foreach ([
                'https://open.er-api.com/v6/latest/USD',
                'https://api.exchangerate-api.com/v4/latest/USD',
            ] as $url) {
                try {
                    $r = Http::timeout(5)->get($url);
                    if ($r->successful() && isset($r->json()['rates']['PKR'])) {
                        return (float) $r->json()['rates']['PKR'];
                    }
                } catch (\Throwable $e) {
                    Log::warning("ExchangeRate $url failed: " . $e->getMessage());
                }
            }
            return 280.0;
        });
    }

    public static function refresh(): float
    {
        Cache::forget('usd_pkr_rate');
        return self::getUsdToPkr();
    }
}
