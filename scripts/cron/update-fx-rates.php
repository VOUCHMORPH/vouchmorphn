#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

use Domain\Services\ForexService;

// Rate providers - layered priority
$rateProviders = [
    'openexchangerates' => [
        'enabled' => false,  // Set to true when you have API key
        'api_key' => getenv('OPENEXCHANGE_API_KEY'),
        'url' => 'https://openexchangerates.org/api/latest.json'
    ],
    'exchangerate_api' => [
        'enabled' => false,
        'api_key' => getenv('EXCHANGERATE_API_KEY'),
        'url' => 'https://api.exchangerate-api.com/v4/latest/'
    ],
    'manual' => [
        'enabled' => true,
        'rates' => [
            'USD_BWP' => 13.50, 'BWP_USD' => 0.074,
            'USD_ZAR' => 18.20, 'ZAR_USD' => 0.055,
            'USD_NGN' => 750.00, 'NGN_USD' => 0.00133,
            'BWP_ZAR' => 1.35, 'ZAR_BWP' => 0.74,
            'EUR_BWP' => 14.80, 'BWP_EUR' => 0.0676,
            'GBP_BWP' => 17.20, 'BWP_GBP' => 0.0581
        ]
    ]
];

// Corridors to update
$corridors = [
    ['from' => 'USD', 'to' => 'BWP', 'markup' => 0.02],
    ['from' => 'USD', 'to' => 'ZAR', 'markup' => 0.015],
    ['from' => 'USD', 'to' => 'NGN', 'markup' => 0.035],
    ['from' => 'BWP', 'to' => 'ZAR', 'markup' => 0.01],
    ['from' => 'BWP', 'to' => 'NGN', 'markup' => 0.04],
    ['from' => 'EUR', 'to' => 'BWP', 'markup' => 0.02],
    ['from' => 'GBP', 'to' => 'BWP', 'markup' => 0.02],
    ['from' => 'ZAR', 'to' => 'BWP', 'markup' => 0.01],
    ['from' => 'NGN', 'to' => 'BWP', 'markup' => 0.04]
];

$db = getDatabaseConnection();
$forexService = new ForexService($db, [], []);

$rates = [];

foreach ($corridors as $corridor) {
    $marketRate = null;
    $providerRate = null;
    
    // Try API providers first
    foreach ($rateProviders as $providerName => $provider) {
        if (!$provider['enabled']) {
            continue;
        }
        
        if ($providerName === 'openexchangerates' && $provider['api_key']) {
            $rate = fetchOpenExchangeRates($provider['api_key'], $corridor['from'], $corridor['to']);
            if ($rate) {
                $marketRate = $rate;
                $providerRate = $rate;
                break;
            }
        }
        
        if ($providerName === 'exchangerate_api' && $provider['api_key']) {
            $rate = fetchExchangeRateApi($provider['api_key'], $corridor['from'], $corridor['to']);
            if ($rate) {
                $marketRate = $rate;
                $providerRate = $rate;
                break;
            }
        }
    }
    
    // Fallback to manual rates
    if ($marketRate === null && isset($rateProviders['manual']['rates'][$corridor['from'] . '_' . $corridor['to']])) {
        $providerRate = $rateProviders['manual']['rates'][$corridor['from'] . '_' . $corridor['to']];
        $marketRate = $providerRate;
    }
    
    if ($marketRate !== null) {
        $markupPercent = $corridor['markup'];
        $finalRate = $providerRate * (1 - $markupPercent);
        
        $rates[] = [
            'from' => $corridor['from'],
            'to' => $corridor['to'],
            'market_rate' => $marketRate,
            'provider_rate' => $providerRate,
            'markup_percent' => $markupPercent,
            'final_rate' => $finalRate,
            'expires_at' => date('Y-m-d H:i:s', strtotime('+1 hour'))
        ];
        
        echo "Updated {$corridor['from']} → {$corridor['to']}: rate={$finalRate}\n";
    }
}

if (!empty($rates)) {
    $forexService->updateCachedRates($rates);
    echo "Updated " . count($rates) . " FX rates\n";
}

function fetchOpenExchangeRates(string $apiKey, string $from, string $to): ?float
{
    $url = "https://openexchangerates.org/api/latest.json?app_id={$apiKey}&base={$from}";
    $response = @file_get_contents($url);
    
    if ($response === false) {
        return null;
    }
    
    $data = json_decode($response, true);
    
    if (isset($data['rates'][$to])) {
        return (float)$data['rates'][$to];
    }
    
    return null;
}

function fetchExchangeRateApi(string $apiKey, string $from, string $to): ?float
{
    $url = "https://api.exchangerate-api.com/v4/latest/{$from}";
    $response = @file_get_contents($url);
    
    if ($response === false) {
        return null;
    }
    
    $data = json_decode($response, true);
    
    if (isset($data['rates'][$to])) {
        return (float)$data['rates'][$to];
    }
    
    return null;
}

function getDatabaseConnection(): PDO
{
    $host = getenv('DB_HOST') ?: 'localhost';
    $port = getenv('DB_PORT') ?: '5432';
    $dbname = getenv('DB_NAME') ?: 'vouchmorph';
    $user = getenv('DB_USER') ?: 'vouchmorph';
    $password = getenv('DB_PASSWORD') ?: '';
    
    return new PDO(
        "pgsql:host={$host};port={$port};dbname={$dbname}",
        $user,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
}
