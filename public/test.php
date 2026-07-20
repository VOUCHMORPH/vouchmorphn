<?php
// test.php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/Core/Config/LoadCountry.php';
require_once __DIR__ . '/../src/Core/Database/DBConnection.php';
require_once __DIR__ . '/../src/Infrastructure/SMS/SmsNotificationService.php';

use Core\Config\LoadCountry;
use Core\Database\DBConnection;
use Infrastructure\SMS\SmsNotificationService;

$config = LoadCountry::getConfig();
$commConfig = $config['communication'] ?? [];

echo "Communication config found: " . (empty($commConfig) ? "NO\n" : "YES\n");
echo json_encode($commConfig, JSON_PRETTY_PRINT) . "\n\n";

if (empty($commConfig)) {
    exit("Stopping — no communication config found.\n");
}

$db = DBConnection::getConnection();
$sms = new SmsNotificationService($db, $commConfig);

echo "isConfigured(): " . ($sms->isConfigured() ? "YES\n" : "NO\n");

$testPhone = '+26770000000'; // starts with 70 → should route to Cazacom
try {
    $result = $sms->sendCashoutCode($testPhone, '123456', 100.00, 'TEST_REF_' . time());
    echo "RESULT: " . json_encode($result, JSON_PRETTY_PRINT) . "\n";
} catch (\Exception $e) {
    echo "EXCEPTION: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}
