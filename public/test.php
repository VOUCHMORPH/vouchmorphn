<?php
// test_sms.php — run directly on the VouchMorph server/container
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/Core/Config/LoadCountry.php';
require_once __DIR__ . '/../src/Infrastructure/SMS/SmsNotificationService.php';

use Core\Config\LoadCountry;
use Infrastructure\SMS\SmsNotificationService;

$config = LoadCountry::getConfig();
$smsConfig = $config['participants']['sms'] ?? [];

echo "SMS config found: " . (empty($smsConfig) ? "NO — smsService would be null in SwapService\n" : "YES\n");
echo json_encode($smsConfig, JSON_PRETTY_PRINT) . "\n\n";

if (empty($smsConfig)) {
    exit("Stopping — no SMS config, this confirms the 'skipped_no_provider' case.\n");
}

$sms = new SmsNotificationService($smsConfig);

$testPhone = '+26770000001'; // use a real number you can check
try {
    $result = $sms->sendCashoutCode($testPhone, '123456', 100.00, 'TEST_REF_' . time());
    echo "SUCCESS: " . json_encode($result) . "\n";
} catch (\Exception $e) {
    echo "FAILED: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
}
