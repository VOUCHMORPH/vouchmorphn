<?php
declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/Application/Controllers/USSDController.php';

use Application\Controllers\USSDController;

try {
    $config = require __DIR__ . '/../../../src/Core/Config/config_loader.php';
    $ussdController = new USSDController($config, $db);

    $request = array_merge($_GET, $_POST);

    $input = file_get_contents('php://input');
    if (!empty($input) && str_starts_with(trim($input), '{')) {
        $jsonData = json_decode($input, true);
        if (is_array($jsonData)) {
            $request = array_merge($request, $jsonData);
        }
    }

    if (
        empty($request['sessionId']) &&
        empty($request['SESSION_ID']) &&
        empty($request['session_id'])
    ) {
        header('Content-Type: text/plain');
        echo "END Invalid USSD request.";
        exit;
    }

    $response = $ussdController->handleUSSDRequest($request);

    header('Content-Type: text/plain; charset=UTF-8');
    echo trim($response);

} catch (Throwable $e) {
    error_log("USSD ERROR: " . $e->getMessage());
    header('Content-Type: text/plain; charset=UTF-8');
    echo "END System error. Please try again later.";
}
