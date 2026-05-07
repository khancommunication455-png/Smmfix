<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ExchangeRateService
{
    const CACHE_KEY = 'usd_pkr_exchange_rate';
    const CACHE_DURATION = 86400; // 24 hours
    const DEFAULT_RATE = 280.0;

    public static function getUsdToPkr(): float
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_DURATION, function () {
            $urls = [
                'https://open.er-api.com/v6/latest/USD',
                'https://api.exchangerate-api.com/v4/latest/USD',
                'https://api.exchangerate.host/latest?base=USD&symbols=PKR',
            ];

            foreach ($urls as $url) {
                try {
                    $response = Http::timeout(5)
                        ->retry(2, 100)
                        ->get($url);

                    if ($response->successful()) {
                        $data = $response->json();

                        // Handle different API response formats
                        if (isset($data['rates']['PKR'])) {
                            return (float) $data['rates']['PKR'];
                        } elseif (isset($data['conversion_rates']['PKR'])) {
                            return (float) $data['conversion_rates']['PKR'];
                        }
                    }
                } catch (\Throwable $e) {
                    Log::warning("Exchange rate API failed: {$url}", [
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            Log::warning('All exchange rate APIs failed, using default rate', [
                'default_rate' => self::DEFAULT_RATE,
            ]);

            return self::DEFAULT_RATE;
        });
    }

    public static function refresh(): float
    {
        Cache::forget(self::CACHE_KEY);
        return self::getUsdToPkr();
    }

    public static function convertUsdToPkr(float $usd): float
    {
        return round($usd * self::getUsdToPkr(), 2);
    }

    public static function convertPkrToUsd(float $pkr): float
    {
        $rate = self::getUsdToPkr();
        return $rate > 0 ? round($pkr / $rate, 6) : 0;
    }
}
