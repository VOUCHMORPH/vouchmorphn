<?php

require_once "test.php";

$test = new CorrectiveTest();

// ------------------------------
// 1. ZURUBANK VOUCHER VERIFY
// ------------------------------
$verify = $test->testVerify(
    "https://zurubank-production.up.railway.app/api/v1/verify_asset.php",
    [
        "asset_type" => "VOUCHER",
        "voucher_number" => "710083197",
        "voucher_pin" => "657250",
        "amount" => 100
    ]
);

// ------------------------------
// 2. HOLD
// ------------------------------
$hold = $test->testHold(
    "https://zurubank-production.up.railway.app/api/v1/hold.php",
    [
        "asset_type" => "VOUCHER",
        "voucher_number" => "710083197",
        "reference" => $verify['data']['verification_reference'] ?? "TEST-REF",
        "amount" => 100
    ]
);

// ------------------------------
// 3. REPLAY TEST
// ------------------------------
$test->testReplayProtection(
    "https://zurubank-production.up.railway.app/api/v1/hold.php",
    [
        "asset_type" => "VOUCHER",
        "voucher_number" => "710083197",
        "reference" => "REPLAY-TEST",
        "amount" => 100
    ]
);

// ------------------------------
// 4. MULTI SOURCE TEST
// ------------------------------
$test->testMultiSource([
    ["type" => "voucher", "amount" => 40],
    ["type" => "account", "amount" => 60]
], 100);

// ------------------------------
$test->report();
