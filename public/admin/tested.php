<?php
// test_env_only.php
// Run: php test_env_only.php

echo "═══════════════════════════════════════════════════════════════════\n";
echo "ENVIRONMENT VARIABLES TEST (Railway Vault)\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

// List of variables to check
$variables = [
    'ZURUBANK_BASE_URL',
    'ZURUBANK_VERIFY_ENDPOINT',
    'ZURUBANK_HOLD_ENDPOINT',
    'ZURUBANK_DEBIT_ENDPOINT',
    'ZURUBANK_API_KEY',
    'SACCUSSALIS_BASE_URL',
    'SACCUSSALIS_GENERATE_TOKEN_ENDPOINT',
    'SACCUSSALIS_API_KEY',
    'CAZACOM_BASE_URL',
    'CAZACOM_VERIFY_ENDPOINT',
    'CAZACOM_HOLD_ENDPOINT',
    'CAZACOM_API_KEY',
];

echo "Checking environment variables:\n\n";

$found = 0;
$missing = 0;

foreach ($variables as $var) {
    $value = getenv($var);
    if ($value && !empty($value)) {
        echo "✅ {$var} = {$value}\n";
        $found++;
    } else {
        echo "❌ {$var} = NOT SET\n";
        $missing++;
    }
}

echo "\n───────────────────────────────────────────────────────────────────\n";
echo "Summary:\n";
echo "   Found: {$found}\n";
echo "   Missing: {$missing}\n";

if ($missing > 0) {
    echo "\n⚠️ Missing environment variables need to be added to Railway vault.\n";
} else {
    echo "\n✅ All environment variables are set!\n";
}

echo "\n═══════════════════════════════════════════════════════════════════\n";
