<?php
declare(strict_types=1);

header('Content-Type: application/json');

error_reporting(E_ALL);
ini_set('display_errors', '1');

$results = [];

try {
    $results['step'] = '1 - reading input';

    $input = file_get_contents("php://input");
    $results['raw_input'] = $input;

    $data = json_decode($input, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("JSON ERROR: " . json_last_error_msg());
    }

    $results['decoded'] = $data;

    // 2. Validate structure
    $results['step'] = '2 - structure validation';

    if (!isset($data['sources'])) {
        throw new Exception("Missing sources array");
    }

    if (!is_array($data['sources'])) {
        throw new Exception("Sources is not an array");
    }

    if (!isset($data['destination'])) {
        throw new Exception("Missing destination object");
    }

    if (!isset($data['destination']['target_amount'])) {
        throw new Exception("Missing destination.target_amount");
    }

    $results['step'] = '3 - dependency check';

    // 3. Check critical classes
    $classes = [
        'Domain\Services\SwapService',
        'Domain\Services\ContributionCalculator',
        'Domain\Services\MultiSourceFeeCalculator',
        'Infrastructure\Banks\GenericBankClient'
    ];

    foreach ($classes as $class) {
        $results['class_check'][$class] = class_exists($class);
    }

    $results['step'] = '4 - environment check';

    // 4. Check env vars (common hidden failure)
    $envVars = [
        'DATABASE_URL',
        'APP_ENV',
        'LOG_LEVEL',
        'SWAP_HMAC_SECRET'
    ];

    foreach ($envVars as $var) {
        $results['env'][$var] = getenv($var) !== false;
    }

    $results['step'] = '5 - SwapService test init';

    // 5. Try minimal instantiation (CRITICAL TEST)
    $pdo = null;
    try {
        $pdo = new PDO(getenv('DATABASE_URL'));
        $results['pdo'] = 'OK';
    } catch (Throwable $e) {
        $results['pdo_error'] = $e->getMessage();
    }

    $results['step'] = '6 - completed';

    echo json_encode([
        'status' => 'ok',
        'results' => $results
    ], JSON_PRETTY_PRINT);

} catch (Throwable $e) {

    http_response_code(500);

    echo json_encode([
        'status' => 'failed',
        'error' => $e->getMessage(),
        'step' => $results['step'] ?? 'unknown',
        'trace' => $e->getTraceAsString()
    ], JSON_PRETTY_PRINT);
}
