<?php
// filepath: d:\Learing\ITE YEAR3\re-exam\demo-exam\app\Services\CurrencyService.php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class CurrencyService
{
    protected $defaultRates = [
        'KHR' => 4100, // 1 USD = 4100 KHR
    ];

    // Cache exchange rates for 24 hours in production
    public function getExchangeRate($currency)
    {
        if ($currency === 'USD') {
            return 1;
        }

        // In production, you might fetch this from an API
        return $this->defaultRates[$currency] ?? null;
    }
}
