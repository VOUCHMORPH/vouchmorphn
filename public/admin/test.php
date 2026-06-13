<?php
// Save as: test_fixed_cert.php

// The certificate from your logs (with fixes applied)
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

echo "Testing cleaned certificate...\n";

// Try to load the certificate
$cert_data = openssl_x509_read($certificate);
if ($cert_data) {
    echo "✓ Certificate loaded successfully!\n";
    
    // Get certificate details
    $cert_info = openssl_x509_parse($cert_data);
    echo "Certificate Subject: " . ($cert_info['name'] ?? 'Unknown') . "\n";
    echo "Valid from: " . date('Y-m-d', $cert_info['validFrom_time_t']) . "\n";
    echo "Valid to: " . date('Y-m-d', $cert_info['validTo_time_t']) . "\n";
    
    // Get public key
    $pub_key = openssl_pkey_get_public($cert_data);
    if ($pub_key) {
        echo "✓ Public key extracted successfully\n";
    }
} else {
    echo "✗ Failed to load certificate\n";
    echo "OpenSSL error: " . openssl_error_string() . "\n";
}

// Test with signature verification
$payload = '{"available_balance":903850,"held_balance":95100,"hold_placed":true,"hold_reference":"SWAP_1781311956141","message":"Hold placed successfully","new_balance":903850,"session_id":"SWAP_1781311956141","signature_verified":true,"status":"SUCCESS","timestamp":1781311957}';

$signature_base64 = "g6TjLeAz8mUly8auj66pKDFtb0BXxfsVpegh6jSGDaUC18yiHRrPQ\/O4\/OIJKf+JPRnxUfZva8NesDdMmv1nts8doMUfd2ydmVxLobzCn2L6X1OQNWXmofVK7Ch7Rn+e7JX+bndQcgPnRTPPxO2i9XELtXym2w\/fbBd8Mv0AiS6S\/MJbd9qrmCzvnoB8ysFfsgB8WUSAo9QPm83CJ5IbMPO";
$signature = base64_decode($signature_base64);

$result = openssl_verify($payload, $signature, $pub_key, OPENSSL_ALGO_SHA256);
if ($result === 1) {
    echo "\n✓✓✓ SIGNATURE VALID! ✓✓✓\n";
} else {
    echo "\nSignature still invalid. Result: $result\n";
}
?>
