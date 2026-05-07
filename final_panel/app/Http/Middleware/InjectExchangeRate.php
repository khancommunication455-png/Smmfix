<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Services\ExchangeRateService;

class InjectExchangeRate
{
    public function handle(Request $request, Closure $next)
    {
        if (!session()->has('usd_pkr_rate') ||
            !session()->has('pkr_refreshed_at') ||
            now()->diffInHours(session('pkr_refreshed_at')) >= 6) {
            session([
                'usd_pkr_rate'     => ExchangeRateService::getUsdToPkr(),
                'pkr_refreshed_at' => now(),
            ]);
        }
        return $next($request);
    }
}
