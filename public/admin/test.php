<?php
// Save as: find_payload_format.php

$signature_base64 = "g6TjLeAz8mUly8auj66pKDFtb0BXxfsVpegh6jSGDaUC18yiHRrPQ\/O4\/OIJKf+JPRnxUfZva8NesDdMmv1nts8doMUfd2ydmVxLobzCn2L6X1OQNWXmofVK7Ch7Rn+e7JX+bndQcgPnRTPPxO2i9XELtXym2w\/fbBd8Mv0AiS6S\/MJbd9qrmCzvnoB8ysFfsgB8WUSAo9QPm83CJ5IbMPO";
$signature = base64_decode($signature_base64);

$certificate = <<<CERT
-----BEGIN CERTIFICATE-----
MIIEbTCCAlUCFGM7U2vcVe90JNEe6/Mxhts3A+vhMA0GCSqGSIb3DQEBCwUAMHcx
CzAJBgNVBAYTAkJXMREwDwYDVQQIDAhHYWJvcm9uZTERMA8GA1UEBwwIR2Fib3Jv
bmUxJTAjBgNVBAoMHFZvdWNoTW9ycGggRmluYW5jaWFsIE5ldHdvcmsxGzAZBgNV
BAMMElZvdWNoTW9ycGggUm9vdCBDQTAeFw0yNjA2MTIyMTM0MDRaFw0zMTA2MTEy
MTM0MDRaMG8xCzAJBgNVBAYTAkJXMREwDwYDVQQIDAhHYWJvcm9uZTERMA8GA1UE
BwwIR2Fib3JvbmUxJTAjBgNVBAoMHFZvdWNoTW9ycGggRmluYW5jaWFsIE5ldHdv
cmsxEzARBgNVBAMMClZPVUNITU9SUEgwggEiMA0GCSqGSIb3DQEBAQUAA4IBDwAw
ggEKAoIBAQDDqszylCQMnxUJVr1Io6TUlDa4qVnqMwz9ClisTWE1COSJOCmFEjoL
ZTh4146xiJD0Ga2mu7PTc6hwHV23UNmZE+UTeLn0BM10nB6BulU3maBKI8lXOjK7
nI/p/jzHYrVvFJQdQTo5fmxgsNPLJX1QozyonV3dY6TCGFdtjXqUEvB4HynHk/kR
NDYbJw8k3GtKMkFL2xliffcenayC7n+WdYEVmidwsUxXvp8bRWwfrgoB3phxEoBp
92rKb84uY1JmlXz89E4Swba1FfLQg00PTy9tUcIJujPe6AuGujQKNKkkIzLhH/H6
krdaNj29AJyLhsHmtY73mqZacMQvWf8DAgMBAAEwDQYJKoZIhvcNAQELBQADggIB
AGLi5JD/Uf7l4kuH2Yzgd2vd8QkD/YKzD3TW/cU2cP/K5ujcPd/m9gtNLDx7DbBl
ug76f7OXrqU7Z2PAUNa+bxk8hlC+MpPoSZNZxv6iZ68UZ01KOzVKHHLX5O7m3IUs
NZPjQ216gnSFsS0FRbBAd1QK0IazOXBVdpsgwQr4YLuYeuc861POEo87/hO8A66A
grWPOuS8H4MPqxbQgQ4Q5eKCBfXTFrG5JECyqOjapO9x4MVKLvC4IwkQYBmlO3jU
c7rCTQJiuRzDjCm9P62L1mWnX6PQPttlYunBOJX7Un4Bwi0GbkGqSFJ4IgrPWjov
MGktLr8AfzX4zN74gPvnr0HpeUNnHjEohtqptcd1+NVWGNqRyXiyGYsQzxyuPJfG
eTaas8siIq0dGJavRYq/lC5Jga3RB9h//zUbtvEOK2RW7z1Tq/YWu+qWYchkSs1c
RcLQCR8hD+MFHwaiI5G7blk9TSxtflAnuXYQqrEHcQiR4CKY2AaVBsc0gaX1OSYt
9/nraZvFmf0YwR1opW3p/YrfW3h4Yh7en1G/Wf/IzJw3gxVes0E1CwjKEzr9Yky5
OMrPGTpmS+xzJdUN6pF5QIoIblWeLJvprcMODu1nwagR7I/xdg4isln+TtVdRt60
QQNPdCuu3QqNCq7suNoAEd+hHQVTzYgWKEby+XRZqkFd
-----END CERTIFICATE-----
CERT;

$pub_key = openssl_pkey_get_public($certificate);

// The response from SACCUSSALIS (from your logs)
$response_data = [
    "available_balance" => 903850,
    "held_balance" => 95100,
    "hold_placed" => true,
    "hold_reference" => "SWAP_1781311956141",
    "message" => "Hold placed successfully",
    "new_balance" => 903850,
    "session_id" => "SWAP_1781311956141",
    "signature_verified" => true,
    "status" => "SUCCESS",
    "timestamp" => 1781311957
];

echo "Testing different payload formats:\n\n";

// Format 1: JSON without spaces (compact)
$payload1 = json_encode($response_data);
echo "1. Compact JSON:\n";
echo "   Payload: " . $payload1 . "\n";
$result1 = openssl_verify($payload1, $signature, $pub_key, OPENSSL_ALGO_SHA256);
echo "   Result: " . ($result1 === 1 ? "✓ VALID" : "✗ Invalid") . "\n\n";

// Format 2: JSON with spaces (pretty)
$payload2 = json_encode($response_data, JSON_PRETTY_PRINT);
echo "2. Pretty JSON:\n";
$result2 = openssl_verify($payload2, $signature, $pub_key, OPENSSL_ALGO_SHA256);
echo "   Result: " . ($result2 === 1 ? "✓ VALID" : "✗ Invalid") . "\n\n";

// Format 3: Sorted keys
ksort($response_data);
$payload3 = json_encode($response_data);
echo "3. Sorted keys, compact:\n";
echo "   Payload: " . $payload3 . "\n";
$result3 = openssl_verify($payload3, $signature, $pub_key, OPENSSL_ALGO_SHA256);
echo "   Result: " . ($result3 === 1 ? "✓ VALID" : "✗ Invalid") . "\n\n";

// Format 4: Query string format
$payload4 = http_build_query($response_data);
echo "4. Query string format:\n";
echo "   Payload: " . substr($payload4, 0, 100) . "...\n";
$result4 = openssl_verify($payload4, $signature, $pub_key, OPENSSL_ALGO_SHA256);
echo "   Result: " . ($result4 === 1 ? "✓ VALID" : "✗ Invalid") . "\n\n";

// Format 5: Include timestamp as string
$response_data['timestamp'] = "1781311957";
$payload5 = json_encode($response_data);
echo "5. Timestamp as string:\n";
$result5 = openssl_verify($payload5, $signature, $pub_key, OPENSSL_ALGO_SHA256);
echo "   Result: " . ($result5 === 1 ? "✓ VALID" : "✗ Invalid") . "\n\n";
