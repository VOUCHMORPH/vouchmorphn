<?php
declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Domain\Services\SwapService;
use Domain\Services\Forex\ForexEngine;
use Domain\Services\Settlement\HybridSettlementStrategy;
use Infrastructure\Adapters\AdapterRegistry;
use Infrastructure\SMS\SmsNotificationService;
use Security\Pin\PinValidator;
use PDO;

echo "<pre>";

/**
 * 1. CONNECT DB (REAL)
 */
function db(): PDO {
    $url = getenv('DATABASE_URL');

    if (!$url) {
        throw new RuntimeException("DATABASE_URL not set");
    }

    $p = parse_url($url);

    $dsn = "pgsql:host={$p['host']};port={$p['port']};dbname=" . ltrim($p['path'], '/');

    return new PDO($dsn, $p['user'], $p['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
}

$db = db();

echo "✅ DATABASE CONNECTED\n\n";

/**
 * 2. BUILD REAL SERVICES (NO MOCKS)
 */
$forex = new ForexEngine($db);
$adapter = new AdapterRegistry();
$sms = new SmsNotificationService();
$security = new PinValidator();
$settlement = new HybridSettlementStrategy($db, $forex);
$swapService = new SwapService(
    $db,
    $forex,
    $adapter,
    $settlement,
    $sms,
    $security
);

/**
 * 3. REAL SWAP INPUT (YOUR DATA)
 */
$request = [
    'voucher_number' => '710083197',
    'pin' => '657250',
    'amount' => 200,
    'from' => 'ZURABANK',
    'to' => 'SACCUSALIS_ATM',
    'from_account' => 'ZURA-DEFAULT',
    'cashout' => true
];

echo "🔥 EXECUTING REAL SWAP...\n\n";

/**
 * 4. EXECUTE REAL SWAP
 */
$result = $swapService->execute($request);

/**
 * 5. PRINT REAL RESULT (NOT SIMULATION)
 */
echo "📊 RESULT:\n";
print_r($result);

echo "\n✅ DONE\n";
echo "</pre>";
