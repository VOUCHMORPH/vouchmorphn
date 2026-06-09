<?php
declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Domain\Services\SwapService;
use Domain\Services\ForexService;
use Domain\Services\Settlement\HybridSettlementStrategy;
use Infrastructure\MessageAdapters\MessageAdapterFactory;
use Infrastructure\SMS\SmsNotificationService;
use PDO;

echo "<pre>";

/**
 * DATABASE CONNECTION (REAL FIXED VERSION)
 */
function db(): PDO {
    $url = getenv('DATABASE_URL');
    if (!$url) {
        throw new RuntimeException("DATABASE_URL not set");
    }

    $p = parse_url($url);

    $dsn = "pgsql:host={$p['host']};port=" . ($p['port'] ?? 5432) .
           ";dbname=" . ltrim($p['path'], '/');

    return new PDO($dsn, $p['user'], $p['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
}

$db = db();

echo "✅ DATABASE CONNECTED\n\n";

/**
 * REAL SERVICES (MATCH YOUR CODEBASE)
 */
$forex = new ForexService($db);
$adapterFactory = new MessageAdapterFactory();
$sms = new SmsNotificationService();
$settlement = new HybridSettlementStrategy($db, $forex);

/**
 * THIS IS YOUR REAL SWAP ENGINE
 */
$swapService = new SwapService(
    $db,
    $forex,
    $adapterFactory,
    $settlement,
    $sms
);

/**
 * REAL INPUT (YOUR VOUCHER)
 */
$request = [
    'voucher_number' => '710083197',
    'pin' => '657250',
    'amount' => 200,
    'from' => 'ZURABANK',
    'to' => 'SACCUSALIS_ATM',
    'from_account' => '10000001',
    'cashout' => true
];

echo "🔥 EXECUTING REAL SWAP FLOW...\n\n";

try {
    $result = $swapService->execute($request);

    echo "📊 FINAL RESULT:\n";
    print_r($result);

} catch (Throwable $e) {
    echo "❌ SWAP FAILED:\n";
    echo $e->getMessage();
}

echo "</pre>";
